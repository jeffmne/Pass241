<?php

use App\Enums\RoleName;
use App\Livewire\Dashboard\Events\TicketTypes;
use App\Livewire\Dashboard\Polls\Categories;
use App\Models\Event;
use App\Models\Poll;
use App\Models\TicketType;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Les gardes des ecrans tiennent-elles APRES l'ouverture ? (sections 9, 10)
 *
 * CE FICHIER DEFEND UNE SEULE IDEE : une permission retiree doit refermer
 * l'ecran tout de suite, pas au prochain rechargement de la page.
 *
 * Un ecran Livewire ne rejoue pas sa route quand on clique dedans. Une garde
 * posee dans `mount()` ne s'execute donc qu'au premier chargement : Livewire
 * rehydrate ensuite le composant depuis son instantane et appelle directement
 * la methode demandee, sans repasser par `mount`.
 *
 * Tant que l'ecran ne fait que lire, la consequence est mince. Mais ces
 * ecrans-la ECRIVENT — prix des billets, categories d'un concours — et leurs
 * methodes d'ecriture ne portaient aucune garde propre : elles se reposaient
 * entierement sur celle du montage.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->membre = addMemberTo($this->organization, RoleName::Manager);

    actAsOrganization($this->organization);
    $this->actingAs($this->membre);

    [$this->event, $this->poll] = forOrganization($this->organization, fn () => [
        Event::factory()->published()->create(['name' => 'Concert Afro 2026']),
        Poll::factory()->active()->create(['name' => 'Miss Estuaire']),
    ]);

    /*
     * Retire au membre tous ses droits, comme le ferait l'organisateur depuis
     * l'ecran des membres — pendant que l'onglet du membre reste ouvert.
     *
     * Le cache de permissions est vide explicitement : Spatie le tient en
     * memoire pour la duree de la requete, et sans cela l'ancien jeu de droits
     * repondrait encore.
     */
    $this->revoke = function () {
        setPermissionsTeamId($this->organization->getKey());
        $this->membre->removeRole(RoleName::Manager->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->membre->unsetRelation('roles')->unsetRelation('permissions');
    };
});

it('refuse de creer un tarif quand la permission vient d\'etre retiree', function () {
    /*
     * LE TEST QUI JUSTIFIE LA GARDE SUR CHAQUE REQUETE.
     *
     * Un tarif est un montant que le public paiera. Le laisser modifiable par
     * quelqu'un qui n'a plus le droit d'y toucher, au motif que son onglet
     * etait deja ouvert, revient a faire dependre une regle d'autorisation de
     * la duree de vie d'un onglet.
     */
    $screen = Livewire::test(TicketTypes::class, ['event' => $this->event])
        ->call('create')
        ->set('name', 'Tarif pirate')
        ->set('price', 500)
        ->set('quantity', 100);

    ($this->revoke)();

    $screen->call('save')->assertForbidden();

    expect(TicketType::query()->acrossOrganizations()->where('name', 'Tarif pirate')->exists())
        ->toBeFalse();
});

it('refuse de modifier un concours quand la permission vient d\'etre retiree', function () {
    $screen = Livewire::test(Categories::class, ['poll' => $this->poll])
        ->call('newCategory')
        ->set('categoryName', 'Categorie pirate');

    ($this->revoke)();

    $screen->call('saveCategory')->assertForbidden();

    expect($this->poll->categories()->where('name', 'Categorie pirate')->exists())
        ->toBeFalse();
});

it('refuse encore l\'ecran a l\'ouverture, comme avant', function () {
    // Le pendant : la garde du montage ne doit pas avoir ete perdue en cours
    // de route.
    ($this->revoke)();

    Livewire::test(TicketTypes::class, ['event' => $this->event])->assertForbidden();
    Livewire::test(Categories::class, ['poll' => $this->poll])->assertForbidden();
});
