<?php

namespace App\Services\Statistics;

use App\Enums\CommissionType;
use App\Enums\TicketStatus;
use App\Models\Organization;
use App\Services\Commission\CommissionCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Chiffres de billetterie du dashboard organisateur (section 56).
 *
 * TOUT EST AGREGE EN SQL. Aucune collection de billets n'est chargee, jamais :
 * un evenement de 100 000 billets doit produire les memes quelques lignes de
 * resultat qu'un evenement de dix. Compter en PHP marcherait six mois, puis
 * ferait tomber le dashboard le jour du premier gros evenement — c'est-a-dire
 * exactement le jour ou l'organisateur le regarde le plus.
 *
 * DEUX REGLES DE COMPTAGE, qui expliquent les chiffres :
 *
 * 1. Un billet REMBOURSE, annule ou expire n'est pas vendu. Le compter
 *    gonflerait les recettes d'un argent rendu au client.
 * 2. La recette d'un billet est le prix REELLEMENT PAYE, releve sur la ligne
 *    de commande, jamais le tarif courant du type : le prix a pu changer
 *    depuis l'achat.
 *
 * Les frais du prestataire n'apparaissent nulle part : ils ont ete avances par
 * l'acheteur et n'ont jamais appartenu a l'organisateur (decision D-023).
 */
class TicketingStatistics
{
    /**
     * Duree de mise en cache.
     *
     * Une minute : assez pour qu'un dashboard rafraichi en boucle ne
     * reagrege pas a chaque fois, assez court pour qu'une vente faite a
     * l'instant apparaisse avant que quelqu'un ne s'inquiete.
     */
    private const CACHE_SECONDS = 60;

    public function __construct(private readonly CommissionCalculator $commissions) {}

    /**
     * Chiffres consolides de l'organisation.
     *
     * @return array{
     *     sold: int, revenue: int, net: int, capacity: int, remaining: int,
     *     entries: int, attendanceRate: float|null, hasEntries: bool
     * }
     */
    public function forOrganization(Organization $organization): array
    {
        return Cache::remember(
            $this->cacheKey($organization),
            self::CACHE_SECONDS,
            fn () => $this->compute($organization),
        );
    }

    /**
     * Ventes par type de billet, agregees par NOM.
     *
     * Par nom et non par identifiant : « VIP » veut dire la meme chose d'un
     * evenement a l'autre, et c'est cette lecture qui interesse l'organisateur
     * — comment se vend sa categorie VIP, pas comment se vend la ligne 47.
     *
     * @return Collection<int, array{
     *     name: string, sold: int, capacity: int, remaining: int,
     *     revenue: int, share: float
     * }>
     */
    public function byTicketType(Organization $organization): Collection
    {
        $rows = Cache::remember(
            $this->cacheKey($organization).':types',
            self::CACHE_SECONDS,
            fn () => $this->computeByType($organization),
        );

        $totalSold = array_sum(array_column($rows, 'sold'));

        return collect($rows)
            ->map(function (array $row) use ($totalSold) {
                $row['remaining'] = max(0, $row['capacity'] - $row['sold']);
                // Part des ventes, pas de la capacite : c'est la repartition
                // de ce qui est parti qui renseigne, pas celle de l'offre.
                // Cast explicite : une division PHP rend un entier quand elle
                // tombe juste, et le type de `share` changerait alors d'une
                // ligne a l'autre.
                $row['share'] = $totalSold > 0 ? (float) ($row['sold'] / $totalSold * 100) : 0.0;

                return $row;
            })
            ->sortByDesc('sold')
            ->values();
    }

    /** Vide le cache d'une organisation. */
    public function forget(Organization $organization): void
    {
        Cache::forget($this->cacheKey($organization));
        Cache::forget($this->cacheKey($organization).':types');
    }

    /**
     * @return array{
     *     sold: int, revenue: int, net: int, capacity: int, remaining: int,
     *     entries: int, attendanceRate: float|null, hasEntries: bool
     * }
     */
    private function compute(Organization $organization): array
    {
        $sales = DB::table('tickets')
            ->join('order_items', 'order_items.id', '=', 'tickets.order_item_id')
            ->where('tickets.organization_id', $organization->getKey())
            ->whereIn('tickets.status', $this->soldStatuses())
            ->selectRaw('
                COUNT(*) AS sold,
                COALESCE(SUM(order_items.unit_price), 0) AS revenue,
                COUNT(*) FILTER (WHERE tickets.status = ?) AS entries
            ', [TicketStatus::Used->value])
            ->first();

        $capacity = (int) DB::table('ticket_types')
            ->where('organization_id', $organization->getKey())
            ->sum('quantity');

        $sold = (int) ($sales->sold ?? 0);
        $revenue = (int) ($sales->revenue ?? 0);
        $entries = (int) ($sales->entries ?? 0);

        return [
            'sold' => $sold,
            'revenue' => $revenue,
            // Ce que l'organisateur recevra reellement. Afficher la recette
            // brute seule ferait lire le reversement comme une erreur
            // (section 21.2).
            'net' => $this->commissions->netFor($revenue, CommissionType::Ticketing),
            'capacity' => $capacity,
            'remaining' => max(0, $capacity - $sold),
            'entries' => $entries,
            'attendanceRate' => $sold > 0 ? $entries / $sold * 100 : null,
            // Le controle d'acces n'existe pas encore (phase 5). Tant qu'aucun
            // billet n'a ete scanne, annoncer « 0 entree » et « 0 % de
            // presence » ne renseigne sur rien et se lit comme un echec.
            'hasEntries' => $entries > 0,
        ];
    }

    /**
     * @return list<array{name: string, sold: int, capacity: int, revenue: int}>
     */
    private function computeByType(Organization $organization): array
    {
        /*
         * Une seule requete, avec la sous-requete de ventes jointe a gauche :
         * un type jamais vendu doit apparaitre avec zero, pas disparaitre.
         * C'est souvent celui-la qu'on cherche.
         */
        $sold = DB::table('tickets')
            ->join('order_items', 'order_items.id', '=', 'tickets.order_item_id')
            ->whereIn('tickets.status', $this->soldStatuses())
            ->groupBy('tickets.ticket_type_id')
            ->selectRaw('
                tickets.ticket_type_id,
                COUNT(*) AS sold,
                COALESCE(SUM(order_items.unit_price), 0) AS revenue
            ');

        return DB::table('ticket_types')
            ->leftJoinSub($sold, 'sold', 'sold.ticket_type_id', '=', 'ticket_types.id')
            ->where('ticket_types.organization_id', $organization->getKey())
            ->groupBy('ticket_types.name')
            ->selectRaw('
                ticket_types.name AS name,
                COALESCE(SUM(ticket_types.quantity), 0) AS capacity,
                COALESCE(SUM(sold.sold), 0) AS sold,
                COALESCE(SUM(sold.revenue), 0) AS revenue
            ')
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->name,
                'capacity' => (int) $row->capacity,
                'sold' => (int) $row->sold,
                'revenue' => (int) $row->revenue,
            ])
            ->all();
    }

    /**
     * Statuts comptes comme vendus.
     *
     * Un billet rembourse, annule ou expire ne l'est pas : le compter
     * gonflerait les recettes d'un argent rendu au client.
     *
     * @return list<string>
     */
    private function soldStatuses(): array
    {
        return [TicketStatus::Active->value, TicketStatus::Used->value];
    }

    private function cacheKey(Organization $organization): string
    {
        return 'stats:ticketing:'.$organization->getKey();
    }
}
