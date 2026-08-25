<?php

use App\Actions\Orders\ConfirmPayment;
use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Ticketing\StockLedger;
use App\Support\Tenancy\OrganizationContext;

/*
 * Confirmation d'un paiement (sections 15.1, 17.1, 21.2, 23).
 *
 * C'est le moment ou l'argent devient des billets. Quatre garanties tiennent
 * ensemble : idempotence, verification atomique du stock, tout ou rien, et
 * commission calculee.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->ticketType] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create();

        return [$event, TicketType::factory()->for($event)->create([
            'name' => 'Standard',
            'price' => 10_000,
            'quantity' => 10,
        ])];
    });

    $this->makeOrder = function (int $quantity = 2, bool $expired = false): Order {
        return forOrganization($this->organization, function () use ($quantity, $expired) {
            $order = Order::factory()->for($this->event)->create([
                'amount' => 10_000 * $quantity,
                'expires_at' => $expired ? now()->subMinute() : now()->addMinutes(5),
            ]);

            $order->items()->create([
                'ticket_type_id' => $this->ticketType->id,
                'ticket_type_name' => $this->ticketType->name,
                'quantity' => $quantity,
                'unit_price' => 10_000,
                'subtotal' => 10_000 * $quantity,
            ]);

            return $order->load('items');
        });
    };

    app(OrganizationContext::class)->forget();
    $this->confirm = app(ConfirmPayment::class);
});

it('marque la commande payee et emet un billet par unite', function () {
    $order = ($this->makeOrder)(3);

    $confirmed = $this->confirm->handle($order, 'AIRTEL-TX-123');

    expect($confirmed->status)->toBe(OrderStatus::Paid)
        ->and($confirmed->paid_at)->not->toBeNull()
        ->and($confirmed->provider_reference)->toBe('AIRTEL-TX-123')
        // La reservation n'a plus lieu d'etre : les billets sont vendus.
        ->and($confirmed->expires_at)->toBeNull();

    expect(Ticket::query()->acrossOrganizations()->count())->toBe(3);
});

it('emet des billets valides au nom de l acheteur', function () {
    $order = ($this->makeOrder)(1);

    $this->confirm->handle($order);

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();

    expect($ticket->status)->toBe(TicketStatus::Active)
        ->and($ticket->holder_name)->toBe($order->buyerName())
        ->and($ticket->event_id)->toBe($this->event->id)
        ->and($ticket->organization_id)->toBe($this->organization->id)
        // Reference lisible, dictable au support. Le QR encodera un jeton
        // signe, jamais cette valeur (section 18).
        ->and($ticket->reference)->toStartWith('T-');
});

it('preleve la commission de 5 % et fige son taux', function () {
    $order = ($this->makeOrder)(2); // 20 000 FCFA

    $this->confirm->handle($order);

    $commission = Commission::query()->acrossOrganizations()->firstOrFail();

    expect($commission->gross_amount)->toBe(20_000)
        ->and($commission->commission_amount)->toBe(1_000)
        ->and($commission->net_amount)->toBe(19_000)
        ->and($commission->rate_basis_points)->toBe(500)
        ->and($commission->organization_id)->toBe($this->organization->id);
});

it('consomme le stock une fois le paiement confirme', function () {
    $order = ($this->makeOrder)(4);

    $this->confirm->handle($order);

    $ledger = app(StockLedger::class);

    expect($ledger->sold($this->ticketType))->toBe(4)
        ->and($ledger->reserved($this->ticketType))->toBe(0)
        ->and($ledger->available($this->ticketType))->toBe(6);
});

/*
 * ---------------------------------------------------------------------------
 * Idempotence (section 23)
 * ---------------------------------------------------------------------------
 */

it('ne cree jamais un second jeu de billets si le webhook est rejoue', function () {
    $order = ($this->makeOrder)(2);

    // Les webhooks mobile money sont rejoues, c'est normal.
    $this->confirm->handle($order);
    $this->confirm->handle($order->fresh());
    $this->confirm->handle($order->fresh());

    expect(Ticket::query()->acrossOrganizations()->count())->toBe(2)
        ->and(Commission::query()->acrossOrganizations()->count())->toBe(1);
});

