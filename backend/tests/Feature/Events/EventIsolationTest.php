<?php

use App\Enums\RoleName;
use App\Livewire\Dashboard\Events\Form;
use App\Livewire\Dashboard\Events\Index;
use App\Models\Event;
use Livewire\Livewire;

/*
 * Isolation multi-tenant appliquee aux evenements (sections 9, 74, 80.4).
 *
 * Les tests du jalon precedent prouvaient le mecanisme sur un modele isole.
 * Ceux-ci prouvent qu'il tient sur le premier vrai module du produit, y compris
 * a travers le dashboard et la liaison de route.
 */

beforeEach(function () {
    [$this->userA, $this->orgA] = organizerWithOrganization('Organisation A');
    [$this->userB, $this->orgB] = organizerWithOrganization('Organisation B');

    // `forOrganization()` place le contexte : le trait renseigne alors
    // `organization_id` lui-meme, exactement comme en production.
    $this->eventA = forOrganization($this->orgA, fn () => Event::factory()->create([
        'name' => 'Gala de A', 'slug' => 'gala-de-a',
    ]));

    $this->eventB = forOrganization($this->orgB, fn () => Event::factory()->create([
        'name' => 'Gala de B', 'slug' => 'gala-de-b',
    ]));
});

it('ne liste que les evenements de l organisation courante', function () {
    $this->actingAs($this->userA);

    // Un test de composant ne traverse pas le middleware : le contexte et la
    // portee des roles doivent etre poses a la main, comme le ferait
    // EnsureOrganizationContext sur une vraie requete.
    actAsOrganization($this->orgA);

    Livewire::test(Index::class)
        ->assertSee('Gala de A')
        ->assertDontSee('Gala de B');
});

it('renvoie 404 quand on ouvre l evenement d une autre organisation', function () {
    // La liaison de route passe par la portee globale : l'evenement de B est
    // introuvable depuis le contexte de A, meme avec son identifiant exact.
    $this->actingAs($this->userA)
        ->get(route('events.edit', $this->eventB))
        ->assertNotFound();
});

it('compte separement les evenements de chaque organisation', function () {
    forOrganization($this->orgA, fn () => Event::factory()->count(2)->create());

    $this->actingAs($this->userA);
    actAsOrganization($this->orgA);
    expect(Event::count())->toBe(3);

    $this->actingAs($this->userB);
    actAsOrganization($this->orgB);
    expect(Event::count())->toBe(1);
});

it('rattache un evenement cree a l organisation courante, sans la demander', function () {
    $this->actingAs($this->userA);
    actAsOrganization($this->orgA);

    Livewire::test(Form::class)
        ->set('name', 'Nouveau gala')
        ->set('description', 'Une description suffisante.')
        ->set('startsAt', now()->addMonth()->format('Y-m-d\TH:i'))
        ->set('locationName', 'Salle des fetes')
        ->call('save')
        ->assertHasNoErrors();

    $event = Event::where('name', 'Nouveau gala')->firstOrFail();

    expect($event->organization_id)->toBe($this->orgA->id);
});

it('refuse la creation d un evenement a un role sans le droit de gerer', function () {
    $agent = addMemberTo($this->orgA, RoleName::AccessAgent);

    $this->actingAs($agent)
        ->get(route('events.create'))
        ->assertForbidden();
});

it('laisse un agent hors de la liste des evenements', function () {
    $agent = addMemberTo($this->orgA, RoleName::AccessAgent);

    // Section 10 : un agent de controle ne consulte pas les evenements depuis
    // le dashboard, son perimetre est l'application mobile.
    $this->actingAs($agent)
        ->get(route('events.index'))
        ->assertForbidden();
});

it('autorise un co-organisateur a gerer les evenements', function () {
    $delegate = addMemberTo($this->orgA, RoleName::CoOrganizer);

    $this->actingAs($delegate)
        ->get(route('events.index'))
        ->assertOk()
        ->assertSee('Gala de A', escape: false)
        ->assertDontSee('Gala de B', escape: false);
});
