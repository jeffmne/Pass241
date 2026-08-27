<?php

namespace App\Actions\Orders;

use App\Enums\AuditAction;
use App\Enums\CommissionType;
use App\Enums\OrderStatus;
use App\Jobs\SendTicketsEmail;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Audit\AuditLogger;
use App\Services\Commission\CommissionCalculator;
use App\Services\Ticketing\StockLedger;
use App\Services\Ticketing\TicketIssuer;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Confirmation d'un paiement (sections 15.1, 17.1, 21.2, 23).
 *
 * C'est le moment ou l'argent devient des billets. Quatre garanties tiennent
 * ensemble ici, et aucune ne peut sauter sans consequence :
 *
 * 1. IDEMPOTENCE (section 23). Une commande deja payee ressort telle quelle.
 *    Les webhooks mobile money sont rejoues, c'est normal, et un rejeu ne doit
 *    jamais produire un second jeu de billets ni une seconde commission.
 *
 * 2. VERIFICATION ATOMIQUE DU STOCK (section 17.1). Un verrou de ligne
 *    serialise les confirmations concurrentes. C'est ce qui couvre le webhook
 *    arrive apres l'expiration de la reservation.
 *
 * 3. TOUT OU RIEN. Statut, billets et commission sont ecrits dans une seule
 *    transaction. Une commande payee sans billets, ou des billets sans
 *    commission, seraient l'un et l'autre impossibles a rattraper proprement.
 *
 * 4. COMMISSION CALCULEE (sections 21.2, 80.2). Jamais saisie, avec le taux
 *    fige au moment de la transaction.
 */
class ConfirmPayment
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly CommissionCalculator $commissions,
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
        private readonly TicketIssuer $tickets,
    ) {}

    /**
     * @param  string|null  $providerReference  identifiant de la transaction chez l'operateur
     *
     * @throws RuntimeException si le stock ne suffit plus
     */
    public function handle(Order $order, ?string $providerReference = null): Order
    {
        // Section 23 : seul le premier passage confirme. Les suivants sortent
        // sans rien faire.
        if ($order->isPaid()) {
            return $order;
        }

        if ($order->status === OrderStatus::Cancelled || $order->status === OrderStatus::Refunded) {
            throw new RuntimeException(sprintf(
                'Une commande %s ne peut plus etre payee.',
                $order->status->label()
            ));
        }

        $confirmed = $this->context->runFor($order->organization, function () use ($order, $providerReference) {
            return DB::transaction(function () use ($order, $providerReference) {
                /*
                 * Relecture verrouillee de la commande.
                 *
                 * Deux webhooks livres en parallele arrivent tous deux ici :
                 * le second attend, puis constate que la commande est deja
                 * payee et ressort. Sans ce verrou, le test d'idempotence
                 * ci-dessus serait gagnable par les deux a la fois.
                 */
                $fresh = Order::query()
                    ->acrossOrganizations()
                    ->lockForUpdate()
                    ->find($order->getKey());

                if (! $fresh || $fresh->isPaid()) {
                    return $fresh ?? $order;
                }

                $fresh->setRelation('items', $order->items()->with('ticketType')->get());

                $this->assertStockStillAvailable($fresh);

                $fresh->forceFill([
                    'status' => OrderStatus::Paid,
                    'paid_at' => now(),
                    'provider_reference' => $providerReference ?? $fresh->provider_reference,
                    'expires_at' => null,
                    'failed_at' => null,
                    'failure_reason' => null,
                ])->save();

                $tickets = $this->tickets->issue($fresh);

                /*
                 * Section 21.2 : la commission est calculee automatiquement,
                 * avec un instantane du taux.
                 *
                 * Assise sur `subtotal` — le prix des billets — et JAMAIS sur
                 * `amount`, qui contient en plus les frais du prestataire
                 * avances par l'acheteur (decision D-023). Les prendre pour
                 * assiette ferait prelever a Pass241 une commission sur
                 * l'argent d'un tiers, et gonflerait mecaniquement le net
                 * reverse a l'organisateur.
                 */
                $this->commissions->record(
                    commissionable: $fresh,
                    organization: $fresh->organization,
                    grossAmount: $fresh->subtotal,
                    type: CommissionType::Ticketing,
                );

                $this->audit->log(
                    AuditAction::PaymentConfirmed,
                    $fresh,
                    [
                        'reference' => $fresh->reference,
                        'amount' => $fresh->amount,
                        'tickets' => $tickets,
                        'provider_reference' => $fresh->provider_reference,
                    ],
                    actor: null,
                    organizationId: $fresh->organization_id,
                );

                return $fresh;
            });
        });

        $this->sendCopyByEmail($confirmed);

        return $confirmed;
    }

    /**
     * Envoie la copie du billet, si une adresse a ete renseignee (section 69).
     *
     * Hors de la transaction, et volontairement : un serveur de messagerie
     * indisponible ne doit jamais annuler un paiement confirme. L'email est une
     * commodite, le billet est deja emis et consultable (section 15.1).
     *
     * Delegue a un job : l'email porte le billet en PDF, dont le rendu coute
     * trop cher pour le webhook de l'operateur, qui abandonne s'il attend.
     * Le `try` reste utile — en file synchrone, l'echec remonterait ici.
     */
    private function sendCopyByEmail(Order $order): void
    {
        if (! $order->email) {
            return;
        }

        try {
            SendTicketsEmail::dispatch($order->getKey());
        } catch (Throwable $e) {
            // Journalise sans interrompre : personne ne perd son billet parce
            // qu'un email n'est pas parti.
            report($e);
        }
    }

    /**
     * Section 17.1, second niveau de protection.
     *
     * La reservation de cette commande a pu expirer entre le moment ou
     * l'acheteur a valide sur son telephone et celui ou le callback est arrive.
     * Le stock est donc reverifie, sous verrou, avant toute emission.
     */
    private function assertStockStillAvailable(Order $order): void
    {
        foreach ($order->items as $item) {
            $ticketType = $item->ticketType
                ?? TicketType::query()->acrossOrganizations()->find($item->ticket_type_id);

            if (! $ticketType) {
                throw new RuntimeException('Un des billets de cette commande n\'existe plus.');
            }

            $this->stock->commit($ticketType, $item->quantity, $order->getKey());
        }
    }

    // L'emission des billets vit dans `TicketIssuer` : les invitations
    // produisent les memes billets par le meme geste (decision D-039).

    /**
     * Marque un paiement comme echoue (section 21.1).
     *
     * Distinct de l'expiration : ici l'operateur a repondu, et sa reponse est
     * un refus. Le motif est conserve pour que l'acheteur sache s'il doit
     * reessayer ou recharger son compte.
     */
    public function markFailed(Order $order, string $reason): Order
    {
        if (! $order->isPending()) {
            return $order;
        }

        try {
            $order->forceFill([
                'status' => OrderStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => $reason,
            ])->save();
        } catch (Throwable $e) {
            report($e);

            return $order;
        }

        $this->audit->log(
            AuditAction::PaymentFailed,
            $order,
            ['reference' => $order->reference, 'reason' => $reason],
            actor: null,
            organizationId: $order->organization_id,
        );

        return $order;
    }
}
