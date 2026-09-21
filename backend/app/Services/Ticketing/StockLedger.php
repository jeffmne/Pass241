<?php

namespace App\Services\Ticketing;

use App\Enums\OrderStatus;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Etat du stock d'un type de billet (section 17.1).
 *
 *     disponible = quantite − vendus − reserves
 *
 * Les trois termes se lisent en base, jamais ailleurs :
 *
 * - vendus   : commandes payees ;
 * - reserves : commandes en attente dont l'expiration n'est pas atteinte.
 *
 * C'est le point central de la decision D-017. La reservation n'est pas un
 * etat separe a maintenir : c'est simplement une commande PENDING encore
 * valide. Il n'y a donc rien a synchroniser, rien a nettoyer, et la condition
 * sur la date d'expiration fait expirer les reservations d'elle-meme.
 *
 * Consequence directe : si Redis tombe ou si la file d'attente s'arrete, le
 * stock reste juste.
 */
class StockLedger
{
    /**
     * Un seul agregat pour l'affichage de tous les tarifs. Cet instantane vit
     * uniquement sur ces modeles de presentation ; les achats relisent le
     * stock sous verrou, sans cache partage ni retard d'invalidation.
     *
     * @param  Collection<int, TicketType>  $types
     */
    public function loadDisplayCounts(Collection $types): void
    {
        if ($types->isEmpty()) {
            return;
        }

        $counts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.ticket_type_id', $types->modelKeys())
            ->whereIn('orders.status', [OrderStatus::Paid->value, OrderStatus::PartiallyRefunded->value, OrderStatus::Pending->value])
            ->select('order_items.ticket_type_id')
            ->selectRaw('SUM(CASE WHEN orders.status IN (?, ?) THEN order_items.quantity ELSE 0 END) AS sold', [OrderStatus::Paid->value, OrderStatus::PartiallyRefunded->value])
            ->selectRaw('SUM(CASE WHEN orders.status = ? AND orders.expires_at > ? THEN order_items.quantity ELSE 0 END) AS reserved', [OrderStatus::Pending->value, now()])
            ->groupBy('order_items.ticket_type_id')
            ->get()->keyBy('ticket_type_id');

        foreach ($types as $type) {
            $row = $counts->get($type->getKey());
            $type->setDisplayStock((int) ($row?->sold ?? 0), (int) ($row?->reserved ?? 0));
        }
    }

    /**
     * Billets deja vendus.
     *
     * Seules les commandes effectivement payees comptent. Un remboursement
     * partiel laisse la commande payee : les billets restants sont toujours
     * consommes.
     */
    public function sold(TicketType $ticketType): int
    {
        return (int) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.ticket_type_id', $ticketType->getKey())
            ->whereIn('orders.status', [
                OrderStatus::Paid->value,
                OrderStatus::PartiallyRefunded->value,
            ])
            ->sum('order_items.quantity');
    }

    /**
     * Billets reserves par des commandes en cours de paiement.
     *
     * `expires_at > now()` fait tout le travail : une reservation echue cesse
     * d'etre comptee a la seconde ou elle expire, sans qu'aucun traitement
     * n'ait besoin de s'executer.
     */
    public function reserved(TicketType $ticketType): int
    {
        return (int) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.ticket_type_id', $ticketType->getKey())
            ->where('orders.status', OrderStatus::Pending->value)
            ->whereNotNull('orders.expires_at')
            ->where('orders.expires_at', '>', now())
            ->sum('order_items.quantity');
    }

    /** Ce qu'un acheteur peut encore prendre, maintenant. */
    public function available(TicketType $ticketType): int
    {
        $occupied = (int) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.ticket_type_id', $ticketType->getKey())
            ->where(function ($query) {
                $query->whereIn('orders.status', [OrderStatus::Paid->value, OrderStatus::PartiallyRefunded->value])
                    ->orWhere(fn ($pending) => $pending->where('orders.status', OrderStatus::Pending->value)
                        ->where('orders.expires_at', '>', now()));
            })
            ->sum('order_items.quantity');

        return max(0, $ticketType->quantity - $occupied);
    }

    /**
     * Verifie et consomme le stock de facon atomique.
     *
     * Section 17.1 : c'est le second niveau de protection, celui qui couvre ce
     * que la reservation ne peut pas couvrir — un webhook arrive apres
     * l'expiration, un traitement differe en retard, une horloge decalee, une
     * confirmation empruntant un autre chemin.
     *
     * Le verrou de ligne PostgreSQL (`SELECT ... FOR UPDATE`) serialise les
     * confirmations concurrentes sur un meme type de billet : deux webhooks
     * traites en parallele ne peuvent pas lire le meme stock restant et
     * conclure tous les deux qu'il y a de la place.
     *
     * La reservation de la commande en cours est exclue du decompte : elle est
     * precisement ce qu'on est en train de transformer en vente.
     *
     * @throws RuntimeException si le stock ne suffit plus
     */
    public function commit(TicketType $ticketType, int $quantity, int $excludingOrderId): void
    {
        /*
         * Hors transaction, le verrou de ligne pose plus bas serait relache
         * immediatement et ne protegerait plus rien : mieux vaut une erreur
         * bruyante qu'une garantie qu'on croit avoir.
         *
         * Cette garde n'est pas couverte par la suite de tests, et ne peut pas
         * l'etre : RefreshDatabase enveloppe chaque test dans une transaction,
         * donc la condition detectee ici ne s'y produit jamais.
         */
        if (! DB::transactionLevel()) {
            throw new RuntimeException(
                'La consommation de stock doit s\'executer dans une transaction : '
                .'hors transaction, le verrou de ligne serait relache immediatement.'
            );
        }

        // Verrouille la ligne du type de billet jusqu'a la fin de la
        // transaction. Toute autre confirmation portant sur ce meme type
        // attend ici.
        $locked = DB::table('ticket_types')
            ->where('id', $ticketType->getKey())
            ->lockForUpdate()
            ->first(['id', 'name', 'quantity']);

        if (! $locked) {
            throw new RuntimeException('Ce type de billet n\'existe plus.');
        }

        $sold = $this->sold($ticketType);
        $reserved = $this->reservedExcluding($ticketType, $excludingOrderId);
        $available = $locked->quantity - $sold - $reserved;

        if ($quantity > $available) {
            throw new RuntimeException(sprintf(
                '« %s » : il ne reste plus assez de billets (%d demande%s, %d disponible%s).',
                $locked->name,
                $quantity,
                $quantity > 1 ? 's' : '',
                max(0, $available),
                $available > 1 ? 's' : '',
            ));
        }
    }

    /**
     * Reservations en cours, hors celle de la commande designee.
     *
     * Sans cette exclusion, une commande se verrait refuser le stock qu'elle a
     * elle-meme reserve.
     */
    private function reservedExcluding(TicketType $ticketType, int $orderId): int
    {
        return (int) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.ticket_type_id', $ticketType->getKey())
            ->where('orders.id', '!=', $orderId)
            ->where('orders.status', OrderStatus::Pending->value)
            ->whereNotNull('orders.expires_at')
            ->where('orders.expires_at', '>', now())
            ->sum('order_items.quantity');
    }
}
