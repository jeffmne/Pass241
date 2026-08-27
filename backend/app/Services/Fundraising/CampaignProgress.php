<?php

namespace App\Services\Fundraising;

use App\Models\Campaign;

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
 * ETAT AU JALON 7.1 : la table des dons n'existe pas encore, les valeurs sont
 * donc nulles — ce qui est la reponse exacte, et non un bouchon : une campagne
 * ne peut aujourd'hui avoir recu aucun don confirme. L'agregation SQL arrive
 * avec les dons (jalon 7.2), au meme endroit, sans que la page publique ni le
 * tableau de bord n'aient a changer.
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

        // Section 39 : seuls les dons CONFIRMES comptent, diminues de leurs
        // remboursements (section 47). Zero don confirme aujourd'hui.
        $collected = 0;
        $donations = 0;
        $donors = 0;

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
