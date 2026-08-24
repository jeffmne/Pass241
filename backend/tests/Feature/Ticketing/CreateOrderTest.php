<?php

use App\Actions\Orders\CreateOrder;
use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Jobs\ExpireOrderIfUnpaid;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Ticketing\StockHoldService;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/*
 * Creation d'une commande (sections 15, 16, 17.1, 20).
 *
 * L'acheteur n'a pas de compte : ces tests s'executent hors contexte
 * d'organisation, comme une vraie requete publique.
 */

beforeEach(function () {
    Queue::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->standard, $this->vip] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create(['name' => 'Concert Afro 2026']);

        return [
            $event,
            TicketType::factory()->for($event)->create(['name' => 'Standard', 'price' => 10_000, 'quantity' => 50]),
            TicketType::factory()->for($event)->vip()->create(['quantity' => 10]),
        ];
    });

    // Le visiteur n'appartient a aucune organisation.
    app(OrganizationContext::class)->forget();
    setPermissionsTeamId(null);

    $this->place = fn (array $quantities, array $overrides = []) => app(CreateOrder::class)->handle(
        event: $this->event,
        quantities: $quantities,
        firstName: $overrides['firstName'] ?? 'Awa',
        lastName: $overrides['lastName'] ?? 'Ndong',
        email: $overrides['email'] ?? null,
        provider: $overrides['provider'] ?? PaymentProvider::Airtel,
        payerPhone: $overrides['payerPhone'] ?? '074000000',
    );
});

afterEach(fn () => Redis::flushdb());

it('cree une commande en attente sans aucun compte', function () {
    $order = ($this->place)([$this->standard->id => 2]);

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->organization_id)->toBe($this->organization->id)
        ->and($order->event_id)->toBe($this->event->id)
        ->and($order->reference)->toStartWith('PS241-')
        ->and($order->totalTickets())->toBe(2)
        ->and($order->expires_at)->not->toBeNull();
});

it('calcule le montant depuis les prix en base, jamais depuis le client', function () {
    $order = ($this->place)([
        $this->standard->id => 2,
        $this->vip->id => 1,
    ]);

    // 2 x 10 000 + 1 x 50 000
    expect($order->amount)->toBeXaf()
        ->and($order->amount)->toBe(70_000)
        ->and($order->formattedAmount())->toContain('70');
});

it('fige le prix et le nom du billet au moment de l achat', function () {
    $order = ($this->place)([$this->standard->id => 1]);

    forOrganization($this->organization, fn () => $this->standard->update([
        'price' => 25_000,
        'name' => 'Standard (nouveau tarif)',
    ]));

    $item = $order->fresh()->items->first();

    // La commande doit continuer a dire ce qui a reellement ete achete et paye
    // ce jour-la : c'est la base de toute reconciliation (section 46).
    expect($item->unit_price)->toBe(10_000)
        ->and($item->ticket_type_name)->toBe('Standard')
        ->and($order->fresh()->amount)->toBe(10_000);
});

it('accepte une commande sans adresse email', function () {
    // Section 15.1 : le billet est livre et recuperable sans email.
    $order = ($this->place)([$this->standard->id => 1], ['email' => null]);

    expect($order->email)->toBeNull()
        ->and($order->payer_phone)->toBe('074000000');
});

it('programme l expiration de la commande', function () {
    ($this->place)([$this->standard->id => 1]);

    // Les webhooks mobile money arrivent en retard, ou pas du tout : une
    // commande ne doit jamais rester en attente indefiniment (section 21.1).
    Queue::assertPushed(ExpireOrderIfUnpaid::class);
});

it('journalise la creation de la commande', function () {
    $order = ($this->place)([$this->standard->id => 1]);

    $log = AuditLog::action(AuditAction::PaymentCreated)->firstOrFail();

    expect($log->organization_id)->toBe($this->organization->id)
        ->and($log->metadata['reference'])->toBe($order->reference)
        ->and($log->metadata['amount'])->toBe(10_000);
});

it('refuse un billet appartenant a un autre evenement', function () {
    $intruder = forOrganization($this->organization, function () {
        $other = Event::factory()->published()->create();

        return TicketType::factory()->for($other)->create();
    });

    // Sans cette verification, un identifiant venu d'ailleurs pourrait etre
    // glisse dans la requete et vendu au mauvais tarif (section 80.4).
    expect(fn () => ($this->place)([$intruder->id => 1]))
        ->toThrow(RuntimeException::class);

    expect(Order::query()->acrossOrganizations()->count())->toBe(0);
});

