<?php

namespace App\Actions\Refunds;

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\RefundOrigin;
use App\Enums\RefundStatus;
use App\Enums\TicketStatus;
use App\Mail\RefundDecided;
use App\Models\Commission;
use App\Models\Event;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Commission\CommissionCalculator;
use App\Services\Payments\PaymentManager;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Rembourse tous les acheteurs d'un evenement annule (sections 24, 24.1,
 * decision D-028).
 *
 * Chemin DISTINCT de la demande client, et pour une raison de fond : ici le
 * client n'a rien demande et n'y est pour rien. Trois differences en
 * decoulent :
 *
 * 1. LES FRAIS DU PRESTATAIRE SONT RENDUS. Quand un client change d'avis, ils
 *    restent chez l'operateur (decision D-023). Quand l'organisateur annule,
 *    faire supporter au client le cout d'une decision qui n'est pas la sienne
 *    serait indefendable — c'est l'organisateur qui les supporte.
 *
 * 2. AUCUNE DECISION N'EST DEMANDEE. Les demandes sont creees deja approuvees :
 *    il n'y a rien a arbitrer, l'evenement n'aura pas lieu.
 *
 * 3. LE DELAI DE 48H NE S'APPLIQUE PAS. Il protege l'organisateur des
 *    changements d'avis tardifs ; il n'a aucun sens quand c'est lui qui annule.
 *
 * La commission est integralement rendue, mais sur la PART BILLETS seulement :
 * les frais n'ont jamais transite par l'organisateur, il n'y a aucune
 * commission a leur rendre.
 */
class RefundCancelledEvent
{
    public function __construct(
        private readonly CommissionCalculator $commissions,
        private readonly PaymentManager $payments,
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * @return int nombre de commandes remboursees
     */
    public function handle(Event $event, User $actor): int
    {
        return $this->context->runFor($event->organization, function () use ($event, $actor) {
            $orders = Order::query()
                ->where('event_id', $event->getKey())
                ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::PartiallyRefunded->value])
                ->with(['tickets', 'items'])
                ->get();

            $refunded = 0;

            foreach ($orders as $order) {
                // Une commande en echec ne doit pas arreter les autres : les
                // acheteurs suivants attendent leur argent eux aussi.
                try {
                    if ($this->refundOrder($order, $event, $actor)) {
                        $refunded++;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }

            return $refunded;
        });
    }

    private function refundOrder(Order $order, Event $event, User $actor): bool
    {
        // Part encore remboursable : les billets deja rembourses ne le sont
        // pas une seconde fois.
        $refundableTickets = $order->tickets
            ->filter(fn ($ticket) => $ticket->status !== TicketStatus::Refunded);

        if ($refundableTickets->isEmpty()) {
            return false;
        }

        $ticketPortion = $this->remainingTicketAmount($order, $refundableTickets->count());

        if ($ticketPortion <= 0) {
            return false;
        }

        $fee = $this->remainingFee($order, $ticketPortion);

        $request = DB::transaction(function () use ($order, $actor, $ticketPortion, $fee, $refundableTickets) {
            $request = RefundRequest::create([
                'order_id' => $order->getKey(),
                'reference' => $this->uniqueReference(),
                // Deja approuvee : il n'y a rien a arbitrer, l'evenement
                // n'aura pas lieu.
                'status' => RefundStatus::Approved->value,
                'origin' => RefundOrigin::EventCancelled->value,
                'amount' => $ticketPortion + $fee,
                'psp_fee_amount' => $fee,
                'includes_psp_fee' => true,
                'reason' => 'Evenement annule par l\'organisateur',
            ]);

            $request->forceFill([
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_reason' => 'Evenement annule',
            ])->save();

            foreach ($refundableTickets as $ticket) {
                // Un billet deja utilise reste utilise : le remboursement
                // regle la question de l'argent, il n'annule pas une entree
                // deja effectuee.
                if ($ticket->status === TicketStatus::Used) {
                    continue;
                }

                $ticket->forceFill(['status' => TicketStatus::Refunded->value])->save();
            }

            $this->adjustCommission($order, $ticketPortion);

            $order->forceFill(['status' => OrderStatus::Refunded->value])->save();

            return $request;
        });

        $this->audit->log(
            AuditAction::RefundApproved,
            $request,
            [
                'reference' => $request->reference,
                'order' => $order->reference,
                'amount' => $request->amount,
                'origin' => RefundOrigin::EventCancelled->value,
                'event' => $event->slug,
            ],
            actor: $actor,
            organizationId: $order->organization_id,
        );

        $this->sendToProvider($request, $order);
        $this->notifyBuyer($request, $order);

        return true;
    }

    /**
     * Prix des billets encore remboursables.
     *
     * Somme des prix unitaires reellement payes, releves sur les lignes de
     * commande : le tarif a pu changer depuis l'achat.
     */
    private function remainingTicketAmount(Order $order, int $ticketCount): int
    {
        $unitPrices = $order->items
            ->flatMap(fn ($item) => array_fill(0, $item->quantity, $item->unit_price))
            ->values();

        // Les billets restants sont les derniers de la liste : sans plan de
        // salle, ils sont interchangeables, et la somme est la meme.
        return (int) $unitPrices->take($ticketCount)->sum();
    }

    /** Part des frais correspondant aux billets encore remboursables. */
    private function remainingFee(Order $order, int $ticketPortion): int
    {
        if ($order->psp_fee_amount <= 0 || $order->subtotal <= 0) {
            return 0;
        }

        return intdiv(
            $order->psp_fee_amount * $ticketPortion * 2 + $order->subtotal,
            $order->subtotal * 2,
        );
    }

    private function adjustCommission(Order $order, int $ticketPortion): void
    {
        $original = Commission::query()
            ->originals()
            ->where('commissionable_type', $order->getMorphClass())
            ->where('commissionable_id', $order->getKey())
            ->first();

        if ($original === null) {
            return;
        }

        $this->commissions->recordRefund($original, $ticketPortion);
    }

    private function sendToProvider(RefundRequest $request, Order $order): void
    {
        try {
            $result = $this->payments->driver()->refund(
                $order,
                $request->amount,
                'Evenement annule par l\'organisateur',
            );

            $request->forceFill([
                'provider_reference' => $result->providerReference,
                'refunded_at' => $result->succeeded() ? now() : null,
            ])->save();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function notifyBuyer(RefundRequest $request, Order $order): void
    {
        if (! $order->email) {
            return;
        }

        try {
            Mail::to($order->email)->send(new RefundDecided($request->fresh()));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function uniqueReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $block = fn (int $length) => collect(range(1, $length))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');

            $reference = 'RB-'.$block(4).'-'.$block(3);
        } while (RefundRequest::query()->acrossOrganizations()->where('reference', $reference)->exists());

        return $reference;
    }
}
