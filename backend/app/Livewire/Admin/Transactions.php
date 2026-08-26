<?php

namespace App\Livewire\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Transactions de la plateforme, pour le Super Admin (section 58).
 *
 * « Les donnees financieres historiques restent protegees contre les
 * modifications arbitraires. » Consultation seule, sans exception : aucune
 * methode ne modifie une commande, et le modele refuse de toute facon de
 * toucher au montant d'une commande payee.
 *
 * Le numero de l'acheteur n'est jamais affiche, meme partiellement : cet ecran
 * sert a reperer un ecart de montant ou un statut anormal, pas a retrouver
 * quelqu'un (section 52).
 */
#[Layout('components.layouts.admin')]
class Transactions extends Component
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
        $orders = Order::query()
            ->acrossOrganizations()
            ->with([
                'organization',
                'event' => fn ($query) => $query->acrossOrganizations(),
            ])
            ->when($this->filter === 'paid', fn ($query) => $query
                ->where('status', OrderStatus::Paid->value))
            ->when($this->filter === 'refunded', fn ($query) => $query
                ->whereIn('status', [
                    OrderStatus::Refunded->value,
                    OrderStatus::PartiallyRefunded->value,
                ]))
            ->when($this->filter === 'failed', fn ($query) => $query
                ->whereIn('status', [OrderStatus::Failed->value, OrderStatus::Cancelled->value]))
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';

                $query->where(fn ($q) => $q
                    ->where('reference', 'ilike', $term)
                    ->orWhere('provider_reference', 'ilike', $term)
                    ->orWhereHas('organization', fn ($o) => $o->where('name', 'ilike', $term)));
            })
            ->latest()
            ->paginate(25);

        return view('livewire.admin.transactions', [
            'orders' => $orders,
        ]);
    }
}
