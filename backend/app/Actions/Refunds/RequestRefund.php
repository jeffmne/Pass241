<?php

namespace App\Actions\Refunds;

use App\Enums\AuditAction;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Ticket;
use App\Services\Audit\AuditLogger;
use App\Services\Refunds\RefundPolicy;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Soumission d'une demande de remboursement par le client (section 24.1).
 *
 * Le client DEMANDE, il ne rembourse pas. Rien d'argent ne bouge ici : cette
 * action ne fait qu'ouvrir un dossier que l'organisateur devra trancher.
 *
 * L'acheteur n'a pas de compte (section 15) : l'action s'execute donc hors de
 * tout contexte d'organisation, dans une requete publique. L'organisation est
 * deduite de la commande, jamais d'un parametre envoye par le client
 * (regle 80.4).
 */
class RequestRefund
{
    public function __construct(
        private readonly RefundPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(
        Order $order,
        ?Ticket $ticket = null,
        ?string $reason = null,
        ?string $ipAddress = null,
    ): RefundRequest {
        // Le billet doit appartenir a CETTE commande. Sans cette verification,
        // une reference glissee dans la requete permettrait de demander le
        // remboursement du billet de quelqu'un d'autre.
        if ($ticket && $ticket->order_id !== $order->getKey()) {
            throw new RuntimeException('Ce billet n\'appartient pas a cette commande.');
        }

        if ($blocker = $this->policy->blocker($order, $ticket)) {
            throw new RuntimeException($blocker);
        }

        $amount = $this->policy->refundableAmount($order, $ticket);

        if ($amount <= 0) {
            throw new RuntimeException('Il n\'y a aucun montant a rembourser sur cette commande.');
        }

        return $this->context->runFor($order->organization, function () use (
            $order, $ticket, $reason, $ipAddress, $amount
        ) {
            try {
                $request = DB::transaction(fn () => RefundRequest::create([
                    'order_id' => $order->getKey(),
                    'ticket_id' => $ticket?->getKey(),
                    'reference' => $this->uniqueReference(),
                    'status' => RefundStatus::Requested->value,
                    'amount' => $amount,
                    'psp_fee_amount' => $this->policy->nonRefundableFee($order, $ticket),
                    'reason' => $reason,
                    'ip_address' => $ipAddress,
                ]));
            } catch (UniqueConstraintViolationException) {
                // L'index partiel n'autorise qu'une demande EN COURS a la fois :
                // sans lui, un client pourrait en empiler dix pour forcer la
                // main a l'organisateur.
                throw new RuntimeException('Une demande est deja en cours pour ce billet.');
            }

            $this->audit->log(
                AuditAction::RefundRequested,
                $request,
                [
                    'reference' => $request->reference,
                    'order' => $order->reference,
                    'ticket' => $ticket?->reference,
                    'amount' => $request->amount,
                    'reason' => $reason,
                ],
                // Aucun acteur : le demandeur n'a pas de compte. L'adresse IP
                // conservee sur la ligne est la seule trace technique.
                actor: null,
                organizationId: $order->organization_id,
            );

            return $request;
        });
    }

    /** Reference lisible, du type RB-4KX9-27B. */
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
