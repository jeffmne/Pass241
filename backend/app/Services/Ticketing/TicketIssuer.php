<?php

namespace App\Services\Ticketing;

use App\Enums\AuditAction;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\Audit\AuditLogger;

/**
 * Emission des billets d'une commande (section 15.1).
 *
 * Extrait de `ConfirmPayment` le jour ou les invitations ont eu besoin du meme
 * geste (decision D-039). Deux chemins produisent des billets — un paiement
 * confirme, une invitation acceptee — et ils doivent en produire exactement
 * les memes : meme reference lisible, meme jeton signe, meme journalisation.
 *
 * Une seconde implementation aurait fini par diverger, et la divergence serait
 * tombee du cote des invitations : le chemin le moins parcouru, donc celui ou
 * un billet mal forme se serait vu le plus tard — a une porte, un soir.
 */
class TicketIssuer
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Emet un billet par unite commandee.
     *
     * Le porteur est l'acheteur : la plateforme ne demande pas le nom de chaque
     * participant a l'achat (section 16), et inventer des porteurs distincts
     * serait faux. Pour une invitation, l'acheteur EST l'invite — la commande
     * porte son nom.
     *
     * @return int nombre de billets emis
     */
    public function issue(Order $order): int
    {
        $issued = 0;

        foreach ($order->items as $item) {
            for ($i = 0; $i < $item->quantity; $i++) {
                Ticket::create([
                    'event_id' => $order->event_id,
                    'order_id' => $order->getKey(),
                    'order_item_id' => $item->getKey(),
                    'ticket_type_id' => $item->ticket_type_id,
                    'reference' => $this->uniqueReference(),
                    'holder_name' => $order->buyerName(),
                ]);

                $issued++;
            }
        }

        $this->audit->log(
            AuditAction::TicketCreated,
            $order,
            ['reference' => $order->reference, 'count' => $issued],
            actor: null,
            organizationId: $order->organization_id,
        );

        return $issued;
    }

    /**
     * Reference lisible d'un billet, imprimee dessus.
     *
     * Ce n'est pas un secret : le QR encode un jeton signe, jamais cette valeur
     * (section 18). Elle sert au support et a la recherche manuelle a l'entree.
     *
     * L'alphabet exclut I, L, O, 0 et 1 : une reference se lit a voix haute au
     * telephone, et se recopie a la main sur un cahier d'entree.
     */
    public function uniqueReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $block = fn (int $length) => collect(range(1, $length))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');

            $reference = 'T-'.$block(4).'-'.$block(4);
        } while (Ticket::query()->acrossOrganizations()->where('reference', $reference)->exists());

        return $reference;
    }
}
