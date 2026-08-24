<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Ticketing\StockHoldService;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Relache les reservations de stock d'une commande (section 17.1).
 *
 * Le hold expire seul grace au TTL Redis. Ce job sert a le liberer PLUS TOT :
 * des qu'on sait qu'une commande n'aboutira pas — paiement refuse, abandon —
 * le dernier billet redevient disponible sans attendre la fin du delai. Sur un
 * evenement presque complet, ces minutes sont des ventes.
 */
class ReleaseStockHold implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $orderId) {}

    public function handle(StockHoldService $holds, OrganizationContext $context): void
    {
        // Un worker de file d'attente n'a aucun contexte d'organisation : la
        // commande est donc retrouvee hors portee.
        $order = Order::query()->acrossOrganizations()->find($this->orderId);

        if (! $order) {
            return;
        }

        /*
         * Le chargement des types de billets se fait DANS le contexte de
         * l'organisation. Sans cela, la portee globale ne renverrait rien et le
         * job ne libererait jamais aucun hold — en silence, ce qui est le pire
         * mode de defaillance possible pour du stock.
         */
        $context->runFor($order->organization, function () use ($order, $holds) {
            foreach ($order->load('items.ticketType')->items as $item) {
                if ($item->ticketType) {
                    // `release` verifie que le hold appartient bien a cette
                    // commande : un job en retard ne doit pas liberer le billet
                    // que quelqu'un d'autre est en train de payer.
                    $holds->release($item->ticketType, $order->reference);
                }
            }
        });
    }
}
