<?php

use App\Actions\Payments\ConfirmPayment;
use App\Enums\OrderStatus;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Commission;
use App\Models\Event;
use App\Models\Order;
use App\Models\PaymentWebhook;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Payments\FakeProvider;
use App\Services\Payments\PayableResolver;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Str;

/*
 * Webhooks de paiement (sections 22, 23).
 *
 * « Le frontend ne doit jamais decider seul qu'un paiement est confirme. »
 * Cette route est le seul chemin entrant, et elle n'accepte que des messages
 * signes.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->ticketType] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create();

        return [$event, TicketType::factory()->for($event)->create(['price' => 10_000, 'quantity' => 10])];
    });

    $this->order = forOrganization($this->organization, function () {
        $order = Order::factory()->for($this->event)->create(['amount' => 20_000]);

        $order->items()->create([
            'ticket_type_id' => $this->ticketType->id,
            'ticket_type_name' => $this->ticketType->name,
            'quantity' => 2,
            'unit_price' => 10_000,
            'subtotal' => 20_000,
        ]);

        return $order;
    });

    app(OrganizationContext::class)->forget();

    /** Envoie un webhook signe, comme le ferait un operateur. */
    $this->send = function (array $overrides = [], bool $sign = true) {
        $payload = array_merge([
            'event_id' => 'evt-'.Str::uuid()->toString(),
            'order_reference' => $this->order->reference,
            'status' => 'SUCCEEDED',
            'amount' => $this->order->amount,
            'provider_reference' => 'AIRTEL-TX-001',
            'failure_reason' => null,
        ], $overrides);

        $headers = $sign
            ? ['X-Pass241-Signature' => FakeProvider::sign($payload)]
            : ['X-Pass241-Signature' => 'signature-forgee'];

        return [$this->postJson('/api/webhooks/payments/fake', $payload, $headers), $payload];
    };
});

/** Execute les jobs de webhook en attente, comme le ferait un worker. */
function drainWebhooks(): void
{
    foreach (PaymentWebhook::whereNull('processed_at')->where('signature_valid', true)->get() as $webhook) {
        (new ProcessPaymentWebhook($webhook->id))->handle(
            app(ConfirmPayment::class),
            app(PayableResolver::class),
        );
    }
}

it('confirme le paiement et emet les billets', function () {
    [$response] = ($this->send)();

    $response->assertOk()->assertJson(['status' => 'accepted']);

    drainWebhooks();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(Ticket::query()->acrossOrganizations()->count())->toBe(2)
        ->and(Commission::query()->acrossOrganizations()->count())->toBe(1);
});

it('refuse un webhook dont la signature est invalide', function () {
    [$response] = ($this->send)(sign: false);

    // 202 et non 401 : repondre differemment selon la validite indiquerait a
    // un attaquant quand il approche.
    $response->assertStatus(202)->assertJson(['status' => 'ignored']);

    drainWebhooks();

    // Rien n'a bouge, et surtout aucun billet n'a ete emis.
    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::query()->acrossOrganizations()->count())->toBe(0);
});

it('conserve les messages non authentifies', function () {
    ($this->send)(sign: false);

    // C'est ce qui permet de constater apres coup une tentative de fraude
    // (section 22).
    $webhook = PaymentWebhook::firstOrFail();

    expect($webhook->signature_valid)->toBeFalse()
        ->and($webhook->outcome)->toBe('Signature invalide.');
});

it('n emet qu un seul jeu de billets quand le meme message est rejoue', function () {
    // Section 23 : « Si le meme webhook est recu plusieurs fois, seul le
    // premier declenche une confirmation. »
    $eventId = 'evt-doublon';

    [$first] = ($this->send)(['event_id' => $eventId]);
    [$second] = ($this->send)(['event_id' => $eventId]);
    [$third] = ($this->send)(['event_id' => $eventId]);

    $first->assertOk()->assertJson(['status' => 'accepted']);
    $second->assertOk()->assertJson(['status' => 'duplicate']);
    $third->assertOk()->assertJson(['status' => 'duplicate']);

    drainWebhooks();

    expect(PaymentWebhook::count())->toBe(1)
        ->and(Ticket::query()->acrossOrganizations()->count())->toBe(2)
        ->and(Commission::query()->acrossOrganizations()->count())->toBe(1);
});

