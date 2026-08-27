<?php

namespace App\Livewire\Dashboard\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\PermissionName;
use App\Models\Campaign;
use App\Services\Fundraising\CampaignProgress;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Liste des collectes de l'organisation (sections 34, 56).
 *
 * Aucun filtrage par organisation n'apparait dans les requetes ci-dessous : il
 * est applique par la portee globale du trait BelongsToOrganization. Voir un
 * `where('organization_id', ...)` ecrit a la main ici serait le signe que la
 * portee ne fait pas son travail (section 9).
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'statut', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can(PermissionName::CampaignsView->value), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('statusFilter', 'search');
        $this->resetPage();
    }

    public function render(CampaignProgress $progress)
    {
        $query = Campaign::query()->latest('id');

        if ($this->statusFilter !== '' && $status = CampaignStatus::tryFrom($this->statusFilter)) {
            $query->status($status);
        }

        if (trim($this->search) !== '') {
            // `ilike` plutot que `like` : sous PostgreSQL, `like` est sensible
            // a la casse et une recherche « ecole » ne trouverait pas « Ecole ».
            $query->where('name', 'ilike', '%'.trim($this->search).'%');
        }

        $campaigns = $query->paginate(12);

        return view('livewire.dashboard.campaigns.index', [
            'campaigns' => $campaigns,
            'statuses' => CampaignStatus::cases(),
            'totalCount' => Campaign::query()->count(),
            'canManage' => auth()->user()->can(PermissionName::CampaignsManage->value),
            /*
             * La progression est calculee, jamais lue sur une colonne
             * (regle 80.1). Elle est preparee ici pour que la vue n'ait aucun
             * calcul a faire — le frontend n'est pas source de verite
             * (sections 49, 50).
             */
            'progress' => $campaigns->mapWithKeys(
                fn (Campaign $campaign) => [$campaign->getKey() => $progress->forCampaign($campaign)]
            ),
        ]);
    }
}
