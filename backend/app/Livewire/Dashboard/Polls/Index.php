<?php

namespace App\Livewire\Dashboard\Polls;

use App\Enums\PermissionName;
use App\Enums\PollStatus;
use App\Models\Poll;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Liste des votes de l'organisation (sections 86.4, 87).
 *
 * Aucun filtrage par organisation n'apparait dans les requetes ci-dessous : il
 * est applique par la portee globale de `BelongsToOrganization`. Voir un
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
        abort_unless(auth()->user()->can(PermissionName::VotesView->value), 403);
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

    public function render()
    {
        $query = Poll::query()->withCount('categories')->latest('id');

        if ($this->statusFilter !== '' && $status = PollStatus::tryFrom($this->statusFilter)) {
            $query->status($status);
        }

        if (trim($this->search) !== '') {
            // `ilike` plutot que `like` : sous PostgreSQL, `like` est sensible a
            // la casse et une recherche « miss » ne trouverait pas « Miss ».
            $query->where('name', 'ilike', '%'.trim($this->search).'%');
        }

        return view('livewire.dashboard.polls.index', [
            'polls' => $query->paginate(12),
            'statuses' => PollStatus::cases(),
            'totalCount' => Poll::query()->count(),
            'canManage' => auth()->user()->can(PermissionName::VotesManage->value),
        ]);
    }
}