it('reste sur un seul jeu de billets meme avec deux messages distincts', function () {
    // Deux identifiants differents portant la meme confirmation : la premiere
    // barriere ne joue pas, c'est ConfirmPayment qui protege.
    ($this->send)(['event_id' => 'evt-a']);
    ($this->send)(['event_id' => 'evt-b']);

    drainWebhooks();

    expect(PaymentWebhook::count())->toBe(2)
        ->and(Ticket::query()->acrossOrganizations()->count())->toBe(2)
        ->and(Commission::query()->acrossOrganizations()->count())->toBe(1);
});

it('refuse de confirmer un montant qui ne correspond pas', function () {
    ($this->send)(['amount' => 500]);

    drainWebhooks();

    // Erreur de l'operateur ou tentative de manipulation : dans les deux cas,
    // confirmer serait pire que ne rien faire.
    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::query()->acrossOrganizations()->count())->toBe(0)
        ->and(PaymentWebhook::firstOrFail()->outcome)->toContain('Montant incoherent');
});

it('marque la commande en echec sur un refus de l operateur', function () {
    ($this->send)(['status' => 'FAILED', 'failure_reason' => 'Solde insuffisant.']);

    drainWebhooks();

    $order = $this->order->fresh();

    expect($order->status)->toBe(OrderStatus::Failed)
        ->and($order->failure_reason)->toBe('Solde insuffisant.')
        ->and(Ticket::query()->acrossOrganizations()->count())->toBe(0);
});

it('ignore un etat intermediaire sans rien casser', function () {
    ($this->send)(['status' => 'PROCESSING']);

    drainWebhooks();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(PaymentWebhook::firstOrFail()->outcome)->toContain('Etat non definitif');
});

it('conserve un message dont la commande est introuvable', function () {
    ($this->send)(['order_reference' => 'PS241-XXXX-999']);

    drainWebhooks();

    expect(PaymentWebhook::firstOrFail()->outcome)->toContain('Transaction introuvable');
});

it('ignore un provider inconnu sans en reveler la liste', function () {
    $this->postJson('/api/webhooks/payments/stripe', ['event_id' => 'evt-1'])
        ->assertStatus(202)
        ->assertJson(['status' => 'ignored']);

    expect(PaymentWebhook::count())->toBe(0);
});

it('rattache le message a sa commande', function () {
    ($this->send)();
    drainWebhooks();

    $webhook = PaymentWebhook::firstOrFail();

    // Rattachement polymorphe depuis la decision D-041 : le meme journal
    // accueille les webhooks des dons, et demain ceux des votes payants.
    expect($webhook->payable_id)->toBe($this->order->id)
        ->and($webhook->payable_type)->toBe($this->order->getMorphClass());
});

it('signale les cas a rembourser quand le stock ne suffit plus', function () {
    // L'acheteur a valide a la derniere seconde : sa reservation vient
    // d'expirer, et elle ne bloque donc plus le stock.
    forOrganization($this->organization, fn () => $this->order->forceFill([
        'expires_at' => now()->subMinute(),
    ])->save());

    // Tout le stock part pendant ce temps.
    $competitor = forOrganization($this->organization, function () {
        $order = Order::factory()->for($this->event)->create(['amount' => 100_000]);

        $order->items()->create([
            'ticket_type_id' => $this->ticketType->id,
            'ticket_type_name' => $this->ticketType->name,
            'quantity' => 10,
            'unit_price' => 10_000,
            'subtotal' => 100_000,
        ]);

        return $order;
    });

    app(ConfirmPayment::class)->handle($competitor);

    // Le callback du premier acheteur arrive maintenant.
    ($this->send)();
    drainWebhooks();

    // L'argent est encaisse mais aucun billet ne peut etre emis : le message
    // porte le motif, pour que ces cas soient retrouvables.
    expect(PaymentWebhook::where('payable_id', $this->order->id)->firstOrFail()->outcome)
        ->toContain('A rembourser');

    expect(Ticket::query()->acrossOrganizations()->count())->toBe(10);
});
