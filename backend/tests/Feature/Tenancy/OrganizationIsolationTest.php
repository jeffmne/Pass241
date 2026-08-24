<?php

use App\Models\OrganizationDocument;
use App\Support\Tenancy\OrganizationContext;

/*
 * Isolation multi-tenant (sections 9, 74, 80.4).
 *
 * C'est la suite la plus importante du projet : une regression ici expose les
 * donnees d'un organisateur a un autre. Les tests portent sur
 * OrganizationDocument, qui est deliberement le modele le plus sensible du
 * jalon — ce sont des pieces d'identite.
 */

beforeEach(function () {
    [$this->userA, $this->orgA] = organizerWithOrganization('Organisation A');
    [$this->userB, $this->orgB] = organizerWithOrganization('Organisation B');

    foreach ([[$this->orgA, 'piece-a.pdf'], [$this->orgB, 'piece-b.pdf']] as [$org, $file]) {
        actAsOrganization($org);

        OrganizationDocument::create([
            'type' => 'IDENTITY',
            'path' => "kyc/{$org->id}/{$file}",
            'original_name' => $file,
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);
    }

    app(OrganizationContext::class)->forget();
});

it('ne montre que les documents de l organisation courante', function () {
    actAsOrganization($this->orgA);

    $documents = OrganizationDocument::all();

    expect($documents)->toHaveCount(1)
        ->and($documents->first()->original_name)->toBe('piece-a.pdf')
        ->and($documents->first()->organization_id)->toBe($this->orgA->id);
});

it('cache les documents de l autre organisation apres bascule de contexte', function () {
    actAsOrganization($this->orgB);

    expect(OrganizationDocument::pluck('original_name')->all())->toBe(['piece-b.pdf']);
});

it('rend introuvable un document d une autre organisation par son identifiant', function () {
    actAsOrganization($this->orgA);
    $documentA = OrganizationDocument::first();

    actAsOrganization($this->orgB);

    // Protection contre l'IDOR (section 66) : connaitre l'identifiant ne suffit
    // pas, la portee s'applique aussi a une recherche directe par cle.
    expect(OrganizationDocument::find($documentA->id))->toBeNull();
});

it('ne renvoie aucune ligne en l absence de contexte', function () {
    // Comportement ferme volontaire : un oubli du middleware doit produire une
    // liste vide, jamais les donnees de toutes les organisations.
    expect(OrganizationDocument::count())->toBe(0);
});

it('refuse de creer une ressource pour une autre organisation', function () {
    actAsOrganization($this->orgA);

    // Simule un `organization_id` injecte depuis le frontend (section 80.4).
    expect(fn () => OrganizationDocument::create([
        'organization_id' => $this->orgB->id,
        'type' => 'IDENTITY',
        'path' => 'kyc/intrus.pdf',
        'original_name' => 'intrus.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
    ]))->toThrow(RuntimeException::class);

    actAsOrganization($this->orgB);
    expect(OrganizationDocument::count())->toBe(1);
});

it('renseigne automatiquement l organisation courante a la creation', function () {
    actAsOrganization($this->orgA);

    $document = OrganizationDocument::create([
        'type' => 'RCCM',
        'path' => 'kyc/rccm.pdf',
        'original_name' => 'rccm.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 20,
    ]);

    expect($document->organization_id)->toBe($this->orgA->id);
});

it('refuse de deplacer une ressource vers une autre organisation', function () {
    actAsOrganization($this->orgA);
    $document = OrganizationDocument::first();

    $document->organization_id = $this->orgB->id;

    expect(fn () => $document->save())->toThrow(RuntimeException::class);
});

it('n autorise la lecture inter-organisations que par une levee explicite', function () {
    actAsOrganization($this->orgA);

    $all = app(OrganizationContext::class)->withoutScope(
        fn () => OrganizationDocument::count()
    );

    expect($all)->toBe(2)
        // La levee ne dure que le temps du bloc : la portee revient ensuite.
        ->and(OrganizationDocument::count())->toBe(1);
});

it('restaure le contexte precedent apres un traitement pour une autre organisation', function () {
    $context = app(OrganizationContext::class);
    actAsOrganization($this->orgA);

    $inner = $context->runFor($this->orgB, fn () => OrganizationDocument::pluck('original_name')->all());

    expect($inner)->toBe(['piece-b.pdf'])
        ->and($context->currentId())->toBe($this->orgA->id);
});
