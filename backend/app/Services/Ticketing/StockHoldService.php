<?php

namespace App\Services\Ticketing;

use App\Models\TicketType;
use Illuminate\Support\Facades\Redis;

/**
 * Reservation temporaire du dernier billet (section 17.1).
 *
 * Le hold ne se declenche QUE lorsqu'il ne reste qu'un seul billet disponible
 * pour un type donne. C'est la regle explicite de la section 17.1, et elle a
 * une raison : bloquer du stock abondant ferait afficher « complet » alors
 * qu'il reste des places, ce qui coute des ventes.
 *
 * Redis plutot qu'un verrou en base : le TTL libere la reservation tout seul.
 * Un verrou base de donnees oblige a un processus de nettoyage, et laisse du
 * stock bloque si ce processus tombe — exactement le soir d'un concert.
 *
 * LIMITE ASSUMEE, ecrite ici pour qu'elle ne se decouvre pas en production :
 * au-dessus d'un billet restant, plusieurs commandes simultanees peuvent etre
 * ouvertes sur le meme stock. La garantie de non-depassement est donc reportee
 * a la confirmation du paiement, ou le stock est verifie et decremente de
 * facon atomique avant l'emission des billets.
 */
class StockHoldService
{
    /**
     * Duree de vie d'un hold.
     *
     * Rigoureusement egale au delai de validation d'un paiement (section 21.1)
     * : un hold plus court libererait le billet alors que l'acheteur est encore
     * en train de saisir son code, un hold plus long le garderait bloque apres
     * l'echec.
     */
    public function ttlSeconds(): int
    {
        return (int) config('payments.timeout_seconds');
    }

    /**
     * Tente de reserver le dernier billet d'un type.
     *
     * Renvoie true si la commande peut continuer, false si quelqu'un d'autre a
     * pris le dernier billet quelques secondes plus tot.
     *
     * Quand il reste plus d'un billet, aucun hold n'est pose et la methode
     * renvoie true : il n'y a rien a proteger.
     */
    public function tryHold(TicketType $ticketType, string $orderReference, int $quantity = 1): bool
    {
        if (! $this->requiresHold($ticketType, $quantity)) {
            return true;
        }

        // `SET NX` : pose la cle seulement si elle n'existe pas. L'operation est
        // atomique cote Redis, donc deux acheteurs simultanes ne peuvent pas
        // reussir tous les deux — ce que ne garantirait pas un `exists` suivi
        // d'un `set`.
        $acquired = Redis::set(
            $this->key($ticketType),
            $orderReference,
            'EX',
            $this->ttlSeconds(),
            'NX',
        );

        return (bool) $acquired;
    }

    /**
     * Relache le hold, si et seulement s'il appartient a cette commande.
     *
     * La verification du proprietaire evite un scenario reel : le hold de
     * l'acheteur A expire, B pose le sien, puis le job d'expiration de A
     * s'execute avec du retard et liberait le billet de B.
     */
    public function release(TicketType $ticketType, string $orderReference): void
    {
        $key = $this->key($ticketType);

        if (Redis::get($key) === $orderReference) {
            Redis::del($key);
        }
    }

    /** Un hold est-il actuellement pose sur ce type de billet ? */
    public function isHeld(TicketType $ticketType): bool
    {
        return (bool) Redis::exists($this->key($ticketType));
    }

    /** Reference de la commande qui detient le hold, le cas echeant. */
    public function holder(TicketType $ticketType): ?string
    {
        return Redis::get($this->key($ticketType)) ?: null;
    }

    /**
     * Le hold s'applique uniquement au dernier billet disponible.
     *
     * Une demande de plusieurs billets alors qu'il n'en reste qu'un est de
     * toute facon refusee plus haut, a la verification de disponibilite.
     */
    private function requiresHold(TicketType $ticketType, int $quantity): bool
    {
        return $quantity === 1 && $ticketType->remainingStock() === 1;
    }

    private function key(TicketType $ticketType): string
    {
        return 'pass241:hold:ticket_type:'.$ticketType->getKey();
    }
}
