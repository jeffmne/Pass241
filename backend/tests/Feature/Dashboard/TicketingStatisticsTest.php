<?php

use App\Actions\Payments\ConfirmPayment;
use App\Actions\Refunds\DecideRefund;
use App\Actions\Refunds\RequestRefund;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\TicketStatus;
use App\Livewire\Dashboard\Overview;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Statistics\TicketingStatistics;
use App\Support\Money;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Statistiques de billetterie du dashboard (section 56).
 *
 * Trois choses sont verifiees ici, et la troisieme est la plus importante :
 * les chiffres sont justes, ils respectent les permissions, et ils ne
 * traversent JAMAIS les organisations (regle 80.4).
 */

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->event = forOrganization($this->organization, fn () => Event::factory()
        ->published()
        ->create(['starts_at' => now()->addDays(30), 'ends_at' => now()->addDays(30)->addHours(4)]));

    $this->type = fn (string $name, int $price, int $quantity) => forOrganization(
        $this->organization,
        fn () => TicketType::factory()->for($this->event)->create([
            'name' => $name, 'price' => $price, 'quantity' => $quantity,
        ]),
    );

    $this->sell = function (TicketType $type, int $quantity) {
        return forOrganization($this->organization, function () use ($type, $quantity) {
            $subtotal = $type->price * $quantity;

            $order = Order::factory()->for($this->event)->create([
                'subtotal' => $subtotal,
                'amount' => $subtotal,
            ]);

            $order->items()->create([
                'ticket_type_id' => $type->id,
                'ticket_type_name' => $type->name,
                'quantity' => $quantity,
                'unit_price' => $type->price,
                'subtotal' => $subtotal,
            ]);

            return app(ConfirmPayment::class)->handle($order->load('items'));
        });
    };

    $this->stats = fn () => app(TicketingStatistics::class);

    app(OrganizationContext::class)->forget();
});

afterEach(function () {
    app(TicketingStatistics::class)->forget($this->organization);
});

/*
 * ---------------------------------------------------------------------------
 * Justesse des chiffres
 * ---------------------------------------------------------------------------
 */

it('compte les billets vendus et les recettes', function () {
    ($this->sell)(($this->type)('Standard', 10_000, 100), 3);

    $stats = ($this->stats)()->forOrganization($this->organization);

    expect($stats['sold'])->toBe(3)
        ->and($stats['revenue'])->toBe(30_000)
        ->and($stats['capacity'])->toBe(100)
        ->and($stats['remaining'])->toBe(97);
});

it('annonce le net apres commission a cote de la recette', function () {
    ($this->sell)(($this->type)('Standard', 10_000, 100), 2);

    $stats = ($this->stats)()->forOrganization($this->organization);

    // Afficher la recette brute seule ferait lire le reversement comme une
    // erreur : l'organisateur verrait 20 000 puis recevrait 19 000
    // (section 21.2).
    expect($stats['revenue'])->toBe(20_000)
        ->and($stats['net'])->toBe(19_000);
});

it('ne compte pas un billet rembourse comme vendu', function () {
    $type = ($this->type)('Standard', 10_000, 100);
    $order = ($this->sell)($type, 3);

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();
    $request = app(RequestRefund::class)->handle($order->fresh()->load('event'), $ticket);
    forOrganization($this->organization, fn () => app(DecideRefund::class)->approve($request, $this->owner));

    app(TicketingStatistics::class)->forget($this->organization);

    // Le compter gonflerait les recettes d'un argent rendu au client.
    $stats = ($this->stats)()->forOrganization($this->organization);

    expect($stats['sold'])->toBe(2)
        ->and($stats['revenue'])->toBe(20_000);
});

it('retient le prix reellement paye, pas le tarif courant', function () {
    $type = ($this->type)('Standard', 10_000, 100);
    ($this->sell)($type, 2);

    forOrganization($this->organization, fn () => $type->update(['price' => 25_000]));

    app(TicketingStatistics::class)->forget($this->organization);

    // Le tarif a change apres l'achat : la recette doit continuer a dire ce
    // qui a reellement ete encaisse.
    expect(($this->stats)()->forOrganization($this->organization)['revenue'])->toBe(20_000);
});

it('compte les entrees a partir des billets utilises', function () {
    $type = ($this->type)('Standard', 10_000, 100);
    ($this->sell)($type, 4);

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();
    forOrganization($this->organization, fn () => $ticket->forceFill([
        'status' => TicketStatus::Used->value, 'used_at' => now(),
    ])->save());

    app(TicketingStatistics::class)->forget($this->organization);

    $stats = ($this->stats)()->forOrganization($this->organization);

    // Un billet utilise reste un billet vendu : il compte dans les deux.
    expect($stats['sold'])->toBe(4)
        ->and($stats['entries'])->toBe(1)
        ->and($stats['attendanceRate'])->toBe(25.0)
        ->and($stats['hasEntries'])->toBeTrue();
});

