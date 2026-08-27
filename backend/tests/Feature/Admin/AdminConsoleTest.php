<?php

use App\Actions\Payments\ConfirmPayment;
use App\Enums\PayoutState;
use App\Livewire\Admin\AuditTrail;
use App\Livewire\Admin\Organizations as AdminOrganizations;
use App\Livewire\Admin\Overview as AdminOverview;
use App\Livewire\Admin\Transactions as AdminTransactions;
use App\Models\Event;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Statistics\PlatformStatistics;
use App\Support\Money;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Console d'administration (section 58).
 *
 * Quatre ecrans qui traversent tous les organisations. Ces tests verifient que
 * personne d'autre n'y accede, que les chiffres sont justes, et qu'aucun ecran
 * n'expose de donnee personnelle inutile (section 52).
 */

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();

    $this->sellFor = function (string $name, int $quantity, int $price = 10_000) {
        [$owner, $organization] = organizerWithOrganization($name);

        [$event, $type] = forOrganization($organization, function () use ($price) {
            $event = Event::factory()->published()->create();

            return [$event, TicketType::factory()->for($event)->create([
                'name' => 'Standard', 'price' => $price, 'quantity' => 500,
            ])];
        });

        forOrganization($organization, function () use ($event, $type, $quantity, $price) {
            $subtotal = $price * $quantity;

            $order = Order::factory()->for($event)->create([
                'subtotal' => $subtotal,
                'amount' => $subtotal + 200,
                'psp_fee_amount' => 200,
                'payer_phone' => '074999888',
            ]);

            $order->items()->create([
                'ticket_type_id' => $type->id, 'ticket_type_name' => 'Standard',
                'quantity' => $quantity, 'unit_price' => $price, 'subtotal' => $subtotal,
            ]);

            app(ConfirmPayment::class)->handle($order->load('items'));
        });

        app(OrganizationContext::class)->forget();

        return [$owner, $organization];
    };
});

afterEach(function () {
    app(PlatformStatistics::class)->forget();
});

/*
 * ---------------------------------------------------------------------------
 * Acces
 * ---------------------------------------------------------------------------
 */

it('ferme les quatre ecrans a un organisateur', function () {
    [$owner] = ($this->sellFor)('Association test', 1);

    foreach ([
        '/administration',
        '/administration/organisations',
        '/administration/transactions',
        '/administration/journal',
    ] as $path) {
        // 404 et non 403 : l'existence de la console n'a pas a etre confirmee.
        $this->actingAs($owner)->get($path)->assertNotFound();
    }
});

it('ouvre les quatre ecrans au super admin', function () {
    ($this->sellFor)('Association test', 1);

    foreach ([
        '/administration',
        '/administration/organisations',
        '/administration/transactions',
        '/administration/journal',
    ] as $path) {
        $this->actingAs($this->admin)->get($path)->assertOk();
    }
});

/*
 * ---------------------------------------------------------------------------
 * Vue generale
 * ---------------------------------------------------------------------------
 */

it('distingue la commission Pass241 du volume qui transite', function () {
    ($this->sellFor)('Association A', 4);
    ($this->sellFor)('Studio B', 3);

    app(PlatformStatistics::class)->forget();

    $totals = app(PlatformStatistics::class)->totals();

    /*
     * 7 billets a 10 000 = 70 000 de volume, 5 % = 3 500 de commission.
     *
     * Le volume transite par la plateforme sans lui appartenir (section 21.2).
     * Confondre les deux donnerait a Pass241 un chiffre d'affaires imaginaire
     * de 70 000.
     */
    expect($totals['grossVolume'])->toBe(70_000)
        ->and($totals['commission'])->toBe(3_500)
        ->and($totals['ticketsSold'])->toBe(7)
        ->and($totals['organizations'])->toBe(2);
});

it('remonte ce qui demande une intervention sans passer par le cache', function () {
    [, $organization] = ($this->sellFor)('Association test', 2);

    expect(app(PlatformStatistics::class)->attention()['settlements'])->toBe(0);

    forOrganization($organization, fn () => Settlement::create([
        'event_id' => Event::query()->acrossOrganizations()->value('id'),
        'reference' => 'RV-TEST-001',
        'gross_amount' => 20_000, 'commission_amount' => 1_000, 'net_amount' => 19_000,
        'recipient_phone' => '074000000', 'recipient_operator' => 'AIRTEL',
        'status' => PayoutState::Failed->value,
        'idempotency_key' => 'test-key-1',
    ]));

    // Non cache : un reversement en echec doit apparaitre a l'instant ou il
    // echoue, pas cinq minutes plus tard.
    expect(app(PlatformStatistics::class)->attention()['settlements'])->toBe(1);
});

