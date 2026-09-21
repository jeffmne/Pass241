<?php

use App\Actions\Payments\ConfirmPayment;
use App\Actions\Orders\CreateOrder;
use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Jobs\ExpireOrderIfUnpaid;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Ticketing\StockLedger;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Queue;

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

it('cree une commande en attente sans aucun compte', function () {
    $order = ($this->place)([$this->standard->id => 2]);

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->organization_id)->toBe($this->organization->id)
        ->and($order->event_id)->toBe($this->event->id)
        ->and($order->reference)->toStartWith('PS241-')
        ->and($order->totalTickets())->toBe(2)
        ->and($order->expires_at)->not->toBeNull();
});

it('refuse un tarif cache meme si son identifiant est connu', function () {
    forOrganization($this->organization, fn () => $this->standard->update(['is_visible' => false]));

    expect(fn () => ($this->place)([$this->standard->id => 1]))
        ->toThrow(RuntimeException::class, 'Ce billet n\'existe pas');
    expect(Order::query()->acrossOrganizations()->count())->toBe(0);
});

it('calcule le montant depuis les prix en base, jamais depuis le client', function () {
    $order = ($this->place)([
        $this->standard->id => 2,
        $this->vip->id => 1,
    ]);

    // 2 x 10 000 + 1 x 50 000 de billets, plus 2 % de frais de prestataire
    // avances par l'acheteur (decision D-023).
    expect($order->subtotal)->toBeXaf()
        ->and($order->subtotal)->toBe(70_000)
        ->and($order->psp_fee_amount)->toBe(1_400)
        ->and($order->amount)->toBe(71_400)
        ->and($order->formattedAmount())->toContain('71');
});

it('n assied jamais la commission sur les frais du prestataire', function () {
    $order = ($this->place)([$this->vip->id => 1]);

    // Le billet vaut 50 000 : la commission Pass241 porte sur cette somme, pas
    // sur les 51 000 debites. Prendre le total pour assiette reviendrait a
    // prelever une commission sur l'argent de l'operateur, et gonflerait le
    // net reverse a l'organisateur (decision D-023).
    expect($order->subtotal)->toBe(50_000)
        ->and($order->amount)->toBe(51_000);

    app(ConfirmPayment::class)->handle($order);

    $commission = Commission::query()->acrossOrganizations()->firstOrFail();

    expect($commission->gross_amount)->toBe(50_000)
        ->and($commission->commission_amount)->toBe(2_500)
        ->and($commission->net_amount)->toBe(47_500);
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
        ->and($order->fresh()->subtotal)->toBe(10_000);
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
        ->and($log->metadata['subtotal'])->toBe(10_000)
        ->and($log->metadata['psp_fee'])->toBe(200)
        ->and($log->metadata['amount'])->toBe(10_200);
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

it('retire du stock disponible les billets qu elle reserve', function () {
    // Section 17.1 : toute commande reserve ses billets, quel que soit le
    // stock restant. La commande elle-meme EST la reservation (decision D-017).
    ($this->place)([$this->standard->id => 3]);

    expect(app(StockLedger::class)->available($this->standard))->toBe(47);
});

it('empeche deux acheteurs de se partager le meme dernier billet', function () {
    $last = forOrganization($this->organization, fn () => TicketType::factory()
        ->for($this->event)->lastOne()->create(['name' => 'Derniere place']));

    ($this->place)([$last->id => 1]);

    // C'etait le seul cas couvert par l'ancienne regle. Il l'est toujours,
    // mais desormais par le meme mecanisme que tous les autres.
    expect(fn () => ($this->place)([$last->id => 1]))
        ->toThrow(RuntimeException::class);
});

it('empeche aussi la survente sur du stock abondant', function () {
    $limited = forOrganization($this->organization, fn () => TicketType::factory()
        ->for($this->event)->create(['name' => 'Serie limitee', 'quantity' => 4, 'max_per_order' => 3]));

    ($this->place)([$limited->id => 3]);

    // C'est precisement ce que l'ancienne regle laissait passer : au-dessus
    // d'un billet restant, plusieurs commandes s'ouvraient sur le meme stock.
    expect(fn () => ($this->place)([$limited->id => 3]))
        ->toThrow(RuntimeException::class);

    // Il reste bien une place, et une seule.
    expect(app(StockLedger::class)->available($limited))->toBe(1);
});

it('rend le stock des qu une reservation expire', function () {
    $limited = forOrganization($this->organization, fn () => TicketType::factory()
        ->for($this->event)->create(['quantity' => 2, 'max_per_order' => 2]));

    ($this->place)([$limited->id => 2]);

    expect(app(StockLedger::class)->available($limited))->toBe(0);

    // Aucun job n'a besoin de tourner : la reservation cesse d'etre comptee
    // a la seconde ou elle expire.
    $this->travel(6)->minutes();

    expect(app(StockLedger::class)->available($limited))->toBe(2);
});

it('n enregistre rien quand une ligne de la commande est invalide', function () {
    $limited = forOrganization($this->organization, fn () => TicketType::factory()
        ->for($this->event)->create(['quantity' => 5]));

    // Deuxieme ligne volontairement invalide : la transaction est annulee.
    expect(fn () => ($this->place)([$limited->id => 1, $this->vip->id => 999]))
        ->toThrow(RuntimeException::class);

    // Aucune commande, donc aucune reservation fantome : le stock est intact.
    expect(Order::query()->acrossOrganizations()->count())->toBe(0)
        ->and(app(StockLedger::class)->available($limited))->toBe(5);
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