it('n annonce aucune presence tant que rien n a ete scanne', function () {
    ($this->sell)(($this->type)('Standard', 10_000, 100), 2);

    $stats = ($this->stats)()->forOrganization($this->organization);

    // Le controle d'acces arrive en phase 5 : « 0 % de presence » se lirait
    // comme un echec plutot que comme une absence de donnee.
    expect($stats['hasEntries'])->toBeFalse()
        ->and($stats['entries'])->toBe(0);
});

/*
 * ---------------------------------------------------------------------------
 * Repartition par categorie
 * ---------------------------------------------------------------------------
 */

it('repartit les ventes par categorie', function () {
    ($this->sell)(($this->type)('Standard', 10_000, 100), 6);
    ($this->sell)(($this->type)('VIP', 50_000, 20), 2);

    $rows = ($this->stats)()->byTicketType($this->organization);

    $standard = $rows->firstWhere('name', 'Standard');
    $vip = $rows->firstWhere('name', 'VIP');

    expect($standard['sold'])->toBe(6)
        ->and($standard['revenue'])->toBe(60_000)
        ->and($standard['remaining'])->toBe(94)
        ->and($standard['share'])->toBe(75.0)
        ->and($vip['sold'])->toBe(2)
        ->and($vip['revenue'])->toBe(100_000)
        ->and($vip['share'])->toBe(25.0);
});

it('fait apparaitre une categorie jamais vendue', function () {
    ($this->sell)(($this->type)('Standard', 10_000, 100), 2);
    ($this->type)('Premium', 80_000, 10);

    $rows = ($this->stats)()->byTicketType($this->organization);
    $premium = $rows->firstWhere('name', 'Premium');

    // C'est souvent la categorie qui ne part pas qu'on cherche : la faire
    // disparaitre du tableau serait exactement contre-productif.
    expect($premium)->not->toBeNull()
        ->and($premium['sold'])->toBe(0)
        ->and($premium['remaining'])->toBe(10)
        ->and($premium['share'])->toBe(0.0);
});

it('classe les categories par volume vendu', function () {
    ($this->sell)(($this->type)('Standard', 10_000, 100), 2);
    ($this->sell)(($this->type)('VIP', 50_000, 50), 9);

    expect(($this->stats)()->byTicketType($this->organization)->first()['name'])->toBe('VIP');
});

/*
 * ---------------------------------------------------------------------------
 * Isolation et permissions
 * ---------------------------------------------------------------------------
 */

it('ne compte jamais les billets d une autre organisation', function () {
    ($this->sell)(($this->type)('Standard', 10_000, 100), 2);

    [$otherOwner, $other] = organizerWithOrganization('Autre association');

    $otherEvent = forOrganization($other, fn () => Event::factory()->published()->create());
    $otherType = forOrganization($other, fn () => TicketType::factory()->for($otherEvent)->create([
        'name' => 'Standard', 'price' => 10_000, 'quantity' => 100,
    ]));

    forOrganization($other, function () use ($otherEvent, $otherType) {
        $order = Order::factory()->for($otherEvent)->create(['subtotal' => 70_000, 'amount' => 70_000]);
        $order->items()->create([
            'ticket_type_id' => $otherType->id, 'ticket_type_name' => 'Standard',
            'quantity' => 7, 'unit_price' => 10_000, 'subtotal' => 70_000,
        ]);
        app(ConfirmPayment::class)->handle($order->load('items'));
    });

    app(TicketingStatistics::class)->forget($this->organization);

    // Regle 80.4 : meme nom de categorie, meme prix, et pourtant rien ne doit
    // se melanger.
    $stats = ($this->stats)()->forOrganization($this->organization);

    expect($stats['sold'])->toBe(2)
        ->and($stats['revenue'])->toBe(20_000)
        ->and(($this->stats)()->byTicketType($this->organization)
            ->firstWhere('name', 'Standard')['sold'])->toBe(2);
});

