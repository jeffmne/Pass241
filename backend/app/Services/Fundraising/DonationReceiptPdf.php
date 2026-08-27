<?php

namespace App\Services\Fundraising;

use App\Models\Donation;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Recu de don (section 53).
 *
 * « Apres confirmation, generer un recu : reference unique, campagne, montant,
 * date, methode de paiement, identite si applicable. Aucun message personnel ne
 * doit etre inclus. » Le dernier point ne demande aucun effort : il n'existe
 * aucun champ ou un message aurait pu etre saisi (section 42).
 *
 * TROIS REGLES DE CONTENU, reprises du recu de billetterie (decision D-029) et
 * qui valent pour la meme raison :
 *
 * 1. LE BENEFICIAIRE EST L'ORGANISATEUR, pas Pass241. La plateforme encaisse
 *    pour son compte et n'est jamais depositaire des fonds (section 21.2).
 *
 * 2. LA COMMISSION N'Y FIGURE PAS. Elle se joue entre la plateforme et
 *    l'organisateur ; le donateur ne l'a pas payee. L'imprimer lui ferait
 *    croire qu'elle lui a ete prelevee sur son don.
 *
 * 3. LES FRAIS DE PAIEMENT Y FIGURENT, sur leur propre ligne. Le donateur a ete
 *    debite de 10 200 pour un don de 10 000 : sans cette ligne, il ne peut pas
 *    reconcilier avec son relevé mobile money, et un ecart inexplique se lit
 *    comme une tromperie (decision D-023).
 *
 * L'ANONYMAT NE S'APPLIQUE PAS ICI. Il porte sur l'affichage PUBLIC
 * (sections 40, 41) ; ce document est celui du donateur, remis a lui seul par
 * un lien signe. Y ecrire « Donateur anonyme » lui rendrait son propre recu
 * inutilisable — or c'est la piece qu'il joint a une note de frais ou classe
 * dans sa comptabilite.
 *
 * Le document reflete l'etat ACTUEL du don : un remboursement ulterieur y
 * apparait. Un recu qui continuerait a attester un don integral apres
 * remboursement serait faux (section 47).
 */
class DonationReceiptPdf
{
    public function render(Donation $donation): string
    {
        $donation->loadMissing([
            'campaign' => fn ($query) => $query->acrossOrganizations()->with('organization'),
            'refunds' => fn ($query) => $query->acrossOrganizations()->latest(),
        ]);

        return Pdf::loadView('pdf.donation-receipt', [
            'donation' => $donation,
            'campaign' => $donation->campaign,
            'organization' => $donation->campaign?->organization,
            'reference' => $this->reference($donation),
            'refunds' => $donation->refunds,
            'tz' => config('app.display_timezone'),
        ])->setPaper('a4')->output();
    }

    /**
     * Reference du recu, derivee de celle du don.
     *
     * Deterministe et donc stable : le meme recu regenere six mois plus tard
     * porte le meme numero. Unique parce que la reference du don l'est.
     */
    public function reference(Donation $donation): string
    {
        return 'RD-'.mb_substr($donation->reference, 4);
    }

    public function filename(Donation $donation): string
    {
        return 'recu-'.$this->reference($donation).'.pdf';
    }
}
