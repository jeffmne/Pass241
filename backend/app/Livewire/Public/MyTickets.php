<?php

namespace App\Livewire\Public;

use App\Models\Order;
use App\Support\BuyerOrders;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Espace « Mes billets » (section 15.1).
 *
 * « Le billet doit rester recuperable depuis la plateforme, pas uniquement via
 * le QR code joint a un email. »
 *
 * Sans compte et sans SMS, l'appareil de l'acheteur est ce qui le reconnait :
 * un cookie de longue duree retient les commandes passees depuis ce navigateur
 * (decision D-004, voir BuyerOrders).
 */
#[Layout('components.layouts.public')]
class MyTickets extends Component
{
    /** Recherche manuelle, pour un acheteur revenu depuis un autre appareil. */
    public string $reference = '';

    public function findOrder(BuyerOrders $buyer): void
    {
        $reference = mb_strtoupper(trim($this->reference));

        $order = Order::query()
            ->acrossOrganizations()
            ->where('reference', $reference)
            ->first();

        if (! $order) {
            // Message unique : distinguer « inconnue » de « pas la votre »
            // permettrait de tester des references au hasard.
            $this->addError('reference', 'Aucune commande ne correspond a cette reference.');

            return;
        }

        $buyer->remember($order->reference);

        $this->reference = '';
        $this->resetErrorBag();
    }

    /**
     * Commandes retenues sur cet appareil, la plus recente en premier.
     *
     * @return Collection<int, Order>
     */
    private function orders(BuyerOrders $buyer): Collection
    {
        $references = $buyer->all();

        if ($references === []) {
            return collect();
        }

        return Order::query()
            ->acrossOrganizations()
            ->with(Order::publicRelations())
            ->whereIn('reference', $references)
            ->latest('created_at')
            ->get();
    }

    public function render(BuyerOrders $buyer)
    {
        return view('livewire.public.my-tickets', [
            'orders' => $this->orders($buyer),
        ])->layoutData([
            'title' => 'Mes billets — Pass241',
            'indexable' => false,
            'heroDark' => false,
        ]);
    }
}