it('cache les montants a qui n a pas la permission financiere', function () {
    ($this->sell)(($this->type)('Standard', 10_000, 100), 3);

    // CO_ORGANIZER : « gestion operationnelle deleguee, sans acces aux
    // finances par defaut » (section 10). C'est exactement le cas a couvrir.
    $viewer = User::factory()->create();

    forOrganization($this->organization, fn () => $this->organization->members()->create([
        'user_id' => $viewer->id, 'joined_at' => now(),
    ]));

    actAsOrganization($this->organization);
    $viewer->assignRole(RoleName::CoOrganizer->value);

    $this->actingAs($viewer);

    expect($viewer->can(PermissionName::FinancesView->value))->toBeFalse();

    /*
     * Le decompte de billets reste visible, les montants non : un
     * CO_ORGANIZER ou un membre doit savoir combien de billets sont partis
     * pour organiser l'entree, sans voir les recettes (section 10).
     */
    Livewire::test(Overview::class)
        ->assertSee('Billets vendus', escape: false)
        ->assertDontSee('Recettes', escape: false)
        ->assertDontSee(Money::format(30_000), escape: false);
});

/*
 * ---------------------------------------------------------------------------
 * Performance
 * ---------------------------------------------------------------------------
 */

it('agrege en SQL sans charger les billets', function () {
    $type = ($this->type)('Standard', 10_000, 500);
    ($this->sell)($type, 200);

    app(TicketingStatistics::class)->forget($this->organization);

    DB::enableQueryLog();
    ($this->stats)()->forOrganization($this->organization);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    /*
     * Deux requetes : les ventes et la capacite. Aucune ne ramene de ligne de
     * billet. Compter en PHP marcherait six mois, puis ferait tomber le
     * dashboard le jour du premier gros evenement — c'est-a-dire le jour ou
     * l'organisateur le regarde le plus.
     */
    expect($queries)->toHaveCount(2);

    foreach ($queries as $query) {
        expect($query['query'])->toContain('select');
        expect(strtolower($query['query']))->toMatch('/count\(|sum\(/');
    }
});

it('sert les memes chiffres depuis le cache sans requeter', function () {
    ($this->sell)(($this->type)('Standard', 10_000, 100), 2);

    $first = ($this->stats)()->forOrganization($this->organization);

    DB::enableQueryLog();
    $second = ($this->stats)()->forOrganization($this->organization);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Un dashboard rafraichi en boucle ne doit pas reagreger a chaque fois.
    expect($second)->toBe($first)
        ->and($queries)->toBeEmpty();
});

/*
 * ---------------------------------------------------------------------------
 * Reversements affiches sur la vue generale
 * ---------------------------------------------------------------------------
 */

it('affiche les derniers reversements, quel que soit leur objet', function () {
    /*
     * REGRESSION DU 2026-08-27.
     *
     * La vue generale chargeait `with('event')` sur les reversements, relation
     * disparue quand le reversement est devenu polymorphe (decision D-041).
     * Aucun test ne l'a vu : Laravel ne resout une relation d'`eager loading`
     * que s'il y a des lignes a hydrater, et aucun test n'avait de reversement
     * sur son organisation. Le dashboard tombait donc uniquement chez ceux qui
     * en avaient un — c'est-a-dire en production, et nulle part ailleurs.
     */
    $event = $this->event;

    [$settlement, $campaign] = forOrganization($this->organization, function () use ($event) {
        $settlement = App\Models\Settlement::create([
            'settlable_type' => $event->getMorphClass(),
            'settlable_id' => $event->getKey(),
            'reference' => 'RV-TEST-100',
            'gross_amount' => 20_000,
            'commission_amount' => 1_000,
            'net_amount' => 19_000,
            'recipient_phone' => '074000000',
            'recipient_operator' => 'AIRTEL',
            'status' => App\Enums\PayoutState::Succeeded->value,
            'idempotency_key' => 'test-overview-1',
        ]);

        $campaign = App\Models\Campaign::factory()->published()->create(['name' => 'Collecte reversee']);

        App\Models\Settlement::create([
            'settlable_type' => $campaign->getMorphClass(),
            'settlable_id' => $campaign->getKey(),
            'reference' => 'RV-TEST-101',
            'gross_amount' => 50_000,
            'commission_amount' => 1_250,
            'net_amount' => 48_750,
            'recipient_phone' => '074000000',
            'recipient_operator' => 'AIRTEL',
            'status' => App\Enums\PayoutState::Succeeded->value,
            'idempotency_key' => 'test-overview-2',
        ]);

        return [$settlement, $campaign];
    });

    actAsOrganization($this->organization);

    // Les deux objets reversables s'affichent par leur nom, sur le meme ecran.
    Livewire::actingAs($this->owner)
        ->test(Overview::class)
        ->assertOk()
        ->assertSee($event->name)
        ->assertSee('Collecte reversee');
});
