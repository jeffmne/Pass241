<?php

namespace App\Livewire\Dashboard\Events;

use App\Enums\EventStatus;
use App\Enums\PermissionName;
use App\Livewire\Concerns\RequiresPermission;
use App\Models\Event;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Liste des evenements de l'organisation (section 56).
 *
 * Aucun filtrage par organisation n'apparait dans les requetes ci-dessous :
 * il est applique par la portee globale du trait BelongsToOrganization. Voir
 * un `where('organization_id', ...)` ecrit a la main ici serait le signe que
 * la portee ne fait pas son travail (section 9).
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use RequiresPermission;
    use WithPagination;

    #[Url(as: 'statut', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    protected function requiredPermission(): PermissionName
    {
        return PermissionName::EventsView;
    }

    public function mount(): void
    {
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
        $query = Event::query()
            ->with('location')
            ->latest('starts_at');

        if ($this->statusFilter !== '' && $status = EventStatus::tryFrom($this->statusFilter)) {
            $query->status($status);
        }

        if (trim($this->search) !== '') {
            // `ilike` plutot que `like` : sous PostgreSQL, `like` est sensible
            // a la casse et une recherche « concert » ne trouverait pas
            // « Concert Afro ».
            $query->where('name', 'ilike', '%'.trim($this->search).'%');
        }

        return view('livewire.dashboard.events.index', [
            'events' => $query->paginate(12),
            'statuses' => EventStatus::cases(),
            'totalCount' => Event::query()->count(),
            'canManage' => auth()->user()->can(PermissionName::EventsManage->value),
        ]);
    }
}
