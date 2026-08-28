<?php

namespace App\Services\Statistics;

use App\Enums\CommissionType;
use App\Enums\OrderOrigin;
use App\Enums\TicketStatus;
use App\Models\Organization;
use App\Services\Commission\CommissionCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
     *     sold: int, invited: int, revenue: int, net: int, capacity: int,
     *     remaining: int, entries: int, attendanceRate: float|null, hasEntries: bool
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
     *     name: string, sold: int, invited: int, capacity: int,
     *     remaining: int, revenue: int, share: float
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
                // Les invitations prennent des places : les ignorer ici
                // annoncerait comme disponibles des sieges deja attribues.
                $row['remaining'] = max(0, $row['capacity'] - $row['sold'] - $row['invited']);
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

    /**
     * Ventes des N derniers jours, un point par jour.
     *
     * Sert l'histogramme du tableau de bord. Trois partis pris :
     *
     * 1. LA SERIE EST CONTINUE. Les jours sans vente sont ramenes a zero en
     *    PHP plutot que d'etre absents. Un graphique qui saute les jours creux
     *    ment sur le rythme : trois ventes le lundi et trois le vendredi se
     *    liraient comme deux jours consecutifs.
     *
     * 2. LA DATE EST CELLE DU PAIEMENT, pas celle de la creation de la
     *    commande. Une commande creee a 23h55 et payee a 00h10 appartient au
     *    lendemain — c'est ce jour-la que l'argent est entre.
     *
     * 3. LE DECOUPAGE SE FAIT DANS LE FUSEAU D'AFFICHAGE. Les horodatages sont
     *    stockes en UTC ; regrouper sans conversion decalerait chaque journee
     *    d'une heure a Libreville, et rangerait toutes les ventes faites entre
     *    minuit et 1h dans la veille.
     *
     * @return list<array{label: string, value: int, display: string, date: string}>
     */
    public function dailySales(Organization $organization, int $days = 7): array
    {
        $rows = Cache::remember(
            $this->cacheKey($organization).':daily:'.$days,
            self::CACHE_SECONDS,
            fn () => $this->computeDailySales($organization, $days),
        );

        $timezone = config('app.display_timezone');
        $series = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = CarbonImmutable::now($timezone)->subDays($offset);
            $key = $day->format('Y-m-d');
            $count = $rows[$key] ?? 0;

            $series[] = [
                'date' => $key,
                // Initiale majuscule : `translatedFormat` rend « lun. » en
                // francais, et une rangee de minuscules sous un graphique se
                // lit mal.
                'label' => ucfirst(rtrim($day->translatedFormat('D'), '.')),
                'value' => $count,
                'display' => $day->translatedFormat('j F').' : '
                    .$count.' '.($count === 1 ? 'billet vendu' : 'billets vendus'),
            ];
        }

        return $series;
    }

    /**
     * Recettes des N derniers mois, un point par mois.
     *
     * Meme continuite que la serie quotidienne, et pour la meme raison : un
     * mois sans recette fait partie de l'histoire que raconte la courbe.
     *
     * @return list<array{label: string, value: int, display: string, month: string}>
     */
    public function monthlyRevenue(Organization $organization, int $months = 6): array
    {
        $rows = Cache::remember(
            $this->cacheKey($organization).':monthly:'.$months,
            self::CACHE_SECONDS,
            fn () => $this->computeMonthlyRevenue($organization, $months),
        );

        $timezone = config('app.display_timezone');
        $series = [];

        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $month = CarbonImmutable::now($timezone)->startOfMonth()->subMonths($offset);
            $key = $month->format('Y-m');
            $amount = $rows[$key] ?? 0;

            $series[] = [
                'month' => $key,
                'label' => ucfirst(rtrim($month->translatedFormat('M'), '.')),
                'value' => $amount,
                'display' => ucfirst($month->translatedFormat('F Y')).' : '.Money::format($amount),
            ];
        }

        return $series;
    }

    /** Vide le cache d'une organisation. */
    public function forget(Organization $organization): void
    {
        Cache::forget($this->cacheKey($organization));
        Cache::forget($this->cacheKey($organization).':types');

        /*
         * Les series temporelles sont mises en cache par longueur de fenetre.
         * Les longueurs employees par les ecrans sont enumerees ici plutot que
         * devinees : une cle oubliee laisserait un graphique perime a l'ecran
         * apres une vente, ce qui est precisement le moment ou on le regarde.
         */
        foreach ([7, 30] as $days) {
            Cache::forget($this->cacheKey($organization).':daily:'.$days);
        }

        foreach ([6, 12] as $months) {
            Cache::forget($this->cacheKey($organization).':monthly:'.$months);
        }
    }

    /**
     * @return array{
     *     sold: int, invited: int, revenue: int, net: int, capacity: int,
     *     remaining: int, entries: int, attendanceRate: float|null, hasEntries: bool
     * }
     */
    private function compute(Organization $organization): array
    {
        /*
         * Les invitations sont comptees A PART (section 25, decision D-039).
         *
         * Elles produisent de vrais billets, occupent de vraies places et
         * franchissent la porte comme les autres — mais ce ne sont pas des
         * ventes. Les additionner ferait lire a un organisateur qui invite
         * cinquante personnes un chiffre de ventes qui n'a rien rapporte.
         */
        $sales = DB::table('tickets')
            ->join('order_items', 'order_items.id', '=', 'tickets.order_item_id')
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->where('tickets.organization_id', $organization->getKey())
            ->whereIn('tickets.status', $this->soldStatuses())
            ->selectRaw('
                COUNT(*) FILTER (WHERE orders.origin = ?) AS sold,
                COUNT(*) FILTER (WHERE orders.origin = ?) AS invited,
                COALESCE(SUM(order_items.unit_price), 0) AS revenue,
                COUNT(*) FILTER (WHERE tickets.status = ?) AS entries
            ', [
                OrderOrigin::Purchase->value,
                OrderOrigin::Invitation->value,
                TicketStatus::Used->value,
            ])
            ->first();

        $capacity = (int) DB::table('ticket_types')
            ->where('organization_id', $organization->getKey())
            ->sum('quantity');

        $sold = (int) ($sales->sold ?? 0);
        $invited = (int) ($sales->invited ?? 0);
        $revenue = (int) ($sales->revenue ?? 0);
        $entries = (int) ($sales->entries ?? 0);

        // Places prises, quelle qu'en soit la raison : c'est ce qui determine
        // ce qu'il reste a vendre, et le denominateur du taux de presence.
        $issued = $sold + $invited;

        return [
            'sold' => $sold,
            'invited' => $invited,
            'revenue' => $revenue,
            // Ce que l'organisateur recevra reellement. Afficher la recette
            // brute seule ferait lire le reversement comme une erreur
            // (section 21.2).
            'net' => $this->commissions->netFor($revenue, CommissionType::Ticketing),
            'capacity' => $capacity,
            'remaining' => max(0, $capacity - $issued),
            'entries' => $entries,
            'attendanceRate' => $issued > 0 ? $entries / $issued * 100 : null,
            // Tant qu'aucun billet n'a ete scanne, annoncer « 0 entree » et
            // « 0 % de presence » ne renseigne sur rien et se lit comme un
            // echec. Les deux apparaissent au premier scan.
            'hasEntries' => $entries > 0,
        ];
    }

    /**
     * Billets vendus par jour, indexes sur `Y-m-d` dans le fuseau d'affichage.
     *
     * `AT TIME ZONE 'UTC' AT TIME ZONE ?` n'est pas une redondance : la
     * premiere clause dit a Postgres comment INTERPRETER l'horodatage stocke
     * (nos colonnes sont sans fuseau, et Laravel y ecrit de l'UTC), la seconde
     * le CONVERTIT vers le fuseau d'affichage. Sans la premiere, Postgres
     * appliquerait le fuseau de la session, qui depend de la configuration du
     * serveur — le graphique changerait alors selon la machine.
     *
     * @return array<string, int>
     */
    private function computeDailySales(Organization $organization, int $days): array
    {
        $timezone = config('app.display_timezone');

        // Depuis le debut du plus ancien jour affiche : borner en SQL evite de
        // parcourir tout l'historique de l'organisation a chaque affichage.
        $since = CarbonImmutable::now($timezone)->subDays($days - 1)->startOfDay()->utc();

        return DB::table('tickets')
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->where('tickets.organization_id', $organization->getKey())
            ->whereIn('tickets.status', $this->soldStatuses())
            // Les invitations ne sont pas des ventes (section 25) : les
            // inclure ferait apparaitre un pic le jour ou l'organisateur a
            // invite cinquante personnes, sans qu'un franc soit entre.
            ->where('orders.origin', OrderOrigin::Purchase->value)
            ->whereNotNull('orders.paid_at')
            ->where('orders.paid_at', '>=', $since)
            /*
             * On regroupe sur l'ALIAS DE SORTIE, pas sur une copie de
             * l'expression.
             *
             * Repeter l'expression dans le `GROUP BY` semble equivalent, mais
             * ne l'est pas : chaque `?` devient un parametre DISTINCT
             * (`$1` dans le select, `$2` dans le group by), et Postgres refuse
             * alors de considerer les deux expressions comme identiques —
             * « column orders.paid_at must appear in the GROUP BY clause ».
             *
             * L'alias supprime la duplication et le doute d'un seul coup.
             */
            ->groupByRaw('bucket')
            ->selectRaw(
                "(orders.paid_at AT TIME ZONE 'UTC' AT TIME ZONE ?)::date AS bucket, COUNT(*) AS total",
                [$timezone]
            )
            ->get()
            ->mapWithKeys(fn ($row) => [
                CarbonImmutable::parse($row->bucket)->format('Y-m-d') => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Recettes par mois, indexees sur `Y-m` dans le fuseau d'affichage.
     *
     * La recette est le prix REELLEMENT PAYE, releve sur la ligne de commande —
     * jamais le tarif courant du type, qui a pu changer depuis l'achat. C'est
     * la meme regle que pour les chiffres consolides, et elle doit le rester :
     * une courbe qui ne totalise pas la carte du dessus fait douter des deux.
     *
     * @return array<string, int>
     */
    private function computeMonthlyRevenue(Organization $organization, int $months): array
    {
        $timezone = config('app.display_timezone');

        $since = CarbonImmutable::now($timezone)->startOfMonth()->subMonths($months - 1)->utc();

        return DB::table('tickets')
            ->join('order_items', 'order_items.id', '=', 'tickets.order_item_id')
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->where('tickets.organization_id', $organization->getKey())
            ->whereIn('tickets.status', $this->soldStatuses())
            ->where('orders.origin', OrderOrigin::Purchase->value)
            ->whereNotNull('orders.paid_at')
            ->where('orders.paid_at', '>=', $since)
            // Meme raison qu'au-dessus : on regroupe sur l'alias de sortie.
            ->groupByRaw('bucket')
            ->selectRaw(
                "date_trunc('month', orders.paid_at AT TIME ZONE 'UTC' AT TIME ZONE ?) AS bucket,
                 COALESCE(SUM(order_items.unit_price), 0) AS revenue",
                [$timezone]
            )
            ->get()
            ->mapWithKeys(fn ($row) => [
                CarbonImmutable::parse($row->bucket)->format('Y-m') => (int) $row->revenue,
            ])
            ->all();
    }

    /**
     * @return list<array{name: string, sold: int, invited: int, capacity: int, revenue: int}>
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
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->whereIn('tickets.status', $this->soldStatuses())
            ->groupBy('tickets.ticket_type_id')
            ->selectRaw('
                tickets.ticket_type_id,
                COUNT(*) FILTER (WHERE orders.origin = ?) AS sold,
                COUNT(*) FILTER (WHERE orders.origin = ?) AS invited,
                COALESCE(SUM(order_items.unit_price), 0) AS revenue
            ', [OrderOrigin::Purchase->value, OrderOrigin::Invitation->value]);

        return DB::table('ticket_types')
            ->leftJoinSub($sold, 'sold', 'sold.ticket_type_id', '=', 'ticket_types.id')
            ->where('ticket_types.organization_id', $organization->getKey())
            ->groupBy('ticket_types.name')
            ->selectRaw('
                ticket_types.name AS name,
                COALESCE(SUM(ticket_types.quantity), 0) AS capacity,
                COALESCE(SUM(sold.sold), 0) AS sold,
                COALESCE(SUM(sold.invited), 0) AS invited,
                COALESCE(SUM(sold.revenue), 0) AS revenue
            ')
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->name,
                'capacity' => (int) $row->capacity,
                'sold' => (int) $row->sold,
                'invited' => (int) $row->invited,
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
