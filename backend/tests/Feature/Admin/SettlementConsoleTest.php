<?php

use App\Actions\Orders\ConfirmPayment;
use App\Actions\Organizations\SetPayoutDestination;
use App\Actions\Settlements\SettleEvent;
use App\Enums\PaymentProvider;
use App\Enums\PayoutState;
use App\Livewire\Admin\Settlements as AdminSettlements;
use App\Models\Event;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\TicketType;
use App\Models\User;
use App\Support\Money;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Console des reversements, reservee au Super Admin (section 58).
 *
 * SEUL ECRAN DU PRODUIT QUI TRAVERSE LES ORGANISATIONS. Ces tests verifient
 * surtout deux choses : que personne d'autre n'y accede, et qu'aucune action
 * n'y modifie une donnee financiere.
 */

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();

    $this->settleFor = function (string $name, int $quantity): Settlement {
        [$owner, $organization] = organizerWithOrganization($name);

        [$event, $ticketType] = forOrganization($organization, function () {
            $event = Event::factory()->published()->create([
                'starts_at' => now()->subDays(3),
                'ends_at' => now()->subDays(2),
            ]);

            return [$event, TicketType::factory()->for($event)->create([
                'price' => 10_000, 'quantity' => 100,
            ])];
        });

        app(SetPayoutDestination::class)->handle(
            $organization, '074000000', PaymentProvider::Airtel, $owner,
        );

        forOrganization($organization, function () use ($event, $ticketType, $quantity) {
            $subtotal = 10_000 * $quantity;

            $order = Order::factory()->for($event)->create([
                'subtotal' => $subtotal,
                'amount' => $subtotal,
            ]);

            $order->items()->create([
                'ticket_type_id' => $ticketType->id,
                'ticket_type_name' => $ticketType->name,
                'quantity' => $quantity,
                'unit_price' => 10_000,
                'subtotal' => $subtotal,
            ]);

            app(ConfirmPayment::class)->handle($order->load('items'));
        });

        $settlement = app(SettleEvent::class)->handle($event->fresh()->load('organization'));

        app(OrganizationContext::class)->forget();

        return $settlement;
    };
});

/*
 * ---------------------------------------------------------------------------
 * Acces
 * ---------------------------------------------------------------------------
 */

it('repond 404 a un organisateur', function () {
    [$owner] = organizerWithOrganization('Association test');

    app(OrganizationContext::class)->forget();

    // 404 et non 403 : l'existence meme de la console n'a pas a etre confirmee
    // a qui n'y a pas acces.
    $this->actingAs($owner)->get('/administration/reversements')->assertNotFound();
});

it('redirige un visiteur non connecte', function () {
    $this->get('/administration/reversements')->assertRedirect(route('login'));
});

it('laisse entrer le super admin', function () {
    $this->actingAs($this->admin)
        ->get('/administration/reversements')
        ->assertOk()
        ->assertSee('Reversements', escape: false);
});

/*
 * ---------------------------------------------------------------------------
 * Vue transverse
 * ---------------------------------------------------------------------------
 */

it('voit les reversements de toutes les organisations', function () {
    ($this->settleFor)('Association A', 2);
    ($this->settleFor)('Studio B', 3);

    $this->actingAs($this->admin);

    /*
     * C'est le seul endroit du produit ou la portee multi-tenant est levee de
     * facon assumee (regle 80.4). Partout ailleurs, voir deux organisations
     * dans la meme liste serait une faille.
     */
    Livewire::test(AdminSettlements::class)
        ->assertSee('Association A', escape: false)
        ->assertSee('Studio B', escape: false);
});

it('agrege les montants par etat en une requete', function () {
    ($this->settleFor)('Association A', 2);
    ($this->settleFor)('Studio B', 3);

    $this->actingAs($this->admin);

    // 5 billets a 10 000, moins 5 % : 47 500 verses au total.
    Livewire::test(AdminSettlements::class)
        ->assertSee(Money::format(47_500), escape: false);
});

it('filtre les reversements a verifier', function () {
    $ok = ($this->settleFor)('Association A', 2);
    $broken = ($this->settleFor)('Studio B', 3);

    forOrganization($broken->organization, fn () => $broken->forceFill([
        'status' => PayoutState::Failed->value,
        'failed_at' => now(),
    ])->save());

    $this->actingAs($this->admin);

    // Echec et etat inconnu sont les deux seuls cas ou quelqu'un doit se
    // pencher sur un virement.
    Livewire::test(AdminSettlements::class)
        ->set('filter', 'attention')
        ->assertSee($broken->reference, escape: false)
        ->assertDontSee($ok->reference, escape: false);
});

it('retrouve un reversement par sa reference', function () {
    $target = ($this->settleFor)('Association A', 2);
    $other = ($this->settleFor)('Studio B', 3);

    $this->actingAs($this->admin);

    Livewire::test(AdminSettlements::class)
        ->set('search', $target->reference)
        ->assertSee($target->reference, escape: false)
        ->assertDontSee($other->reference, escape: false);
});

/*
 * ---------------------------------------------------------------------------
 * Consultation seule
 * ---------------------------------------------------------------------------
 */

it('n expose aucune action modifiant un reversement', function () {
    ($this->settleFor)('Association A', 2);

    $this->actingAs($this->admin);

    /*
     * Section 58 : « les donnees financieres historiques restent protegees
     * contre les modifications arbitraires ». Ici cela veut dire qu'aucune
     * methode n'existe, pas qu'un bouton est desactive — un bouton grise
     * laisserait croire qu'un humain peut liberer des fonds a la demande.
     */
    $methods = collect((new ReflectionClass(AdminSettlements::class))
        ->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $method) => $method->getName());

    foreach (['approve', 'retry', 'cancel', 'settle', 'markAsPaid', 'delete'] as $forbidden) {
        expect($methods)->not->toContain($forbidden);
    }
});

it('n expose jamais le numero de destination', function () {
    ($this->settleFor)('Association A', 2);

    $this->actingAs($this->admin);

    // Section 52 : un ecran de surveillance n'a pas besoin du numero complet
    // pour reperer un ecart.
    Livewire::test(AdminSettlements::class)->assertDontSee('074000000', escape: false);
});