/*
 * ---------------------------------------------------------------------------
 * Organisations
 * ---------------------------------------------------------------------------
 */

it('classe les organisations par volume et compte leurs membres', function () {
    ($this->sellFor)('Petite association', 1);
    ($this->sellFor)('Gros organisateur', 9);

    $this->actingAs($this->admin);

    $component = Livewire::test(AdminOrganizations::class);

    $first = $component->viewData('organizations')->first();

    expect($first->name)->toBe('Gros organisateur')
        ->and((int) $first->tickets_sold)->toBe(9)
        ->and((int) $first->gross)->toBe(90_000)
        // Regression : `select()` appele apres `withCount` emportait la
        // sous-requete de comptage, et le compteur ressortait vide.
        ->and((int) $first->members_count)->toBe(1);
});

it('filtre les organisations sans numero de reversement', function () {
    ($this->sellFor)('Association test', 1);

    $this->actingAs($this->admin);

    // Le seul etat sur lequel il y a quelque chose a faire : sans numero,
    // aucun reversement ne peut partir.
    Livewire::test(AdminOrganizations::class)
        ->set('filter', 'no-payout')
        ->assertSee('Association test', escape: false);
});

/*
 * ---------------------------------------------------------------------------
 * Transactions
 * ---------------------------------------------------------------------------
 */

it('detaille billets et frais separement sur les transactions', function () {
    ($this->sellFor)('Association test', 2);

    $this->actingAs($this->admin);

    // Les frais du prestataire ne sont pas le prix des billets : les confondre
    // fausserait toute reconciliation (decision D-023).
    Livewire::test(AdminTransactions::class)
        ->assertSee(Money::format(20_000), escape: false)
        ->assertSee(Money::format(200), escape: false)
        ->assertSee(Money::format(20_200), escape: false);
});

it('n expose jamais le numero de l acheteur', function () {
    ($this->sellFor)('Association test', 1);

    $this->actingAs($this->admin);

    /*
     * Section 52 : cet ecran sert a reperer un ecart de montant ou un statut
     * anormal, pas a retrouver quelqu'un. Le numero n'y figure pas, meme
     * partiellement.
     */
    Livewire::test(AdminTransactions::class)
        ->assertDontSee('074999888', escape: false)
        ->assertDontSee('9888', escape: false);
});

/*
 * ---------------------------------------------------------------------------
 * Journal
 * ---------------------------------------------------------------------------
 */

it('affiche le journal en clair et sans numero complet', function () {
    ($this->sellFor)('Association test', 1);

    $this->actingAs($this->admin);

    /*
     * Les libelles affiches sont lisibles. La valeur brute de l'enum figure
     * bien dans l'attribut `value` des options du filtre — c'est sa place, et
     * l'y chercher ne prouverait rien.
     */
    Livewire::test(AuditTrail::class)
        ->assertSee('Paiement confirme', escape: false)
        ->assertSee('Billet emis', escape: false)
        ->assertSee('>Paiement confirme<', escape: false)
        // Section 52 : le journal masque les numeros a l'ecriture, pas a
        // l'affichage. Ce test verifie que la donnee ecrite est deja propre.
        ->assertDontSee('074999888', escape: false);
});

it('n expose aucune action destructive sur le journal', function () {
    $methods = collect((new ReflectionClass(AuditTrail::class))
        ->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $method) => $method->getName());

    // Un journal qu'on peut nettoyer ne prouve rien (section 67).
    foreach (['delete', 'purge', 'clear', 'destroy', 'prune'] as $forbidden) {
        expect($methods)->not->toContain($forbidden);
    }
});

it('n expose aucune action modifiant une organisation ou une transaction', function () {
    foreach ([AdminOrganizations::class, AdminTransactions::class, AdminOverview::class] as $screen) {
        $methods = collect((new ReflectionClass($screen))
            ->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(fn (ReflectionMethod $method) => $method->getName());

        foreach (['suspend', 'delete', 'refund', 'update', 'save'] as $forbidden) {
            expect($methods)->not->toContain($forbidden);
        }
    }
});
