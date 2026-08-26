<?php

namespace App\Livewire\Admin;

use App\Enums\TicketStatus;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Annuaire des organisations, pour le Super Admin (section 58).
 *
 * Consultation seule. La suspension d'une organisation est prevue par la
 * section 10, mais elle coupe les ventes en cours et bloque les reversements :
 * elle merite son propre chemin, audite et confirme, pas un bouton dans une
 * liste. Elle n'est donc pas exposee ici tant que ce chemin n'existe pas.
 */
#[Layout('components.layouts.admin')]
class Organizations extends Component
{
    use WithPagination;

    public string $search = '';

    public string $filter = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        /*
         * Volumes agreges par sous-requete plutot que par `withCount` sur les
         * billets : compter des lignes de billet organisation par organisation
         * ferait autant de requetes que d'organisations affichees, et le
         * nombre de billets peut se compter en centaines de milliers.
         */
        $sales = DB::table('tickets')
            ->join('order_items', 'order_items.id', '=', 'tickets.order_item_id')
            ->whereIn('tickets.status', [TicketStatus::Active->value, TicketStatus::Used->value])
            ->groupBy('tickets.organization_id')
            ->selectRaw('
                tickets.organization_id,
                COUNT(*) AS tickets_sold,
                COALESCE(SUM(order_items.unit_price), 0) AS gross
            ');

        $organizations = Organization::query()
            ->leftJoinSub($sales, 'sales', 'sales.organization_id', '=', 'organizations.id')
            // `select()` AVANT `withCount` : appele apres, il remplace la liste
            // de selection et emporte la sous-requete de comptage avec elle —
            // le compteur ressortait vide.
            ->select('organizations.*')
            ->withCount(['members as members_count'])
            ->selectRaw('COALESCE(sales.tickets_sold, 0) AS tickets_sold')
            ->selectRaw('COALESCE(sales.gross, 0) AS gross')
            ->when($this->filter === 'suspended', fn ($query) => $query->whereNotNull('suspended_at'))
            ->when($this->filter === 'no-payout', fn ($query) => $query->whereNull('payout_phone'))
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';

                $query->where(fn ($q) => $q
                    ->where('organizations.name', 'ilike', $term)
                    ->orWhere('organizations.contact_email', 'ilike', $term));
            })
            ->orderByDesc('gross')
            ->paginate(25);

        return view('livewire.admin.organizations', [
            'organizations' => $organizations,
        ]);
    }
}
