<?php

use App\Models\Event;
use App\Support\Tenancy\OrganizationContext;

/*
 * Page publique d'un evenement (sections 6, 7, 14).
 *
 * Cette page est ouverte par des gens qui n'ont aucun compte, depuis un lien
 * recu sur WhatsApp. Elle est donc le seul endroit du produit ou la portee
 * multi-tenant est levee volontairement — et c'est precisement pour cela
 * qu'elle merite d'etre testee de pres.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');
    actAsOrganization($this->organization);

    $this->event = forOrganization($this->organization, fn () => Event::factory()->published()->create([
        'name' => 'Concert Afro 2026',
        'slug' => 'concert-afro-2026',
        'description' => 'Une soiree de musique afro a Libreville.',
    ]));

    // Le visiteur n'est membre d'aucune organisation.
    app(OrganizationContext::class)->forget();
    setPermissionsTeamId(null);
});

it('s ouvre sans compte ni contexte d organisation', function () {
    $this->get('/e/concert-afro-2026')
        ->assertOk()
        ->assertSee('Concert Afro 2026', escape: false)
        ->assertSee('Institut francais du Gabon', escape: false);

    $this->assertGuest();
});

it('affiche le nom de l organisateur', function () {
    $this->get('/e/concert-afro-2026')
        ->assertOk()
        ->assertSee('Association test', escape: false);
});

it('renvoie 404 sur un brouillon', function () {
    // Un brouillon ne doit pas fuiter, meme si quelqu'un devine son lien.
    $draft = forOrganization($this->organization, fn () => Event::factory()->create(['slug' => 'brouillon-secret']));

    $this->get('/e/brouillon-secret')->assertNotFound();

    expect($draft->status->hasPublicPage())->toBeFalse();
});

it('renvoie 404 sur un evenement archive', function () {
    forOrganization($this->organization, fn () => Event::factory()->archived()->create(['slug' => 'vieil-evenement']));

    $this->get('/e/vieil-evenement')->assertNotFound();
});

it('renvoie 404 sur un lien inconnu', function () {
    $this->get('/e/evenement-qui-n-existe-pas')->assertNotFound();
});

it('reste en ligne pour annoncer une annulation', function () {
    // Quelqu'un qui rouvre le lien recu doit lire l'annulation, pas tomber sur
    // une page introuvable.
    forOrganization($this->organization, fn () => Event::factory()->cancelled('Salle indisponible.')->create([
        'name' => 'Gala annule',
        'slug' => 'gala-annule',
    ]));

    $this->get('/e/gala-annule')
        ->assertOk()
        ->assertSee('annule', escape: false)
        ->assertSee('Salle indisponible.', escape: false);
});

it('expose les balises de partage attendues par WhatsApp', function () {
    $response = $this->get('/e/concert-afro-2026');

    $response->assertOk()
        ->assertSee('property="og:title"', escape: false)
        ->assertSee('property="og:description"', escape: false)
        ->assertSee('property="og:image"', escape: false)
        ->assertSee('property="og:url"', escape: false)
        ->assertSee('Concert Afro 2026', escape: false);
});

it('publie des donnees structurees sur un evenement referencable', function () {
    $this->get('/e/concert-afro-2026')
        ->assertOk()
        ->assertSee('application/ld+json', escape: false)
        ->assertSee('https://schema.org', escape: false)
        ->assertDontSee('noindex', escape: false);
});

it('empeche le referencement d un evenement prive', function () {
    forOrganization($this->organization, fn () => Event::factory()->published()->unlisted()->create([
        'name' => 'Anniversaire de Paul',
        'slug' => 'anniversaire-paul',
    ]));

    $response = $this->get('/e/anniversaire-paul');

    // Le lien fonctionne — il circule entre invites — mais la page ne doit pas
    // remonter dans un moteur de recherche, ni exposer de donnees structurees.
    $response->assertOk()
        ->assertSee('Anniversaire de Paul', escape: false)
        ->assertSee('noindex', escape: false)
        ->assertDontSee('application/ld+json', escape: false);
});

it('ouvre sur la banniere, avant la date et le lieu', function () {
    // La banniere occupe le haut de l'ecran et porte le titre : c'est elle que
    // le visiteur reconnait, il l'a deja vue dans l'apercu du lien. Les
    // informations pratiques viennent apres.
    $html = $this->get('/e/concert-afro-2026')->assertOk()->getContent();

    // L'ancre est le texte reel du document : « Date » est mis en majuscules
    // par la feuille de style, pas dans le HTML.
    $hero = strpos($html, 'event-hero-image');
    $date = strpos($html, 'Date</dt>');

    expect($hero)->not->toBeFalse()
        ->and($date)->not->toBeFalse()
        ->and($hero)->toBeLessThan($date);
});

it('declare une adresse canonique', function () {
    $this->get('/e/concert-afro-2026')
        ->assertOk()
        ->assertSee('rel="canonical"', escape: false)
        ->assertSee('/e/concert-afro-2026', escape: false);
});

it('affiche l heure a Libreville et non en UTC', function () {
    forOrganization($this->organization, fn () => Event::factory()->published()->create([
        'slug' => 'soiree-du-soir',
        // 19h00 UTC = 20h00 a Libreville (UTC+1 toute l'annee).
        'starts_at' => Carbon\Carbon::parse('2026-03-14 19:00', 'UTC'),
        'ends_at' => null,
    ]));

    $this->get('/e/soiree-du-soir')
        ->assertOk()
        ->assertSee('20h00', escape: false);
});

it('ne laisse pas voir les evenements d une autre organisation', function () {
    [, $otherOrganization] = organizerWithOrganization('Organisation concurrente');

    forOrganization($otherOrganization, fn () => Event::factory()->published()->create([
        'name' => 'Evenement concurrent',
        'slug' => 'evenement-concurrent',
    ]));

    app(OrganizationContext::class)->forget();

    // Chaque page publique ne montre que son propre evenement : la levee de
    // portee sert a lire un evenement precis par son slug, pas a ouvrir la
    // base entiere (section 9).
    $this->get('/e/concert-afro-2026')
        ->assertOk()
        ->assertDontSee('Evenement concurrent', escape: false);
});

it('interprete le texte de l organisateur comme du texte, jamais comme du HTML', function () {
    forOrganization($this->organization, fn () => Event::factory()->published()->create([
        'slug' => 'evenement-avec-balises',
        'description' => 'Soiree <script>alert(1)</script> dansante',
    ]));

    $this->get('/e/evenement-avec-balises')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertSee('alert(1)', escape: true);
});
