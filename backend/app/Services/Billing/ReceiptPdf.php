<?php

namespace App\Services\Billing;

use App\Enums\RefundStatus;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Recu de paiement (section 53 adaptee a la billetterie, decision D-029).
 *
 * Reprend les champs imposes pour le recu de don : reference unique, objet,
 * montant, date, methode de paiement, identite. C'est un document comptable,
 * pas un billet — d'ou l'absence d'affiche, de QR et de couleur : ce qui
 * compte ici est qu'un montant se retrouve sur un relevé mobile money.
 *
 * TROIS REGLES DE CONTENU, chacune pour une raison precise :
 *
 * 1. LE VENDEUR EST L'ORGANISATEUR, pas Pass241. La plateforme encaisse pour
 *    son compte et n'est jamais depositaire des fonds (section 21.2). Un recu
 *    qui presenterait Pass241 comme vendeur dirait le contraire de ce que le
 *    produit est.
 *
 * 2. LA COMMISSION N'Y FIGURE PAS. Elle se joue entre la plateforme et
 *    l'organisateur ; l'acheteur ne l'a pas payee. L'imprimer lui ferait
 *    croire qu'elle lui a ete prelevee.
 *
 * 3. LES FRAIS DE PAIEMENT Y FIGURENT, sur leur propre ligne. L'acheteur a ete
 *    debite de 10 200 pour un billet a 10 000 : sans cette ligne, il ne peut
 *    pas reconcilier avec son relevé, et un ecart inexplique se lit comme une
 *    tromperie (decision D-023).
 *
 * Le document reflete l'etat ACTUEL de la commande : un remboursement
 * ulterieur y apparait. Un recu qui continuerait a attester un paiement
 * integral apres remboursement serait faux.
 */
class ReceiptPdf
{
    public function render(Order $order): string
    {
        $order->loadMissing(Order::publicRelations());

        return Pdf::loadView('pdf.receipt', [
            'order' => $order,
            'event' => $order->event,
            'organization' => $order->event?->organization,
            'reference' => $this->reference($order),
            // Seuls les remboursements ABOUTIS apparaissent : une demande en
            // cours n'a rien change au montant recu.
            'refunds' => $order->refundRequests()
                ->acrossOrganizations()
                ->where('status', RefundStatus::Approved->value)
                ->latest()
                ->get(),
            'tz' => config('app.display_timezone'),
        ])->setPaper('a4')->output();
    }

    /**
     * Reference du recu, derivee de celle de la commande.
     *
     * Deterministe et donc stable : le meme recu regenere six mois plus tard
     * porte le meme numero. Une sequence stockee aurait le meme effet au prix
     * d'une colonne et d'un risque de trou dans la numerotation.
     *
     * Unique parce que la reference de commande l'est.
     */
    public function reference(Order $order): string
    {
        return 'RC-'.mb_substr($order->reference, 6);
    }

    public function filename(Order $order): string
    {
        return 'recu-'.$this->reference($order).'.pdf';
    }
}
