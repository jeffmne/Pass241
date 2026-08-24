<?php

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Jobs\ExpireOrderIfUnpaid;
use App\Jobs\ReleaseStockHold;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Audit\AuditLogger;
use App\Services\Ticketing\StockHoldService;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Redis;

/*
 * Expiration des commandes impayees (section 21.1).
 *
 * Les webhooks mobile money arrivent en retard, ou pas du tout, selon
 * l'operateur. Ce job est le filet : sans lui, une commande resterait en
 * attente indefiniment et son stock avec elle.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->ticketType] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create();

        return [$event, TicketType::factory()->for($event)->lastOne()->create()];
    });

    $this->holds = app(StockHoldService::class);

    app(OrganizationContext::class)->forget();
});

afterEach(fn () => Redis::flushdb());

/** Commande en attente, avec sa ligne et son hold, comme apres un vrai achat. */
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

        app(StockHoldService::class)->tryHold($ticketType, $order->reference);

        return $order;
    });
}

it('fait echouer une commande arrivee a echeance', function () {
    $order = pendingOrderFor($this->organization, $this->event, $this->ticketType);

    (new ExpireOrderIfUnpaid($order->id))->handle($this->holds, app(AuditLogger::class), app(OrganizationContext::class));

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Failed)
        ->and($order->failed_at)->not->toBeNull()
        ->and($order->failure_reason)->toContain('delai');
});

it('libere le stock reserve en faisant expirer la commande', function () {
    $order = pendingOrderFor($this->organization, $this->event, $this->ticketType);

    expect($this->holds->isHeld($this->ticketType))->toBeTrue();

    (new ExpireOrderIfUnpaid($order->id))->handle($this->holds, app(AuditLogger::class), app(OrganizationContext::class));

    expect($this->holds->isHeld($this->ticketType))->toBeFalse();

    // Le dernier billet redevient achetable par quelqu'un d'autre.
    // La relecture se fait dans le contexte de l'organisation : hors contexte,
    // la portee globale masque le modele, ce qui est son role (section 9).
    $reloaded = forOrganization($this->organization, fn () => $this->ticketType->fresh());

    expect($reloaded->availableStock())->toBe(1);
});

it('ne touche pas a une commande pas encore echue', function () {
    $order = pendingOrderFor($this->organization, $this->event, $this->ticketType, expired: false);

    // Le job peut s'executer un peu tot si la file est en avance sur
    // l'horloge : il ne doit rien casser.
    (new ExpireOrderIfUnpaid($order->id))->handle($this->holds, app(AuditLogger::class), app(OrganizationContext::class));

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($this->holds->isHeld($this->ticketType))->toBeTrue();
});

it('ne touche pas a une commande deja payee', function () {
    $order = pendingOrderFor($this->organization, $this->event, $this->ticketType);
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    // Le paiement a pu etre confirme entre-temps : il n'y a rien a annuler.
    (new ExpireOrderIfUnpaid($order->id))->handle($this->holds, app(AuditLogger::class), app(OrganizationContext::class));

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('reste sans effet s il est rejoue', function () {
    $order = pendingOrderFor($this->organization, $this->event, $this->ticketType);
    $audit = app(AuditLogger::class);

    // Une file d'attente peut relivrer un message : le job doit etre
    // idempotent, sinon chaque relivraison ajouterait une entree au journal.
    (new ExpireOrderIfUnpaid($order->id))->handle($this->holds, $audit, app(OrganizationContext::class));
    (new ExpireOrderIfUnpaid($order->id))->handle($this->holds, $audit, app(OrganizationContext::class));
    (new ExpireOrderIfUnpaid($order->id))->handle($this->holds, $audit, app(OrganizationContext::class));

    expect(AuditLog::action(AuditAction::PaymentFailed)->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Failed);
});

it('ne plante pas sur une commande disparue', function () {
    (new ExpireOrderIfUnpaid(999_999))->handle($this->holds, app(AuditLogger::class), app(OrganizationContext::class));
})->throwsNoExceptions();

it('journalise l echec du paiement', function () {
    $order = pendingOrderFor($this->organization, $this->event, $this->ticketType);

    (new ExpireOrderIfUnpaid($order->id))->handle($this->holds, app(AuditLogger::class), app(OrganizationContext::class));

    $log = AuditLog::action(AuditAction::PaymentFailed)->firstOrFail();

    expect($log->organization_id)->toBe($this->organization->id)
        ->and($log->metadata['reason'])->toBe('timeout');
});

it('libere le stock a la demande, avant meme l echeance', function () {
    $order = pendingOrderFor($this->organization, $this->event, $this->ticketType, expired: false);

    // Des qu'on sait qu'une commande n'aboutira pas, le dernier billet doit
    // redevenir disponible sans attendre la fin du delai.
    (new ReleaseStockHold($order->id))->handle($this->holds, app(OrganizationContext::class));

    $reloaded = forOrganization($this->organization, fn () => $order->fresh());

    expect($this->holds->isHeld($this->ticketType))->toBeFalse()
        // La commande, elle, n'est pas touchee par ce job.
        ->and($reloaded->status)->toBe(OrderStatus::Pending);
});

it('trouve les commandes a faire expirer', function () {
    pendingOrderFor($this->organization, $this->event, $this->ticketType);
    pendingOrderFor($this->organization, $this->event, $this->ticketType, expired: false);

    $expirable = Order::query()->acrossOrganizations()->expirable()->count();

    expect($expirable)->toBe(1);
});
