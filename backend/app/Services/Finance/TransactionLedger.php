<?php

namespace App\Services\Finance;

use App\Enums\CommissionType;
use App\Models\Commission;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le grand livre des transactions de l'organisation (sections 56, 59).
 *
 * CE SERVICE EXISTE POUR UNE SEULE RAISON : LA REGLE DE SIGNE.
 *
 * La table `commissions` est en ajout seul (section 46). Un remboursement n'y
 * corrige donc pas la ligne d'origine, il ajoute une ligne d'AJUSTEMENT qui dit
 * ce qui a ete rendu — et cette ligne porte un montant POSITIF, parce qu'elle
 * decrit un mouvement, pas un solde.
 *
 * D'ou le piege : `Commission::sum('net_amount')` compte l'encaissement PUIS le
 * remboursement comme deux recettes. Un organisateur qui a encaisse 10 000 F et
 * rembourse la totalite lirait 20 000 F de chiffre d'affaires. Le scope
 * `originals()` evite l'erreur inverse — ne jamais deduire les remboursements —
 * mais aucun des deux ne donne le chiffre juste a lui seul.
 *
 * Le chiffre juste est : ORIGINES MOINS AJUSTEMENTS. Il est ecrit ici, une fois,
 * et l'ecran comme l'export le lisent au meme endroit. Les laisser recalculer
 * chacun de son cote aurait produit tot ou tard un export qui contredit
 * l'ecran — et c'est l'export qu'on garde.
 *
 * L'ISOLATION MULTI-TENANT VIENT DU MODELE (`BelongsToOrganization`), jamais
 * d'un filtre pose ici : aucune methode ne leve la portee (regle 80.4).
 */
class TransactionLedger
{
    /**
     * Periodes proposees, de la plus courante a la plus large.
     *
     * Des bornes libres auraient demande deux champs de date, leur validation
     * et leur report dans l'URL de l'export, pour un besoin que ces trois
     * choix couvrent : « ce mois-ci », « cette annee », « depuis le debut ».
     */
    public const PERIODS = [
        '30j' => '30 derniers jours',
        'annee' => 'Cette annee',
        'tout' => 'Tout',
    ];

    public const DEFAULT_PERIOD = '30j';

    /**
     * Les lignes du grand livre, origines ET ajustements.
     *
     * Les deux figurent : l'organisateur doit lire ce qui a ete preleve, puis
     * ce qui a ete rendu. N'afficher que le solde net cacherait le
     * remboursement, c'est-a-dire precisement ce qu'il cherche quand un chiffre
     * le surprend.
     *
     * @return Builder<Commission>
     */
    public function query(?CommissionType $type = null, string $period = self::DEFAULT_PERIOD): Builder
    {
        return Commission::query()
            ->when($type, fn (Builder $query) => $query->where('type', $type->value))
            ->when($this->since($period), fn (Builder $query, CarbonInterface $since) => $query
                ->where('created_at', '>=', $since));
    }

    /**
     * Les totaux de la periode, ajustements deduits.
     *
     * Une seule requete, et la soustraction faite par la base plutot que par
     * deux agregats additionnes en PHP : le `CASE` ci-dessous EST la regle de
     * signe, et la garder d'un seul tenant empeche qu'une moitie soit corrigee
     * sans l'autre.
     *
     * `transactions` compte les origines seules — un remboursement n'est pas
     * une vente de plus. `refunded` isole ce qui est reparti, pour que l'ecran
     * puisse l'annoncer au lieu de laisser deviner l'ecart.
     *
     * @return array{gross:int, commission:int, net:int, transactions:int, refunded:int}
     */
    public function totals(?CommissionType $type = null, string $period = self::DEFAULT_PERIOD): array
    {
        $signed = fn (string $column) => sprintf(
            'COALESCE(SUM(CASE WHEN parent_commission_id IS NULL THEN %1$s ELSE -%1$s END), 0)',
            $column,
        );

        $row = $this->query($type, $period)
            ->toBase()
            ->selectRaw(implode(', ', [
                $signed('gross_amount').' AS gross',
                $signed('commission_amount').' AS commission',
                $signed('net_amount').' AS net',
                'COUNT(*) FILTER (WHERE parent_commission_id IS NULL) AS transactions',
                'COALESCE(SUM(CASE WHEN parent_commission_id IS NULL THEN 0 ELSE gross_amount END), 0) AS refunded',
            ]))
            ->first();

        // PostgreSQL rend les agregats en chaine : sans ces conversions, les
        // montants repartiraient en flottants a la premiere addition PHP
        // (decision D-003 — les francs CFA restent des entiers de bout en bout).
        return [
            'gross' => (int) ($row->gross ?? 0),
            'commission' => (int) ($row->commission ?? 0),
            'net' => (int) ($row->net ?? 0),
            'transactions' => (int) ($row->transactions ?? 0),
            'refunded' => (int) ($row->refunded ?? 0),
        ];
    }

    /** Debut de la periode, ou null quand elle n'en a pas. */
    public function since(string $period): ?CarbonInterface
    {
        $tz = config('app.display_timezone');

        return match ($period) {
            '30j' => now()->subDays(30),
            // Dans le fuseau d'affichage : « cette annee » commence au 1er
            // janvier a Libreville, pas a Londres.
            'annee' => now()->timezone($tz)->startOfYear(),
            default => null,
        };
    }

    /** Libelle de la periode, pour l'ecran, le fichier et le journal. */
    public function periodLabel(string $period): string
    {
        return self::PERIODS[$period] ?? self::PERIODS[self::DEFAULT_PERIOD];
    }

    /** Normalise ce qui arrive de l'URL : un parametre invente retombe ici. */
    public function normalizePeriod(?string $period): string
    {
        return isset(self::PERIODS[$period]) ? $period : self::DEFAULT_PERIOD;
    }
}
