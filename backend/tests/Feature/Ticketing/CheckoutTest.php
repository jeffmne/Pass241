<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Livewire\Public\Checkout;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Support\Tenancy\OrganizationContext;
use Livewire\Livewire;

/*
 * Parcours d'achat public (sections 15, 16, 16.1).
 *
 * Le composant est ouvert a tous, sans compte. Il ne doit faire confiance a
 * rien de ce qu'il recoit : ces tests portent autant sur ce qu'il accepte que
 * sur ce qu'il refuse.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->standard, $this->vip] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create([
            'name' => 'Concert Afro 2026',
            'slug' => 'concert-afro-2026',
        ]);

        return [
            $event,
            TicketType::factory()->for($event)->create(['name' => 'Standard', 'price' => 10_000, 'quantity' => 50]),
            TicketType::factory()->for($event)->vip()->create(['quantity' => 10]),
        ];
    });

    app(OrganizationContext::class)->forget();
    setPermissionsTeamId(null);
});

it('affiche les billets sur la page publique de l evenement', function () {
    $this->get('/e/concert-afro-2026')
        ->assertOk()
        ->assertSee('Standard', escape: false)
        ->assertSee('VIP', escape: false)
        ->assertSee('10', escape: false);
});

it('ne montre pas les billets masques', function () {
    forOrganization($this->organization, fn () => TicketType::factory()
        ->for($this->event)->hidden()->create(['name' => 'Billet reserve aux invites']));

    // Section 17 : un type masque reste vendable par un autre canal, mais
    // n'apparait pas sur la page de l'evenement.
    $this->get('/e/concert-afro-2026')
        ->assertOk()
        ->assertDontSee('Billet reserve aux invites', escape: false);
});

it('calcule le total en additionnant les billets choisis', function () {
    Livewire::test(Checkout::class, ['event' => $this->event])
        ->call('increment', $this->standard->id)
        ->call('increment', $this->standard->id)
        ->call('increment', $this->vip->id)
        // 2 x 10 000 + 1 x 50 000
        ->assertSee('70');
});

it('borne la quantite par la limite de l organisateur', function () {
    forOrganization($this->organization, fn () => $this->standard->update(['max_per_order' => 2]));

    $component = Livewire::test(Checkout::class, ['event' => $this->event->fresh()]);

    // Le bornage est fait cote serveur : la limite ne peut pas reposer sur
    // l'attribut `max` d'un champ modifiable dans le navigateur.
    foreach (range(1, 5) as $ignored) {
        $component->call('increment', $this->standard->id);
    }

    expect($component->get('quantities')[$this->standard->id])->toBe(2);
});

it('ne descend jamais sous zero', function () {
    $component = Livewire::test(Checkout::class, ['event' => $this->event])
        ->call('decrement', $this->standard->id)
        ->call('decrement', $this->standard->id);

    expect($component->get('quantities')[$this->standard->id])->toBe(0);
});

it('refuse de commander sans avoir choisi de billet', function () {
    Livewire::test(Checkout::class, ['event' => $this->event])
        ->set('firstName', 'Awa')
        ->set('lastName', 'Ndong')
        ->set('provider', 'AIRTEL')
        ->set('payerPhone', '074000000')
        ->call('placeOrder')
        ->assertHasErrors(['quantities']);

    expect(Order::query()->acrossOrganizations()->count())->toBe(0);
});

it('exige le nom, le prenom, l operateur et le numero', function () {
    Livewire::test(Checkout::class, ['event' => $this->event])
        ->call('increment', $this->standard->id)
        ->call('placeOrder')
        ->assertHasErrors(['firstName', 'lastName', 'provider', 'payerPhone']);
});

it('n exige pas d adresse email', function () {
    // Section 15.1 : un achat par mobile money ne doit jamais etre bloque par
    // l'absence d'email.
    Livewire::test(Checkout::class, ['event' => $this->event])
        ->call('increment', $this->standard->id)
        ->set('firstName', 'Awa')
        ->set('lastName', 'Ndong')
        ->set('email', '')
        ->set('provider', 'AIRTEL')
        ->set('payerPhone', '074000000')
        ->call('placeOrder')
        ->assertHasNoErrors();

    $order = Order::query()->acrossOrganizations()->firstOrFail();

    expect($order->email)->toBeNull()
        ->and($order->status)->toBe(OrderStatus::Pending);
});

it('refuse un numero de telephone mal forme', function () {
    Livewire::test(Checkout::class, ['event' => $this->event])
        ->call('increment', $this->standard->id)
        ->set('firstName', 'Awa')
        ->set('lastName', 'Ndong')
        ->set('provider', 'AIRTEL')
        ->set('payerPhone', 'appelez-moi')
        ->call('placeOrder')
        ->assertHasErrors(['payerPhone']);
});

it('refuse un operateur invente', function () {
    Livewire::test(Checkout::class, ['event' => $this->event])
        ->call('increment', $this->standard->id)
        ->set('firstName', 'Awa')
        ->set('lastName', 'Ndong')
        ->set('provider', 'BITCOIN')
        ->set('payerPhone', '074000000')
        ->call('placeOrder')
        ->assertHasErrors(['provider']);
});

it('verifie le numero contre l operateur choisi quand les prefixes sont connus', function () {
    // Section 16.1 : si l'acheteur choisit Airtel mais saisit un numero Moov,
    // la commande est rejetee avant toute tentative de paiement.
    config()->set('payments.prefixes.AIRTEL', ['074', '077']);
    config()->set('payments.prefixes.MOOV', ['062', '065']);

    Livewire::test(Checkout::class, ['event' => $this->event])
        ->call('increment', $this->standard->id)
        ->set('firstName', 'Awa')
        ->set('lastName', 'Ndong')
        ->set('provider', 'AIRTEL')
        ->set('payerPhone', '062000000')
        ->call('placeOrder')
        ->assertHasErrors(['payerPhone']);

    Livewire::test(Checkout::class, ['event' => $this->event])
        ->call('increment', $this->standard->id)
        ->set('firstName', 'Awa')
        ->set('lastName', 'Ndong')
        ->set('provider', 'AIRTEL')
        ->set('payerPhone', '074000000')
        ->call('placeOrder')
        ->assertHasNoErrors();
});

it('accepte tout numero bien forme tant que les prefixes ne sont pas renseignes', function () {
    // Etat par defaut, volontairement : mieux vaut laisser passer un numero a
    // verifier plus tard que refuser un vrai client sur une donnee inventee.
    expect(config('payments.prefixes.AIRTEL'))->toBe([]);

    Livewire::test(Checkout::class, ['event' => $this->event])
        ->call('increment', $this->standard->id)
        ->set('firstName', 'Awa')
        ->set('lastName', 'Ndong')
        ->set('provider', 'AIRTEL')
        ->set('payerPhone', '+241 62 00 00 00')
        ->call('placeOrder')
        ->assertHasNoErrors();
});

it('renvoie vers le suivi de commande apres validation', function () {
    Livewire::test(Checkout::class, ['event' => $this->event])
        ->call('increment', $this->standard->id)
        ->set('firstName', 'Awa')
        ->set('lastName', 'Ndong')
        ->set('provider', 'AIRTEL')
        ->set('payerPhone', '074000000')
        ->call('placeOrder')
        ->assertHasNoErrors()
        // Lien signe : l'acheteur n'a pas de compte, c'est ce lien qui le
        // ramenera a ses billets (decision D-004).
        ->assertRedirect(Order::query()->acrossOrganizations()->firstOrFail()->statusUrl());
});

it('declenche le paiement et retient la reference de l operateur', function () {
    Livewire::test(Checkout::class, ['event' => $this->event])
        ->call('increment', $this->standard->id)
        ->set('firstName', 'Awa')
        ->set('lastName', 'Ndong')
        ->set('provider', 'AIRTEL')
        ->set('payerPhone', '074000000')
        ->call('placeOrder')
        ->assertHasNoErrors();

    $order = Order::query()->acrossOrganizations()->firstOrFail();

    // Section 21.1 : le push USSD part des la commande creee, et son
    // identifiant est conserve pour la reconciliation.
    expect($order->provider_reference)->not->toBeNull()
        ->and($order->provider_reference)->toStartWith('FAKE-');
});

it('explique quand le dernier billet vient d etre pris', function () {
    $last = forOrganization($this->organization, fn () => TicketType::factory()
        ->for($this->event)->lastOne()->create(['name' => 'Derniere place']));

    // Un premier acheteur reserve le dernier billet.
    Livewire::test(Checkout::class, ['event' => $this->event->fresh()])
        ->call('increment', $last->id)
        ->set('firstName', 'Awa')->set('lastName', 'Ndong')
        ->set('provider', 'AIRTEL')->set('payerPhone', '074000000')
        ->call('placeOrder')
        ->assertHasNoErrors();

    // Le second doit comprendre ce qui s'est passe, pas voir une erreur muette.
    Livewire::test(Checkout::class, ['event' => $this->event->fresh()])
        ->call('increment', $last->id)
        ->set('firstName', 'Paul')->set('lastName', 'Obame')
        ->set('provider', 'MOOV')->set('payerPhone', '062000000')
        ->call('placeOrder')
        ->assertHasErrors(['quantities']);

    expect(Order::query()->acrossOrganizations()->count())->toBe(1);
});

it('affiche complet sur la page publique quand tout est vendu', function () {
    // Regression : hors contexte d'organisation, le decompte des ventes
    // renvoyait zero et un evenement complet affichait des places
    // disponibles. Le visiteur serait alle jusqu'au paiement pour rien.
    $limited = forOrganization($this->organization, function () {
        $ticketType = TicketType::factory()->for($this->event)->create([
            'name' => 'Place unique',
            'quantity' => 2,
        ]);

        $order = Order::factory()->for($this->event)->paid()->create();
        $order->items()->create([
            'ticket_type_id' => $ticketType->id,
            'ticket_type_name' => $ticketType->name,
            'quantity' => 2,
            'unit_price' => $ticketType->price,
            'subtotal' => $ticketType->price * 2,
        ]);

        return $ticketType;
    });

    app(OrganizationContext::class)->forget();

    $fromPublicPage = $this->event->fresh()->publicTicketTypes()->firstWhere('id', $limited->id);

    expect($fromPublicPage->soldCount())->toBe(2)
        ->and($fromPublicPage->availableStock())->toBe(0)
        ->and($fromPublicPage->isPurchasable())->toBeFalse();

    $this->get('/e/concert-afro-2026')
        ->assertOk()
        ->assertSee('Complet', escape: false);
});

it('ferme le parcours d achat sur un evenement annule', function () {
    forOrganization($this->organization, fn () => $this->event->forceFill([
        'status' => EventStatus::Cancelled,
        'cancelled_at' => now(),
        'cancellation_reason' => 'Salle indisponible.',
    ])->save());

    $this->get('/e/concert-afro-2026')
        ->assertOk()
        ->assertSee('La vente est fermee', escape: false)
        ->assertDontSee('Continuer vers le paiement', escape: false);
});
