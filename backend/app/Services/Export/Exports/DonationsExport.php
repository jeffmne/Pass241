<?php

namespace App\Services\Export\Exports;

use App\Enums\PermissionName;
use App\Models\Campaign;
use App\Models\Donation;
use App\Services\Export\Exportable;
use Generator;

/**
 * Dons d'une collecte (sections 41, 43, 59).
 *
 * L'ANONYMAT TIENT DANS LE FICHIER AUSSI, et c'est le point le plus important
 * de cet export.
 *
 * La decision D-044 pose que l'organisateur ne voit jamais l'identite d'un
 * donateur anonyme — pas plus a l'ecran qu'ailleurs. Un export qui la
 * livrerait serait la porte de sortie de cette regle : un fichier se garde, se
 * transfere, s'ouvre dans un tableur. Ce serait meme pire qu'un affichage,
 * parce que ca survit a la session.
 *
 * Le fichier reprend donc exactement ce que la page publique montre pour
 * l'identite — `publicDonorName()`, un seul point de verite — et ce que le
 * tableau de bord montre pour les montants.
 *
 * LE NUMERO DE TELEPHONE N'Y FIGURE PAS NON PLUS. Il identifie le donateur
 * aussi surement qu'un nom ; l'exporter reviendrait a rendre l'anonymat
 * cosmetique. La plateforme le conserve pour le paiement, l'audit et le
 * rapprochement financier (section 41) — pas pour le transmettre.
 */
class DonationsExport implements Exportable
{
    public function __construct(private readonly Campaign $campaign) {}

    public function label(): string
    {
        return 'Dons';
    }

    public function filename(): string
    {
        return 'dons-'.$this->campaign->slug.'-'.now()->format('Y-m-d');
    }

    public function headings(): array
    {
        return [
            'Reference',
            'Date',
            'Donateur',
            'Don',
            'Rembourse',
            'Net',
            'Frais de paiement',
            'Total debite',
            'Operateur',
            'Statut',
        ];
    }

    public function rows(): Generator
    {
        $tz = config('app.display_timezone');

        $donations = Donation::query()
            ->where('campaign_id', $this->campaign->getKey())
            ->withSum('refunds', 'amount')
            ->orderBy('id')
            ->lazyById(500);

        foreach ($donations as $donation) {
            yield [
                $donation->reference,
                $donation->confirmed_at?->timezone($tz)->format('Y-m-d H:i'),
                // Jamais `donor_name` : c'est `publicDonorName()` qui applique
                // le choix du donateur, ici comme partout ailleurs.
                $donation->publicDonorName(),
                $donation->subtotal,
                $donation->refundedAmount(),
                $donation->netAmount(),
                $donation->psp_fee_amount,
                $donation->amount,
                $donation->payment_provider?->label(),
                $donation->status->label(),
            ];
        }
    }

    public function permission(): PermissionName
    {
        return PermissionName::CampaignsView;
    }

    public function auditContext(): array
    {
        return ['campaign' => $this->campaign->slug];
    }
}
