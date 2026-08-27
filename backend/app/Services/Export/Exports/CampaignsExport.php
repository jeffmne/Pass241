<?php

namespace App\Services\Export\Exports;

use App\Enums\PermissionName;
use App\Models\Campaign;
use App\Services\Export\Exportable;
use App\Services\Fundraising\CampaignProgress;
use Generator;

/**
 * Toutes les collectes de l'organisation (sections 57, 59).
 *
 * Les chiffres viennent de `CampaignProgress`, seul point de calcul : le
 * fichier dit donc exactement ce que la page publique dit, au meme instant.
 * Recalculer ici avec une requete « equivalente » aurait produit, tot ou tard,
 * un export qui contredit la page — et c'est l'export qu'on garde.
 */
class CampaignsExport implements Exportable
{
    public function __construct(private readonly CampaignProgress $progress) {}

    public function label(): string
    {
        return 'Collectes';
    }

    public function filename(): string
    {
        return 'collectes-'.now()->format('Y-m-d');
    }

    public function headings(): array
    {
        return [
            'Collecte',
            'Statut',
            'Beneficiaire',
            'Objectif',
            'Collecte (net)',
            'Progression (%)',
            'Nombre de dons',
            'Donateurs',
            'Debut',
            'Fin',
            'Lien public',
        ];
    }

    public function rows(): Generator
    {
        $tz = config('app.display_timezone');

        $campaigns = Campaign::query()->orderBy('id')->lazyById(200);

        foreach ($campaigns as $campaign) {
            $progress = $this->progress->forCampaign($campaign);

            yield [
                $campaign->name,
                // L'etat AFFICHE : une collecte dont l'echeance est passee se
                // lit « echue », meme si la tache planifiee n'est pas encore
                // passee.
                $campaign->displayStatus()->label(),
                $campaign->beneficiary_label,
                $progress['goal'],
                $progress['collected'],
                round($progress['percentage'], 1),
                $progress['donations'],
                $progress['donors'],
                $campaign->starts_at?->timezone($tz)->format('Y-m-d'),
                $campaign->ends_at?->timezone($tz)->format('Y-m-d'),
                $campaign->status->hasPublicPage() ? $campaign->publicUrl() : null,
            ];
        }
    }

    public function permission(): PermissionName
    {
        return PermissionName::CampaignsView;
    }

    public function auditContext(): array
    {
        return [];
    }
}
