<?php

namespace App\Services\Settlement;

use App\Enums\CommissionType;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Services\Payments\PaymentManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ce qui revient a l'organisateur pour un evenement (section 21.2).
 *
 * Les montants viennent EXCLUSIVEMENT des lignes de commission deja figees,
 * jamais d'un recalcul a partir des prix ni d'une somme de commandes. Deux
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
 * ete avances par l'acheteur (decision D-023) et ne transitent pas par
 * l'organisateur.
 */
class SettlementLedger
{
    public function __construct(private readonly PaymentManager $payments) {}

    /**
     * Brut, commission et net d'un evenement.
     *
     * @return array{gross: int, commission: int, net: int, orders: int}
     */
    public function totalsFor(Event $event): array
    {
        /*
         * Les lignes d'origine et les ajustements de remboursement partagent
         * la meme transaction depuis la decision D-027. Un ajustement se
         * SOUSTRAIT : c'est de l'argent rendu au client, qui ne doit pas
         * partir aussi chez l'organisateur. Les additionner sans distinguer
         * les deux reverserait des sommes deja remboursees.
         *
         * Les commandes PARTIELLEMENT remboursees restent dans le calcul,
         * amputees de la part rendue. Les exclure entierement priverait
         * l'organisateur des billets non rembourses de la meme commande.
         */
        $sign = 'CASE WHEN commissions.parent_commission_id IS NULL THEN 1 ELSE -1 END';

        $row = DB::table('commissions')
            ->join('orders', function ($join) {
                $join->on('commissions.commissionable_id', '=', 'orders.id')
                    ->where('commissions.commissionable_type', '=', (new Order)->getMorphClass());
            })
            ->where('orders.event_id', $event->getKey())
            // Une commande en echec ou annulee n'a rien encaisse : la reverser
            // creerait de l'argent qui n'existe pas (regle 80.1, point 2).
            ->whereIn('orders.status', [
                OrderStatus::Paid->value,
                OrderStatus::PartiallyRefunded->value,
                OrderStatus::Refunded->value,
            ])
            ->where('commissions.type', CommissionType::Ticketing->value)
            ->selectRaw("
                COALESCE(SUM({$sign} * commissions.gross_amount), 0) AS gross,
                COALESCE(SUM({$sign} * commissions.commission_amount), 0) AS commission,
                COALESCE(SUM({$sign} * commissions.net_amount), 0) AS net,
                COUNT(*) FILTER (WHERE commissions.parent_commission_id IS NULL) AS orders
            ")
            ->first();

        return [
            'gross' => (int) ($row->gross ?? 0),
            'commission' => (int) ($row->commission ?? 0),
            'net' => (int) ($row->net ?? 0),
            'orders' => (int) ($row->orders ?? 0),
        ];
    }

    /**
     * Date a laquelle les fonds doivent arriver chez l'organisateur.
     *
     * Apres l'evenement, plus un delai de securite. La fenetre de
     * remboursement se ferme 48h AVANT l'evenement (section 24.1) : reverser
     * apres garantit qu'aucun remboursement n'exigera de recuperer des fonds
     * deja partis.
     */
    public function releaseDateFor(Event $event): CarbonInterface
    {
        return $event->ends_at->copy()->addHours(
            (int) config('payments.payouts.delay_hours')
        );
    }

    /**
     * Date a laquelle l'ORDRE peut etre transmis au prestataire.
     *
     * A distinguer de la date de liberation, et c'est tout l'interet de la
     * capacite `scheduledPayout` :
     *
     * - le prestataire sait programmer un virement : l'ordre part des la fin
     *   de l'evenement, avec une date d'execution future. Les fonds restent
     *   dans SES livres jusque-la, et Pass241 n'en est jamais depositaire
     *   (section 21.2) ;
     * - il ne sait pas : l'ordre attend l'echeance, et les fonds dorment
     *   entre-temps sur le compte marchand de la plateforme. C'est
     *   precisement ce que `PaymentCapabilities::holdsFunds()` signale.
     */
    public function instructionDueAt(Event $event): CarbonInterface
    {
        if ($this->payments->capabilities()->scheduledPayout) {
            return $event->ends_at->copy();
        }

        return $this->releaseDateFor($event);
    }

    /** Delai a attendre apres la fin avant de transmettre l'ordre, en heures. */
    public function instructionDelayHours(): int
    {
        return $this->payments->capabilities()->scheduledPayout
            ? 0
            : (int) config('payments.payouts.delay_hours');
    }

    public function isDue(Event $event): bool
    {
        return $this->instructionDueAt($event)->isPast();
    }
}
