<?php

namespace App\Livewire\Admin;

use App\Enums\TicketStatus;
use App\Livewire\Concerns\SuperAdminOnly;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Annuaire des organisations, pour le Super Admin (section 58).
 *
 * Chaque carte ouvre la fiche du compte. La suspension vit la-bas et pas ici :
 * elle coupe les ventes en cours et bloque les reversements, elle merite un
 * ecran ou l'on voit ce qu'on ferme avant de le fermer — pas un bouton au bout
 * d'une ligne de liste.
 */
#[Layout('components.layouts.admin')]
class Organizations extends Component
{
    use SuperAdminOnly;

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
