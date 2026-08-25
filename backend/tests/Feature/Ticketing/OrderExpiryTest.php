<?php

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Jobs\ExpireOrderIfUnpaid;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Audit\AuditLogger;
use App\Services\Ticketing\StockLedger;
use App\Support\Tenancy\OrganizationContext;

/*
 * Expiration des commandes impayees (section 21.1).
 *
 * Depuis la decision D-017, ce job ne libere plus aucun stock : la reservation
 * expire d'elle-meme avec la commande. Son role est de marquer l'echec, pour
 * que l'acheteur et l'organisateur lisent un etat clair, et de laisser une
 * trace au journal.
 *
 * Cette separation compte : un worker arrete ne peut plus fausser un decompte
 * de stock.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->ticketType] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create();

        return [$event, TicketType::factory()->for($event)->create(['quantity' => 5])];
    });

    app(OrganizationContext::class)->forget();

    $this->run = fn (int $orderId) => (new ExpireOrderIfUnpaid($orderId))
        ->handle(app(AuditLogger::class), app(OrganizationContext::class));
});

/** Commande en attente, avec sa ligne, comme apres un vrai achat. */
function pendingOrderFor($organization, $event, $ticketType, bool $expired = true): Order
{
    return forOrganization($organization, function () use ($event, $ticketType, $expired) {
        $order = Order::factory()->for($event)->create([
            'expires_at' => $expired ? now()->subMinute() : now()->addMinutes(5),
        ]);

        $order->items()->create([
            'ticket_type_id' => $ticketType->id,
            'ticket_type_name' => $ticketType->name,
            'quantity' => 1,
            'unit_price' => $ticketType->price,
            'subtotal' => $ticketType->price,
        ]);

        return $order;
    });
}

it('fait echouer une commande arrivee a echeance', function () {
    $order = pendingOrderFor($this->organization, $this->event, $this->ticketType);

    ($this->run)($order->id);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Failed)
        ->and($order->failed_at)->not->toBeNull()
        ->and($order->failure_reason)->toContain('delai');
});

it('ne touche pas a une commande pas encore echue', function () {
    $order = pendingOrderFor($this->organization, $this->event, $this->ticketType, expired: false);

    // Le job peut s'executer un peu tot si la file est en avance sur
    // l'horloge : il ne doit rien casser.
    ($this->run)($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

it('ne touche pas a une commande deja payee', function () {
    $order = pendingOrderFor($this->organization, $this->event, $this->ticketType);
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    // Le paiement a pu etre confirme entre-temps : il n'y a rien a annuler.
    ($this->run)($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('reste sans effet s il est rejoue', function () {
    $order = pendingOrderFor($this->organization, $this->event, $this->ticketType);

    // Une file d'attente peut relivrer un message : sans idempotence, chaque
    // relivraison ajouterait une entree au journal.
    ($this->run)($order->id);
    ($this->run)($order->id);
    ($this->run)($order->id);

    expect(AuditLog::action(AuditAction::PaymentFailed)->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Failed);
});

it('ne plante pas sur une commande disparue', function () {
    ($this->run)(999_999);
})->throwsNoExceptions();

it('journalise l echec du paiement', function () {
    $order = pendingOrderFor($this->organization, $this->event, $this->ticketType);

    ($this->run)($order->id);

    $log = AuditLog::action(AuditAction::PaymentFailed)->firstOrFail();

    expect($log->organization_id)->toBe($this->organization->id)
        ->and($log->metadata['reason'])->toBe('timeout');
});

it('rend le stock disponible a l echeance, sans attendre le job', function () {
    pendingOrderFor($this->organization, $this->event, $this->ticketType, expired: false);

    $ledger = app(StockLedger::class);

    expect($ledger->available($this->ticketType))->toBe(4);

    // La reservation cesse d'etre comptee a la seconde ou elle expire. Aucun
    // traitement n'a besoin de s'executer : c'est tout l'interet de la
    // decision D-017.
    $this->travel(6)->minutes();

    expect($ledger->available($this->ticketType))->toBe(5);
});

it('trouve les commandes a faire expirer', function () {
    pendingOrderFor($this->organization, $this->event, $this->ticketType);
    pendingOrderFor($this->organization, $this->event, $this->ticketType, expired: false);

    expect(Order::query()->acrossOrganizations()->expirable()->count())->toBe(1);
});