it('ne journalise la confirmation qu une seule fois', function () {
    $order = ($this->makeOrder)(1);

    $this->confirm->handle($order);
    $this->confirm->handle($order->fresh());

    expect(AuditLog::action(AuditAction::PaymentConfirmed)->count())->toBe(1);
});

it('conserve le montant d une commande deja payee', function () {
    $order = ($this->makeOrder)(2);
    $this->confirm->handle($order);

    $before = $order->fresh()->amount;

    $this->confirm->handle($order->fresh());

    expect($order->fresh()->amount)->toBe($before);
});

/*
 * ---------------------------------------------------------------------------
 * Verification atomique du stock (section 17.1)
 * ---------------------------------------------------------------------------
 */

it('accepte un paiement tardif quand le stock est encore la', function () {
    // L'acheteur a valide a la derniere seconde, le callback arrive apres
    // l'expiration de sa reservation.
    $order = ($this->makeOrder)(2, expired: true);

    $confirmed = $this->confirm->handle($order);

    expect($confirmed->status)->toBe(OrderStatus::Paid)
        ->and(Ticket::query()->acrossOrganizations()->count())->toBe(2);
});

it('refuse un paiement tardif quand les places sont parties', function () {
    $late = ($this->makeOrder)(6, expired: true);

    // Les places ont ete vendues pendant ce temps.
    $other = ($this->makeOrder)(6);
    $this->confirm->handle($other);

    // Section 17.1 : la verification atomique tranche. L'argent est encaisse,
    // la commande devra etre remboursee — mais aucun billet fantome n'est emis.
    expect(fn () => $this->confirm->handle($late))->toThrow(RuntimeException::class);

    expect($late->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::query()->acrossOrganizations()->count())->toBe(6);
});

it('n ecrit rien du tout quand le stock manque', function () {
    $late = ($this->makeOrder)(11, expired: true);

    expect(fn () => $this->confirm->handle($late))->toThrow(RuntimeException::class);

    // Tout ou rien : ni statut, ni billets, ni commission.
    expect($late->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($late->fresh()->paid_at)->toBeNull()
        ->and(Ticket::query()->acrossOrganizations()->count())->toBe(0)
        ->and(Commission::query()->acrossOrganizations()->count())->toBe(0);
});

it('ne s oppose pas a la reservation que la commande a elle-meme posee', function () {
    // Tout le stock est reserve par cette commande : elle doit pouvoir aller
    // au bout, sinon aucune commande complete ne pourrait jamais aboutir.
    $order = ($this->makeOrder)(10);

    $confirmed = $this->confirm->handle($order);

    expect($confirmed->status)->toBe(OrderStatus::Paid)
        ->and(Ticket::query()->acrossOrganizations()->count())->toBe(10);
});

/*
 * ---------------------------------------------------------------------------
 * Echecs
 * ---------------------------------------------------------------------------
 */

it('marque une commande en echec avec son motif', function () {
    $order = ($this->makeOrder)(1);

    $failed = $this->confirm->markFailed($order, 'Solde insuffisant.');

    expect($failed->status)->toBe(OrderStatus::Failed)
        ->and($failed->failure_reason)->toBe('Solde insuffisant.')
        ->and(Ticket::query()->acrossOrganizations()->count())->toBe(0)
        ->and(AuditLog::action(AuditAction::PaymentFailed)->exists())->toBeTrue();
});

it('ne fait pas echouer une commande deja payee', function () {
    $order = ($this->makeOrder)(1);
    $this->confirm->handle($order);

    // Un webhook d'echec en retard ne doit pas annuler un paiement abouti.
    $this->confirm->markFailed($order->fresh(), 'Trop tard.');

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('refuse de payer une commande annulee', function () {
    $order = ($this->makeOrder)(1);
    $order->forceFill(['status' => OrderStatus::Cancelled])->save();

    expect(fn () => $this->confirm->handle($order->fresh()))->toThrow(RuntimeException::class);
});

it('rend le stock quand la commande echoue', function () {
    $order = ($this->makeOrder)(4);

    expect(app(StockLedger::class)->available($this->ticketType))->toBe(6);

    $this->confirm->markFailed($order, 'Solde insuffisant.');

    expect(app(StockLedger::class)->available($this->ticketType))->toBe(10);
});
