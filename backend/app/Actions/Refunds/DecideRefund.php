<?php

namespace App\Actions\Refunds;

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Enums\TicketStatus;
use App\Mail\RefundDecided;
use App\Models\Commission;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Commission\CommissionCalculator;
use App\Services\Payments\PaymentManager;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Decision de l'organisateur sur une demande de remboursement (section 24.1).
 *
 * C'est ici que l'argent repart vers l'acheteur. Trois garanties :
 *
 * 1. TOUT OU RIEN. Statut de la demande, statut des billets, statut de la
 *    commande et ajustement de commission sont ecrits dans une seule
 *    transaction. Un billet rendu inutilisable sans remboursement enregistre,
 *    ou l'inverse, serait impossible a rattraper proprement.
 *
 * 2. LA COMMISSION EST AJUSTEE PAR UNE LIGNE NOUVELLE, jamais par une
 *    modification. La table est en ajout seul : une correction s'ajoute au
 *    passe, elle ne le reecrit pas (section 46).
 *
 * 3. UNE DECISION NE SE REPREND PAS. Le modele refuse de rouvrir une demande
 *    tranchee ; un changement d'avis passe par une nouvelle demande, qui
 *    laisse sa propre trace.
 *
 * L'appel au prestataire se fait APRES la transaction. Un service de paiement
 * indisponible ne doit pas annuler une decision deja prise et communiquee — la
 * demande reste approuvee, et le virement sera repris.
 */
class DecideRefund
{
    public function __construct(
        private readonly CommissionCalculator $commissions,
        private readonly PaymentManager $payments,
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
    ) {}

    public function approve(RefundRequest $request, User $actor, ?string $reason = null): RefundRequest
    {
        return $this->decide($request, $actor, RefundStatus::Approved, $reason);
    }

    public function reject(RefundRequest $request, User $actor, string $reason): RefundRequest
    {
        if (trim($reason) === '') {
            // Section 24.1 : un refus se motive. Sans motif, le client ne peut
            // ni comprendre ni contester.
            throw new RuntimeException('Un refus doit etre motive.');
        }

        return $this->decide($request, $actor, RefundStatus::Rejected, $reason);
    }

    private function decide(
        RefundRequest $request,
        User $actor,
        RefundStatus $status,
        ?string $reason,
    ): RefundRequest {
        if (! $request->isPending()) {
            throw new RuntimeException('Cette demande a deja ete tranchee.');
        }

        $organization = $request->organization;

        return $this->context->runFor($organization, function () use ($request, $actor, $status, $reason) {
            $decided = DB::transaction(function () use ($request, $actor, $status, $reason) {
                $fresh = RefundRequest::query()
                    ->whereKey($request->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // Relu sous verrou : deux membres de l'equipe peuvent trancher
                // la meme demande au meme instant.
                if (! $fresh->isPending()) {
                    return $fresh;
                }

                $fresh->forceFill([
                    'status' => $status->value,
                    'decision_reason' => $reason,
                    'decided_by' => $actor->getKey(),
                    'decided_at' => now(),
                ])->save();

                if ($status === RefundStatus::Approved) {
                    $this->applyRefund($fresh);
                }

                return $fresh;
            });

            $this->audit->log(
                $status === RefundStatus::Approved ? AuditAction::RefundApproved : AuditAction::RefundRejected,
                $decided,
                [
                    'reference' => $decided->reference,
                    'order' => $decided->order->reference,
                    'amount' => $decided->amount,
                    'reason' => $reason,
                ],
                actor: $actor,
                organizationId: $decided->organization_id,
            );

            if ($status === RefundStatus::Approved) {
                $this->sendToProvider($decided);
            }

            $this->notifyBuyer($decided);

            return $decided->refresh();
        });
    }

    /**
     * Rend les billets inutilisables, ajuste la commission et met la commande
     * a jour.
     *
     * Execute dans la transaction de la decision.
     */
    private function applyRefund(RefundRequest $request): void
    {
        $order = $request->order;

        // Billets concernes : un seul si la demande est partielle, tous sinon.
        $tickets = $request->isPartial()
            ? [$request->ticket]
            : $order->tickets()->get()->all();

        foreach ($tickets as $ticket) {
            if ($ticket === null) {
                continue;
            }

            // Un billet deja utilise reste utilise : le remboursement regle la
            // question de l'argent, il n'annule pas une entree deja effectuee.
            if ($ticket->status === TicketStatus::Used) {
                continue;
            }

            $ticket->forceFill(['status' => TicketStatus::Refunded->value])->save();
        }

        // La part BILLETS, jamais le montant rendu : les frais du prestataire
        // n'ont jamais transite par l'organisateur (decision D-023), il n'y a
        // donc aucune commission a leur rendre.
        $this->adjustCommission($order, $request->ticketPortion());

        // PARTIALLY_REFUNDED et non REFUNDED quand il reste des billets
        // valides : le stock et les reversements dependent de cette nuance.
        $remaining = $order->tickets()
            ->whereIn('status', [TicketStatus::Active->value, TicketStatus::Used->value])
            ->count();

        $order->forceFill([
            'status' => $remaining > 0
                ? OrderStatus::PartiallyRefunded->value
                : OrderStatus::Refunded->value,
        ])->save();
    }

    /** Ajoute la ligne d'ajustement de commission (section 24.1). */
    private function adjustCommission(Order $order, int $refundedGross): void
    {
        $original = Commission::query()
            ->originals()
            ->where('commissionable_type', $order->getMorphClass())
            ->where('commissionable_id', $order->getKey())
            ->first();

        if ($original === null) {
            // Aucune commission enregistree : rien a ajuster. Ne pas echouer,
            // le remboursement reste legitime.
            return;
        }

        $this->commissions->recordRefund($original, $refundedGross);
    }

    /**
     * Previent le client de la decision (sections 24.1, 69).
     *
     * C'est le seul canal par lequel il l'apprend : il n'a pas de compte, et
     * rien ne le ramene spontanement sur sa page de suivi. Sans cet email, un
     * refus ne lui serait jamais explique — or la section 24.1 exige qu'il
     * soit motive.
     *
     * Hors transaction et sans jamais interrompre : la decision est prise, un
     * serveur de messagerie indisponible ne doit pas la remettre en cause.
     */
    private function notifyBuyer(RefundRequest $request): void
    {
        $email = $request->order?->email;

        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->send(new RefundDecided($request));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Transmet le remboursement au prestataire.
     *
     * Hors transaction, et sans jamais la remettre en cause : la decision est
     * prise, le billet est deja invalide, et un service indisponible ne doit
     * pas faire croire au client que sa demande a ete refusee. Le virement
     * sera repris.
     */
    private function sendToProvider(RefundRequest $request): void
    {
        try {
            $result = $this->payments->driver()->refund(
                $request->order,
                $request->amount,
                $request->decision_reason ?? 'Remboursement accepte par l\'organisateur',
            );

            $request->forceFill([
                'provider_reference' => $result->providerReference,
                'refunded_at' => $result->succeeded() ? now() : null,
            ])->save();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
