<?php

use App\Enums\RoleName;
use App\Livewire\Dashboard\Events\TicketTypes as TicketTypesScreen;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use Livewire\Livewire;

/*
 * Ecran des types de billets (section 17).
 *
 * CE FICHIER N'EXISTAIT PAS, et c'est ce qui a laissé passer le defaut du
 * 2026-08-27 : le gabarit appelait `remainingStock()`, methode qui n'a jamais
 * existe sur le modele. Rien ne rendait cet ecran AVEC un type de billet — un
 * ecran vide ne touche pas la boucle d'affichage, et le defaut restait
 * invisible jusqu'au premier tarif cree.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->event = forOrganization($this->organization, fn () => Event::factory()
        ->published()
        ->create(['name' => 'Concert Afro 2026']));

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $this->screen = fn () => Livewire::test(TicketTypesScreen::class, ['event' => $this->event]);
});

it('affiche un type de billet existant', function () {
    forOrganization($this->organization, fn () => TicketType::factory()
        ->for($this->event)
        ->create(['name' => 'Standard', 'price' => 10_000, 'quantity' => 100]));

    // La regression : ce rendu appelait une methode inexistante.
    ($this->screen)()
        ->assertOk()
        ->assertSee('Standard')
        ->assertSee('100 restants');
});

it('cree un type de billet depuis l ecran', function () {
    ($this->screen)()
        ->call('create')
        ->set('name', 'VIP')
        ->set('price', 50_000)
        ->set('quantity', 40)
        ->set('maxPerOrder', 4)
        ->call('save')
        ->assertHasNoErrors();

    $type = TicketType::query()->firstOrFail();

    expect($type->name)->toBe('VIP')
        ->and($type->price)->toBe(50_000)
        ->and($type->quantity)->toBe(40);
});

it('affiche le rendu complet apres creation', function () {
    /*
     * Le defaut ne se voyait qu'au rendu SUIVANT la creation : l'ecriture
     * passait, puis la liste explosait. C'est exactement ce que reproduit cet
     * enchainement.
     */
    ($this->screen)()
        ->call('create')
        ->set('name', 'Standard')
        ->set('price', 0)
        ->set('quantity', 100)
        ->call('save')
        ->assertHasNoErrors()
        ->assertOk()
        ->assertSee('Standard');
});

it('retranche du restant les billets vendus et ceux en cours de paiement', function () {
    $type = forOrganization($this->organization, fn () => TicketType::factory()
        ->for($this->event)
        ->create(['name' => 'Standard', 'price' => 10_000, 'quantity' => 10]));

    forOrganization($this->organization, function () use ($type) {
        // Une commande payee : deux billets partis.
        $paid = Order::factory()->for($this->event)->paid()->create(['amount' => 20_000, 'subtotal' => 20_000]);
        $paid->items()->create([
            'ticket_type_id' => $type->id, 'ticket_type_name' => $type->name,
            'quantity' => 2, 'unit_price' => 10_000, 'subtotal' => 20_000,
        ]);

        // Une commande en cours de paiement : un billet immobilise (D-017).
        $pending = Order::factory()->for($this->event)->create(['amount' => 10_000, 'subtotal' => 10_000]);
        $pending->items()->create([
            'ticket_type_id' => $type->id, 'ticket_type_name' => $type->name,
            'quantity' => 1, 'unit_price' => 10_000, 'subtotal' => 10_000,
        ]);
    });

    /*
     * L'organisateur voit le meme chiffre que le public : 10 − 2 vendus − 1
     * reserve. Afficher 8 ici ferait naitre la question « pourquoi la page
     * annonce-t-elle moins de places que mon tableau de bord ? ».
     */
    ($this->screen)()
        ->assertOk()
        ->assertSee('2 / 10 vendus')
        ->assertSee('7 restants');
});

it('ferme l ecran a qui ne peut pas gerer la billetterie', function () {
    $agent = addMemberTo($this->organization, RoleName::AccessAgent);

    actAsOrganization($this->organization);
    $this->actingAs($agent);

    $this->get(route('events.ticket-types', $this->event))->assertForbidden();
});
