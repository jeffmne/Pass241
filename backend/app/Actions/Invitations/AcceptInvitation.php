<?php

namespace App\Actions\Invitations;

use App\Enums\AuditAction;
use App\Enums\InvitationStatus;
use App\Enums\OrderStatus;
use App\Jobs\SendTicketsEmail;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Audit\AuditLogger;
use App\Services\Ticketing\StockLedger;
use App\Services\Ticketing\TicketIssuer;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Acceptation d'une invitation (section 25).
 *
 * Le moment ou une place mise de cote devient un billet. C'est l'invite qui
 * agit, depuis son telephone, sans compte — comme un acheteur (section 15).
 *
 * TROIS GARANTIES, les memes que pour un paiement confirme :
 *
 * 1. IDEMPOTENT. Une invitation deja acceptee rend son billet au lieu d'en
 *    emettre un second. Un invite qui recharge la page, ou qui rouvre le lien
 *    le lendemain, doit retrouver son billet — pas une erreur.
 *
 * 2. STOCK VERIFIE SOUS VERROU. La place etait retenue, mais son echeance a pu
 *    passer entre-temps : la verification atomique est la seule chose qui
 *    empeche d'emettre un billet au-dela de la capacite (section 17.1).
 *
 * 3. TOUT OU RIEN. Statut de la commande, billet et statut de l'invitation dans
 *    une seule transaction.
 *
 * AUCUNE COMMISSION N'EST ENREGISTREE. Une invitation ne fait entrer aucun
 * argent ; une commission a zero franc encombrerait un journal financier qui
 * doit rester lisible, et laisserait croire qu'une vente a eu lieu.
 */
class AcceptInvitation
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly TicketIssuer $tickets,
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * @throws RuntimeException si l'invitation n'est plus acceptable
     */
    public function handle(Invitation $invitation): Ticket
    {
        $organization = $invitation->organization
            ?? throw new RuntimeException('Cette invitation n\'a plus d\'organisation.');

        $ticket = $this->context->runFor($organization, function () use ($invitation) {
            return DB::transaction(function () use ($invitation) {
                /*
                 * Relecture verrouillee : deux ouvertures simultanees du meme
                 * lien — un invite impatient qui touche deux fois — arrivent
                 * toutes deux ici. La seconde attend, puis constate que le
                 * billet existe deja.
                 */
                $fresh = Invitation::query()
                    ->acrossOrganizations()
                    ->lockForUpdate()
                    ->findOrFail($invitation->getKey());

                $existing = $this->issuedTicket($fresh);

                if ($existing !== null) {
                    return $existing;
                }

                if ($fresh->status === InvitationStatus::Cancelled) {
                    throw new RuntimeException('Cette invitation a ete annulee par l\'organisateur.');
                }

                if (! $fresh->status->isOpen()) {
                    throw new RuntimeException('Cette invitation n\'est plus valable.');
                }

                if ($fresh->hasLapsed()) {
                    throw new RuntimeException('Cette invitation a expire.');
                }

                $order = Order::query()
                    ->acrossOrganizations()
                    ->with('items.ticketType')
                    ->lockForUpdate()
                    ->findOrFail($fresh->order_id);

                $this->commitSeat($order);

                $order->forceFill([
                    'status' => OrderStatus::Paid->value,
                    // La commande n'a rien encaisse : `paid_at` dit ici « la
                    // place est definitivement prise », ce qui est le sens dont
                    // le reste du produit a besoin.
                    'paid_at' => now(),
                    'expires_at' => null,
                ])->save();

                $this->tickets->issue($order);

                $fresh->forceFill([
                    'status' => InvitationStatus::Accepted->value,
                    'accepted_at' => now(),
                ])->save();

                $this->audit->log(
                    AuditAction::InvitationAccepted,
                    $fresh,
                    [
                        'guest' => $fresh->guest_name,
                        'order' => $order->reference,
                    ],
                    actor: null,
                    organizationId: $order->organization_id,
                );

                return $this->issuedTicket($fresh) ?? throw new RuntimeException(
                    'Le billet n\'a pas pu etre emis.'
                );
            });
        });

        $this->sendCopyByEmail($invitation->refresh());

        return $ticket;
    }

    /** Le billet deja emis pour cette invitation, s'il existe. */
    private function issuedTicket(Invitation $invitation): ?Ticket
    {
        return Ticket::query()
            ->acrossOrganizations()
            ->where('order_id', $invitation->order_id)
            ->first();
    }

    /**
     * Consomme la place, sous verrou (section 17.1).
     *
     * La place etait reservee, mais l'echeance a pu passer : entre-temps, des
     * acheteurs ont pu prendre les dernieres places. Le refus est alors net et
     * explique — mieux vaut le dire a l'invite maintenant qu'a la porte.
     */
    private function commitSeat(Order $order): void
    {
        foreach ($order->items as $item) {
            $ticketType = $item->ticketType
                ?? TicketType::query()->acrossOrganizations()->find($item->ticket_type_id);

            if (! $ticketType) {
                throw new RuntimeException('Le billet de cette invitation n\'existe plus.');
            }

            $this->stock->commit($ticketType, $item->quantity, $order->getKey());
        }
    }

    /**
     * Envoie le billet par email, si l'invite en a une (section 69).
     *
     * Hors de la transaction : un serveur de messagerie indisponible ne doit
     * pas annuler une invitation acceptee. Le billet est deja emis et
     * consultable a l'ecran.
     */
    private function sendCopyByEmail(Invitation $invitation): void
    {
        if (! $invitation->guest_email) {
            return;
        }

        try {
            SendTicketsEmail::dispatch($invitation->order_id);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
