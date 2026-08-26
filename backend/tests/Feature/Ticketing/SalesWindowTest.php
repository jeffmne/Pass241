<?php

use App\Actions\Orders\CreateOrder;
use App\Enums\PaymentProvider;
use App\Livewire\Public\Checkout;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Periode de vente d'un type de billet (section 17).
 *
 * Quatre etats a distinguer, et la distinction n'est pas cosmetique : « vente
 * terminee » et « complet » n'appellent pas la meme reaction de l'acheteur.
 * Le premier lui dit de renoncer, le second de revenir voir si une place se
 * libere.
 *
 * LA VERIFICATION EST FAITE COTE SERVEUR. Le motif affiche a l'ecran est un
 * confort ; c'est CreateOrder qui refuse, et lui seul compte — un formulaire
 * se forge.
 */

beforeEach(function () {
    Queue::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->event = forOrganization($this->organization, fn () => Event::factory()
        ->published()
        ->create([
            'name' => 'Concert Afro 2026',
            'starts_at' => now()->addDays(30),
            'ends_at' => now()->addDays(30)->addHours(4),
        ]));

    $this->ticketType = function (array $window = []): TicketType {
        return forOrganization($this->organization, fn () => TicketType::factory()
            ->for($this->event)
            ->create(array_merge([
                'name' => 'Early Bird',
                'price' => 10_000,
                'quantity' => 50,
            ], $window)));
    };

    $this->order = function (TicketType $type, int $quantity = 1) {
        return app(CreateOrder::class)->handle(
            event: $this->event->fresh(),
            quantities: [$type->id => $quantity],
            firstName: 'Awa',
            lastName: 'Ndong',
            email: null,
            provider: PaymentProvider::Airtel,
            payerPhone: '074000000',
        );
    };

    app(OrganizationContext::class)->forget();
});

/*
 * ---------------------------------------------------------------------------
 * Les quatre etats
 * ---------------------------------------------------------------------------
 */

it('refuse la vente avant la date de debut', function () {
    $type = ($this->ticketType)([
        'sales_start_at' => now()->addDays(3),
        'sales_end_at' => now()->addDays(10),
    ]);

    expect($type->isPurchasable())->toBeFalse()
        ->and($type->salesHaveStarted())->toBeFalse()
        // Le message donne la date : l'acheteur doit savoir quand revenir,
        // pas seulement qu'il ne peut pas acheter.
        ->and($type->unavailabilityReason())->toContain('Vente a partir du');
});

it('autorise la vente pendant la periode', function () {
    $type = ($this->ticketType)([
        'sales_start_at' => now()->subDay(),
        'sales_end_at' => now()->addDays(10),
    ]);

    expect($type->isPurchasable())->toBeTrue()
        ->and($type->unavailabilityReason())->toBeNull();
});

it('refuse la vente apres la date de fin', function () {
    $type = ($this->ticketType)([
        'sales_start_at' => now()->subDays(10),
        'sales_end_at' => now()->subMinute(),
    ]);

    expect($type->isPurchasable())->toBeFalse()
        ->and($type->salesHaveEnded())->toBeTrue()
        ->and($type->unavailabilityReason())->toBe('La vente est terminee.');
});

it('distingue vente terminee et complet', function () {
    $ended = ($this->ticketType)([
        'name' => 'Early Bird',
        'sales_end_at' => now()->subMinute(),
    ]);

    $soldOut = ($this->ticketType)(['name' => 'VIP', 'quantity' => 1]);

    ($this->order)($soldOut, 1);

    /*
     * Les deux ferment l'achat, mais ne disent pas la meme chose a
     * l'acheteur : « terminee » lui dit de renoncer, « complet » de revenir
     * voir si une place se libere. Les confondre lui ferait perdre son temps
     * ou lui ferait manquer une place.
     */
    expect($ended->unavailabilityReason())->toBe('La vente est terminee.')
        ->and($soldOut->fresh()->unavailabilityReason())->toBe('Complet');
});

it('vend sans limite de date quand aucune periode n est fixee', function () {
    $type = ($this->ticketType)();

    // Une periode non renseignee ne doit pas fermer la vente : c'est le cas le
    // plus courant, un billet en vente jusqu'a l'evenement.
    expect($type->sales_start_at)->toBeNull()
        ->and($type->sales_end_at)->toBeNull()
        ->and($type->isPurchasable())->toBeTrue();
});

/*
 * ---------------------------------------------------------------------------
 * Le serveur tranche, jamais le formulaire
 * ---------------------------------------------------------------------------
 */

it('refuse une commande passee avant l ouverture des ventes', function () {
    $type = ($this->ticketType)(['sales_start_at' => now()->addDays(3)]);

    // Le formulaire peut etre forge : c'est CreateOrder qui refuse.
    expect(fn () => ($this->order)($type))
        ->toThrow(RuntimeException::class, 'Vente a partir du');

    expect(Order::query()->acrossOrganizations()->count())->toBe(0);
});

it('refuse une commande passee apres la cloture des ventes', function () {
    $type = ($this->ticketType)(['sales_end_at' => now()->subMinute()]);

    expect(fn () => ($this->order)($type))
        ->toThrow(RuntimeException::class, 'vente est terminee');

    expect(Order::query()->acrossOrganizations()->count())->toBe(0);
});

it('refuse une commande dont la periode se ferme entre l affichage et la validation', function () {
    $type = ($this->ticketType)(['sales_end_at' => now()->addMinutes(5)]);

    expect($type->isPurchasable())->toBeTrue();

    // L'acheteur a laisse la page ouverte. Le stock etait la, la periode ne
    // l'est plus.
    $this->travel(6)->minutes();

    expect(fn () => ($this->order)($type->fresh()))
        ->toThrow(RuntimeException::class, 'vente est terminee');
});

/*
 * ---------------------------------------------------------------------------
 * Integrite en base
 * ---------------------------------------------------------------------------
 */

it('refuse en base une fin de vente anterieure a son debut', function () {
    // Une periode inversee ne ferme pas la vente, elle la rend incoherente :
    // la base la refuse, quelle que soit la couche qui l'a laissee passer.
    expect(fn () => forOrganization($this->organization, fn () => DB::transaction(
        fn () => TicketType::factory()->for($this->event)->create([
            'sales_start_at' => now()->addDays(10),
            'sales_end_at' => now()->addDays(2),
        ])
    )))->toThrow(QueryException::class);
});

/*
 * ---------------------------------------------------------------------------
 * Ecran d'achat
 * ---------------------------------------------------------------------------
 */

it('affiche le motif de fermeture au lieu des boutons de quantite', function () {
    ($this->ticketType)(['name' => 'Early Bird', 'sales_end_at' => now()->subMinute()]);

    $event = Event::publicBySlug($this->event->slug);

    Livewire::test(Checkout::class, ['event' => $event])
        ->assertSee('La vente est terminee.', escape: false)
        // Le billet reste visible : le masquer laisserait croire qu'il n'a
        // jamais existe, alors qu'il a peut-etre ete vu la veille.
        ->assertSee('Early Bird', escape: false);
});

it('annonce la date d ouverture quand la vente n a pas commence', function () {
    ($this->ticketType)([
        'name' => 'Early Bird',
        'sales_start_at' => now()->addDays(3)->setTime(9, 0),
    ]);

    $event = Event::publicBySlug($this->event->slug);

    Livewire::test(Checkout::class, ['event' => $event])
        ->assertSee('Vente a partir du', escape: false);
});
