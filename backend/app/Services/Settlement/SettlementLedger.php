<?php

namespace App\Services\Settlement;

use App\Services\Payments\PaymentManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ce qui revient a l'organisateur (section 21.2).
 *
 * Les montants viennent EXCLUSIVEMENT des lignes de commission deja figees,
 * jamais d'un recalcul a partir des prix ni d'une somme de transactions. Deux
 * raisons, et la seconde est la plus importante :
 *
 * 1. la commission porte un instantane de son taux. Recalculer six mois plus
 *    tard avec le taux du jour donnerait un chiffre different de celui qui a
 *    ete annonce a l'organisateur ;
 * 2. c'est de l'argent qui sort. Le montant vire doit provenir d'une donnee
 *    ecrite une fois et jamais modifiee — la table des commissions est en
 *    ajout seul, garantie par declencheur.
 *
 * Les frais du prestataire n'apparaissent pas ici, et c'est normal : ils ont
 * ete avances par le payeur (decision D-023) et ne transitent pas par
 * l'organisateur.
 *
 * Depuis la decision D-041, ce grand livre ne sait plus s'il compte des billets
 * ou des dons : le `Settlable` dit quelles commissions sont les siennes, la
 * regle de calcul ne change pas.
 */
class SettlementLedger
{
    public function __construct(private readonly PaymentManager $payments) {}

    /**
     * Brut, commission et net.
     *
     * @return array{gross: int, commission: int, net: int, transactions: int}
     */
    public function totalsFor(Settlable $settlable): array
    {
        /*
         * Les lignes d'origine et les ajustements de remboursement partagent la
         * meme transaction (decision D-027). Un ajustement se SOUSTRAIT : c'est
         * de l'argent rendu au payeur, qui ne doit pas partir aussi chez
         * l'organisateur. Les additionner sans distinguer les deux reverserait
         * des sommes deja remboursees.
         */
        $sign = 'CASE WHEN commissions.parent_commission_id IS NULL THEN 1 ELSE -1 END';

        $query = DB::table('commissions')
            ->where('commissions.type', $settlable->settlementCommissionType()->value);

        // Le perimetre vient du module ; le calcul reste ici.
        $settlable->constrainCommissions($query);

        $row = $query->selectRaw("
                COALESCE(SUM({$sign} * commissions.gross_amount), 0) AS gross,
                COALESCE(SUM({$sign} * commissions.commission_amount), 0) AS commission,
                COALESCE(SUM({$sign} * commissions.net_amount), 0) AS net,
                COUNT(*) FILTER (WHERE commissions.parent_commission_id IS NULL) AS transactions
            ")
            ->first();

        return [
            'gross' => (int) ($row->gross ?? 0),
            'commission' => (int) ($row->commission ?? 0),
            'net' => (int) ($row->net ?? 0),
            'transactions' => (int) ($row->transactions ?? 0),
        ];
    }

    /**
     * Date a laquelle les fonds doivent arriver chez l'organisateur.
     *
     * Apres la fin de l'activite, plus un delai de securite. Pour un evenement,
     * la fenetre de remboursement se ferme 48h avant (section 24.1) : reverser
     * apres garantit qu'aucun remboursement n'exigera de recuperer des fonds
     * deja partis.
     */
    public function releaseDateFor(Settlable $settlable): ?CarbonInterface
    {
        $endsAt = $settlable->settlementEndsAt();

        return $endsAt?->copy()->addHours((int) config('payments.payouts.delay_hours'));
    }

    /**
     * Date a laquelle l'ORDRE peut etre transmis au prestataire.
     *
     * A distinguer de la date de liberation, et c'est tout l'interet de la
     * capacite `scheduledPayout` :
     *
     * - le prestataire sait programmer un virement : l'ordre part des la fin de
     *   l'activite, avec une date d'execution future. Les fonds restent dans
     *   SES livres jusque-la, et Pass241 n'en est jamais depositaire
     *   (section 21.2) ;
     * - il ne sait pas : l'ordre attend l'echeance, et les fonds dorment
     *   entre-temps sur le compte marchand de la plateforme. C'est precisement
     *   ce que `PaymentCapabilities::holdsFunds()` signale.
     */
    public function instructionDueAt(Settlable $settlable): ?CarbonInterface
    {
        $endsAt = $settlable->settlementEndsAt();

        if ($endsAt === null) {
            return null;
        }

        if ($this->payments->capabilities()->scheduledPayout) {
            return $endsAt->copy();
        }

        return $this->releaseDateFor($settlable);
    }

    /** Delai a attendre apres la fin avant de transmettre l'ordre, en heures. */
    public function instructionDelayHours(): int
    {
        return $this->payments->capabilities()->scheduledPayout
            ? 0
            : (int) config('payments.payouts.delay_hours');
    }

    /**
     * L'activite est-elle terminee depuis assez longtemps pour reverser ?
     *
     * Faux tant qu'elle continue : une collecte encore ouverte peut recevoir un
     * don a la minute suivante, et la decouper en tranches produirait des
     * virements multiples la ou un seul est prevu.
     */
    public function isDue(Settlable $settlable): bool
    {
        return $this->instructionDueAt($settlable)?->isPast() ?? false;
    }
}
