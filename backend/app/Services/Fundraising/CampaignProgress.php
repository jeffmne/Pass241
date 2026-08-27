<?php

namespace App\Services\Fundraising;

use App\Enums\DonationStatus;
use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

/**
 * Progression d'une campagne (sections 37, 38, 65).
 *
 * SEUL POINT DE CALCUL du montant collecte, du pourcentage et du nombre de
 * dons. Rien de tout cela n'est stocke : la regle 80.1 interdit qu'un
 * organisateur puisse saisir ces valeurs, et la facon la plus sure de tenir
 * cette regle est qu'il n'existe aucune colonne a saisir.
 *
 * Le frontend n'est jamais la source de verite (sections 49, 50) : une requete
 * qui enverrait `collectedAmount` n'a aucun champ ou atterrir.
 *
 * L'agregation se fait en SQL, sur les dons CONFIRMES uniquement (section 39).
 * Un don en attente n'est pas de l'argent recu : le compter ferait mentir la
 * page publique a chaque paiement abandonne.
 */
class CampaignProgress
{
    /**
     * @return array{
     *     goal: int, collected: int, donations: int, donors: int,
     *     percentage: float, displayPercentage: float, remaining: int
     * }
     */
    public function forCampaign(Campaign $campaign): array
    {
        $goal = max(0, (int) $campaign->goal_amount);

        /*
         * `subtotal` et non `amount` : le montant collecte est ce qui a ete
         * DONNE, pas ce qui a ete debite. Le total debite contient les frais du
         * prestataire, avances par le donateur pour que la cause recoive le
         * montant annonce (decision D-023) — les afficher gonflerait la
         * progression d'argent que la campagne ne recevra jamais.
         *
         * Aucune portee par organisation n'est appliquee, et il n'y a pas de
         * fuite possible : la requete est bornee a UNE campagne, dont
         * l'identifiant vient du serveur. La page publique est lue par des
         * visiteurs qui n'appartiennent a aucune organisation.
         *
         * Les remboursements viendront se deduire ici (section 47), au jalon
         * ou ils existeront — au meme endroit, sans que la page change.
         */
        $row = DB::table('donations')
            ->where('campaign_id', $campaign->getKey())
            ->where('status', DonationStatus::Confirmed->value)
            ->selectRaw('
                COALESCE(SUM(subtotal), 0) AS collected,
                COUNT(*) AS donations,
                COUNT(DISTINCT payer_phone) AS donors
            ')
            ->first();

        /*
         * Section 47 : les remboursements se DEDUISENT du montant collecte.
         *
         * « collectedAmount = SUM(donations confirmees) − SUM(remboursements
         * lies). » Une seconde agregation plutot qu'une jointure : un don peut
         * porter plusieurs remboursements partiels, et joindre les deux tables
         * multiplierait les lignes de dons avant de les sommer.
         */
        $refunded = (int) DB::table('donation_refunds')
            ->join('donations', 'donations.id', '=', 'donation_refunds.donation_id')
            ->where('donations.campaign_id', $campaign->getKey())
            ->sum('donation_refunds.amount');

        $collected = max(0, (int) ($row->collected ?? 0) - $refunded);
        $donations = (int) ($row->donations ?? 0);
        // Par numero de telephone : c'est la seule identite dont on dispose a
        // coup sur, le donateur n'ayant pas de compte. Deux dons du meme
        // numero, c'est un donateur.
        $donors = (int) ($row->donors ?? 0);

        // Cast explicite : une division PHP rend un ENTIER quand elle tombe
        // juste, et le type declare de ce tableau serait faux une fois sur dix.
        $percentage = $goal > 0 ? (float) ($collected / $goal * 100) : 0.0;

        return [
            'goal' => $goal,
            'collected' => $collected,
            'donations' => $donations,
            'donors' => $donors,
            // Valeur reelle, conservee telle quelle cote serveur (section 65).
            'percentage' => $percentage,
            // Valeur d'affichage, bornee a 100 % : une barre de progression qui
            // deborde se lit comme un bug, alors qu'un objectif depasse est une
            // bonne nouvelle. Le chiffre reel reste au-dessus.
            'displayPercentage' => min(100.0, $percentage),
            'remaining' => max(0, $goal - $collected),
        ];
    }
}
