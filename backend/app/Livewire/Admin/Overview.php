<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\SuperAdminOnly;
use App\Models\AuditLog;
use App\Services\Statistics\PlatformStatistics;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Vue generale de la plateforme, pour le Super Admin (section 58).
 *
 * Un ecran de surveillance, pas un tableau de bord commercial : ce qui compte
 * est de reperer ce qui ne va pas, pas de contempler une courbe. Les
 * anomalies passent donc AVANT les volumes.
 *
 * Consultation seule, comme toute la console : aucune methode ne modifie quoi
 * que ce soit.
 */
#[Layout('components.layouts.admin')]
class Overview extends Component
{
    use SuperAdminOnly;

    public function render()
    {
        $statistics = app(PlatformStatistics::class);

        return view('livewire.admin.overview', [
            'totals' => $statistics->totals(),
            'attention' => $statistics->attention(),
            /*
             * Derniere activite, toutes organisations confondues.
             *
             * Volontairement courte : dix lignes se lisent d'un coup d'oeil,
             * cinquante demandent un effort et ne seraient plus regardees. Le
             * journal complet a son propre ecran.
             */
            'recent' => AuditLog::query()
                ->with(['organization', 'user'])
                ->latest('created_at')
                ->limit(10)
                ->get(),
        ]);
    }
}
