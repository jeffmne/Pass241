<?php

use App\Models\Event;
use App\Support\Tenancy\OrganizationContext;

/*
 * Liaison de route sur un modele porte par une organisation (section 9).
 *
 * CES TESTS N'APPELLENT PAS `actAsOrganization()`, ET C'EST LE POINT. Une vraie
 * requete HTTP arrive sans contexte : c'est le middleware qui l'etablit. Les
 * autres tests, eux, le posent a la main avant d'appeler — ce qui masque
 * exactement ce que ce fichier verifie.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->event = forOrganization($this->organization, fn () => Event::factory()
        ->published()
        ->create(['name' => 'Gala des entrepreneurs']));

    // Etat d'une requete entrante : aucun contexte, rien en memoire.
    app(OrganizationContext::class)->forget();
});

it('ouvre la modification d un evenement de son organisation', function () {
    $this->actingAs($this->owner)
        ->get(route('events.edit', $this->event))
        ->assertOk();
});

it('ouvre les autres ecrans rattaches a un evenement', function () {
    foreach (['events.ticket-types', 'events.invitations', 'events.entries', 'events.access-control'] as $route) {
        $this->actingAs($this->owner)
            ->get(route($route, $this->event))
            ->assertOk();
    }
});

it('ouvre les ecrans rattaches a une collecte', function () {
    $campaign = forOrganization($this->organization, fn () => App\Models\Campaign::factory()
        ->published()
        ->create());

    app(OrganizationContext::class)->forget();

    // Meme liaison, meme piege : les collectes etaient tout aussi inaccessibles.
    foreach (['campaigns.edit', 'campaigns.donations'] as $route) {
        $this->actingAs($this->owner)
            ->get(route($route, $campaign))
            ->assertOk();
    }
});

it('renvoie 404 sur l evenement d une autre organisation', function () {
    [$intruder] = organizerWithOrganization('Autre association');

    app(OrganizationContext::class)->forget();

    // Regle 80.4 : l'isolation ne depend pas de l'ecran, elle tient des la
    // liaison de route.
    $this->actingAs($intruder)
        ->get(route('events.edit', $this->event))
        ->assertNotFound();
});

/*
 * ---------------------------------------------------------------------------
 * Menu du tableau de bord
 * ---------------------------------------------------------------------------
 */

it('n affiche aucune entree de menu qui ne mene nulle part', function () {
    /*
     * Le menu annoncait « Billetterie » et « Controle d'acces » en gris, en
     * attendant leur module. Les modules existent, mais ne sont pas des
     * destinations : ils vivent dans un evenement. Une entree grise permanente
     * se lit comme une panne, pas comme une promesse.
     */
    $html = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain(route('campaigns.index'))
        ->and($html)->toContain(route('refunds.index'))
        // Les deux entrees inertes ont disparu du menu.
        ->and(substr_count($html, 'aria-disabled="true"'))->toBe(0);
});

it('mene a un ecran ouvert depuis chaque entree du menu', function () {
    foreach (['dashboard', 'events.index', 'campaigns.index', 'refunds.index', 'organization.settings'] as $route) {
        $this->actingAs($this->owner)->get(route($route))->assertOk();
    }
});

/*
 * ---------------------------------------------------------------------------
 * Actions Livewire, par le vrai chemin HTTP
 * ---------------------------------------------------------------------------
 */

it('accepte une action Livewire sur un ecran rattache a un evenement', function () {
    /*
     * `Livewire::test()` n'exerce AUCUN middleware : il appelle le composant
     * directement, avec le contexte que le test a bien voulu poser. Le
     * navigateur, lui, passe par `POST /livewire/update`, ou Livewire rejoue
     * les middlewares de la route d'origine — donc la liaison de route, donc la
     * portee par organisation.
     *
     * C'est ce chemin-la qui repondait 404 sur « Ajouter une porte » et
     * « Ajouter un agent », et aucun test ne le parcourait.
     */
    $page = $this->actingAs($this->owner)
        ->get(route('events.access-control', $this->event))
        ->assertOk();

    $html = $page->getContent();

    expect($html)->toMatch('/wire:snapshot="/');

    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);

    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);

    app(OrganizationContext::class)->forget();

    /*
     * L'adresse de mise a jour est OBFUSQUEE dans cette version de Livewire
     * (`/livewire-967f37ec/update`) : elle se lit par son nom de route, jamais
     * en dur.
     */
    $this->actingAs($this->owner)
        ->postJson(route('default-livewire.update'), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [[
                    'path' => '',
                    'method' => '$set',
                    'params' => ['showEntryForm', true],
                ]],
            ]],
        ], [
            // Livewire refuse net toute requete sans cet en-tete : sans lui, le
            // test mesurerait cette protection et non le contexte.
            'X-Livewire' => '1',
        ])
        ->assertOk();
});