it('refuse un billet appartenant a une autre organisation', function () {
    [, $otherOrganization] = organizerWithOrganization('Organisation concurrente');

    $foreign = forOrganization($otherOrganization, function () {
        $event = Event::factory()->published()->create();

        return TicketType::factory()->for($event)->create();
    });

    expect(fn () => ($this->place)([$foreign->id => 1]))
        ->toThrow(RuntimeException::class);
});

it('refuse de depasser la limite par commande', function () {
    forOrganization($this->organization, fn () => $this->standard->update(['max_per_order' => 3]));

    expect(fn () => ($this->place)([$this->standard->id => 4]))
        ->toThrow(RuntimeException::class);
});

it('refuse de commander plus que le stock disponible', function () {
    forOrganization($this->organization, fn () => $this->standard->update([
        'quantity' => 2,
        'max_per_order' => 10,
    ]));

    expect(fn () => ($this->place)([$this->standard->id => 3]))
        ->toThrow(RuntimeException::class);
});

it('refuse une commande vide', function () {
    expect(fn () => ($this->place)([$this->standard->id => 0]))
        ->toThrow(RuntimeException::class);
});

it('refuse la vente sur un evenement non publie', function () {
    // L'identifiant est lu DANS le contexte de l'organisation : hors contexte,
    // la portee globale masque le type de billet, ce qui est precisement son
    // role (section 9).
    [$draft, $ticketTypeId] = forOrganization($this->organization, function () {
        $event = Event::factory()->create();

        return [$event, TicketType::factory()->for($event)->create()->id];
    });

    expect(fn () => app(CreateOrder::class)->handle(
        event: $draft,
        quantities: [$ticketTypeId => 1],
        firstName: 'Awa',
        lastName: 'Ndong',
        email: null,
        provider: PaymentProvider::Airtel,
        payerPhone: '074000000',
    ))->toThrow(RuntimeException::class);
});

it('reserve le dernier billet le temps du paiement', function () {
    $last = forOrganization($this->organization, fn () => TicketType::factory()
        ->for($this->event)->lastOne()->create(['name' => 'Derniere place']));

    $order = ($this->place)([$last->id => 1]);

    expect(app(StockHoldService::class)->holder($last))->toBe($order->reference);

    // Un second acheteur ne peut plus l'obtenir (section 17.1).
    expect(fn () => ($this->place)([$last->id => 1]))
        ->toThrow(RuntimeException::class);
});

it('ne bloque aucun stock quand il reste plusieurs billets', function () {
    ($this->place)([$this->standard->id => 1]);

    expect(app(StockHoldService::class)->isHeld($this->standard))->toBeFalse();

    // Deux acheteurs peuvent avancer en parallele sur du stock abondant.
    $second = ($this->place)([$this->standard->id => 1]);

    expect($second->status)->toBe(OrderStatus::Pending);
});

it('relache les reservations quand la commande echoue en cours de route', function () {
    $last = forOrganization($this->organization, fn () => TicketType::factory()
        ->for($this->event)->lastOne()->create());

    // Deuxieme ligne volontairement invalide : la transaction est annulee
    // apres que le hold de la premiere ligne a ete pose.
    expect(fn () => ($this->place)([$last->id => 1, $this->vip->id => 999]))
        ->toThrow(RuntimeException::class);

    // Sans nettoyage, ce billet resterait bloque cinq minutes pour rien.
    expect(app(StockHoldService::class)->isHeld($last))->toBeFalse()
        ->and(Order::query()->acrossOrganizations()->count())->toBe(0);
});

it('donne a chaque commande une reference unique et dictable', function () {
    $references = collect(range(1, 5))
        ->map(fn () => ($this->place)([$this->standard->id => 1])->reference);

    expect($references->unique())->toHaveCount(5);

    // L'alphabet exclut les caracteres qu'on confond a l'oral : 0/O, 1/I/L.
    foreach ($references as $reference) {
        expect($reference)->toMatch('/^PS241-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{3}$/');
    }
});

it('refuse de modifier le montant d une commande payee', function () {
    $order = ($this->place)([$this->standard->id => 1]);
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    // Section 67 : une correction financiere passe par un remboursement, qui
    // laisse une trace, jamais par un UPDATE qui n'en laisse aucune.
    $order->amount = 1;

    expect(fn () => $order->save())->toThrow(RuntimeException::class);
});
