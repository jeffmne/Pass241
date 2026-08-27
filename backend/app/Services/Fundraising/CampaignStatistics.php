<?php

namespace App\Services\Fundraising;

use App\Enums\CommissionType;
use App\Enums\DonationStatus;
use App\Models\Campaign;
use App\Services\Commission\CommissionCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Chiffres d'une collecte pour le tableau de bord (section 55).
 *
 * TOUT EST AGREGE EN SQL. Aucune collection de dons n'est chargee, jamais : une
 * campagne qui recoit dix mille dons doit produire les memes quelques lignes de
 * resultat qu'une campagne qui en recoit dix. Compter en PHP marcherait
 * quelques mois, puis ferait tomber l'ecran le jour de la campagne qui marche —
 * c'est-a-dire exactement le jour ou l'organisateur le regarde le plus.
 *
 * UNE SEULE REGLE DE COMPTAGE, et elle explique tous les chiffres : seuls les
 * dons CONFIRMES existent (section 39). Un don en attente est une intention,
 * un don echoue n'a jamais eu lieu.
 *
 * Les frais du prestataire n'apparaissent nulle part : ils ont ete avances par
 * le donateur et n'ont jamais appartenu a l'organisateur (decision D-023).
 *
 * Rien de ce qui est calcule ici n'est stocke (regle 80.1) : l'organisateur ne
 * peut donc pas le modifier, et le tableau de bord affiche exactement ce que la
 * page publique affiche.
 */
class CampaignStatistics
{
    /**
     * Duree de mise en cache.
     *
     * Une minute, comme la billetterie : assez pour qu'un ecran rafraichi en
     * boucle ne reagrege pas a chaque fois, assez court pour qu'un don recu a
     * l'instant apparaisse avant que quelqu'un ne s'inquiete.
     */
    private const CACHE_SECONDS = 60;

    /** Fenetre de l'evolution : un mois se lit d'un coup d'oeil. */
    private const EVOLUTION_DAYS = 30;

    public function __construct(private readonly CommissionCalculator $commissions) {}

    /**
     * @return array{
     *     collected: int, net: int, donations: int, donors: int,
     *     average: int, largest: int, firstAt: Carbon|null, lastAt: Carbon|null
     * }
     */
    public function forCampaign(Campaign $campaign): array
    {
        return Cache::remember(
            $this->cacheKey($campaign),
            self::CACHE_SECONDS,
            fn () => $this->compute($campaign),
        );
    }

    /**
     * Evolution jour par jour, sur un mois glissant (section 55).
     *
     * Les jours sans don sont RENDUS QUAND MEME, a zero : un graphique dont les
     * jours creux disparaissent ecrase le temps et fait lire une progression
     * reguliere la ou il y a eu un pic puis rien.
     *
     * @return Collection<int, array{date: Carbon, amount: int, count: int}>
     */
    public function evolution(Campaign $campaign, int $days = self::EVOLUTION_DAYS): Collection
    {
        $rows = Cache::remember(
            $this->cacheKey($campaign).':evolution:'.$days,
            self::CACHE_SECONDS,
            fn () => $this->computeEvolution($campaign, $days),
        );

        $timezone = config('app.display_timezone');
        $byDay = collect($rows)->keyBy('day');

        return collect(range($days - 1, 0))
            ->map(function (int $ago) use ($byDay, $timezone) {
                $date = now($timezone)->subDays($ago)->startOfDay();
                $row = $byDay->get($date->format('Y-m-d'));

                return [
                    'date' => $date,
                    'amount' => (int) ($row['amount'] ?? 0),
                    'count' => (int) ($row['count'] ?? 0),
                ];
            })
            ->values();
    }

    public function forget(Campaign $campaign): void
    {
        Cache::forget($this->cacheKey($campaign));
        Cache::forget($this->cacheKey($campaign).':evolution:'.self::EVOLUTION_DAYS);
    }

    /**
     * @return array{
     *     collected: int, net: int, donations: int, donors: int,
     *     average: int, largest: int, firstAt: Carbon|null, lastAt: Carbon|null
     * }
     */
    private function compute(Campaign $campaign): array
    {
        /*
         * `subtotal` et non `amount` : ce qui a ete DONNE, pas ce qui a ete
         * debite. Le total debite contient les frais du prestataire.
         */
        $row = DB::table('donations')
            ->where('campaign_id', $campaign->getKey())
            ->where('status', DonationStatus::Confirmed->value)
            ->selectRaw('
                COALESCE(SUM(subtotal), 0) AS collected,
                COUNT(*) AS donations,
                COUNT(DISTINCT payer_phone) AS donors,
                COALESCE(MAX(subtotal), 0) AS largest,
                MIN(confirmed_at) AS first_at,
                MAX(confirmed_at) AS last_at
            ')
            ->first();

        $collected = (int) ($row->collected ?? 0);
        $donations = (int) ($row->donations ?? 0);

        return [
            'collected' => $collected,
            /*
             * Ce que l'organisateur recevra reellement (section 21.2).
             * Afficher le brut seul ferait lire le reversement comme une
             * erreur.
             */
            'net' => $this->commissions->netFor($collected, CommissionType::Donation),
            'donations' => $donations,
            'donors' => (int) ($row->donors ?? 0),
            // Don moyen en arithmetique entiere : les montants sont des entiers
            // XAF (decision D-003), et un don moyen a virgule n'aurait aucun
            // sens dans une monnaie sans centimes.
            'average' => $donations > 0 ? intdiv($collected, $donations) : 0,
            'largest' => (int) ($row->largest ?? 0),
            'firstAt' => $row?->first_at ? Carbon::parse($row->first_at) : null,
            'lastAt' => $row?->last_at ? Carbon::parse($row->last_at) : null,
        ];
    }

    /**
     * @return list<array{day: string, amount: int, count: int}>
     */
    private function computeEvolution(Campaign $campaign, int $days): array
    {
        $timezone = (string) config('app.display_timezone');

        /*
         * Regroupement au fuseau d'AFFICHAGE, pas en UTC (decision D-007). Un
         * don recu a 23h30 a Libreville appartient a ce jour-la pour
         * l'organisateur, pas au lendemain — sans quoi la courbe raconterait
         * une histoire decalee d'un jour une nuit sur deux.
         */
        return DB::table('donations')
            ->where('campaign_id', $campaign->getKey())
            ->where('status', DonationStatus::Confirmed->value)
            ->where('confirmed_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw(
                'to_char(confirmed_at AT TIME ZONE ?, \'YYYY-MM-DD\') AS day,
                 COALESCE(SUM(subtotal), 0) AS amount,
                 COUNT(*) AS count',
                [$timezone]
            )
            ->groupBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'amount' => (int) $row->amount,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    private function cacheKey(Campaign $campaign): string
    {
        return 'campaign-stats:'.$campaign->getKey();
    }
}
