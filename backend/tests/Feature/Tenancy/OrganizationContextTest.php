<?php

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;

/*
 * Middleware EnsureOrganizationContext (section 9).
 *
 * Verifie que l'acces au dashboard depend de l'appartenance reelle et d'elle
 * seule, et que les cas de sortie (appartenance revoquee, organisation
 * suspendue, aucune organisation) ne laissent jamais l'utilisateur avec un
 * acces qu'il ne devrait plus avoir.
 */

it('redirige un visiteur non authentifie vers la connexion', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});

it('laisse entrer un organisateur membre', function () {
    [$user] = organizerWithOrganization();

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

it('coupe l acces des que l appartenance est revoquee', function () {
    [$user, $organization] = organizerWithOrganization();

    OrganizationMember::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $user->id)
        ->update(['revoked_at' => now()]);

    $this->actingAs($user)->get('/dashboard')->assertForbidden();

    // L'organisation active est effacee : la session ne doit pas conserver un
    // droit qui n'existe plus.
    expect($user->fresh()->current_organization_id)->toBeNull();
});

it('renvoie vers la creation d organisation un utilisateur qui n en a aucune', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('organizations.create'));
});

it('bloque l acces a une organisation suspendue', function () {
    [$user, $organization] = organizerWithOrganization();
    $organization->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($user)->get('/dashboard')->assertForbidden();
});

it('reprend une appartenance existante quand aucune organisation n est selectionnee', function () {
    [$user, $organization] = organizerWithOrganization();

    $user->forceFill(['current_organization_id' => null])->save();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    expect($user->fresh()->current_organization_id)->toBe($organization->id);
});

it('refuse le contexte d une organisation existante dont l utilisateur n est pas membre', function () {
    [$user] = organizerWithOrganization('Organisation A');
    [, $orgB] = organizerWithOrganization('Organisation B');

    // La cle etrangere empeche une valeur inexistante ; le risque reel est donc
    // une organisation qui existe mais dont l'appartenance n'a jamais ete
    // accordee — ou a ete revoquee.
    $user->forceFill(['current_organization_id' => $orgB->id])->save();

    $this->actingAs($user)->get('/dashboard')->assertForbidden();

    expect($user->fresh()->current_organization_id)->toBeNull();
});

it('refuse de basculer vers une organisation dont l utilisateur n est pas membre', function () {
    [$userA] = organizerWithOrganization('Organisation A');
    [, $orgB] = organizerWithOrganization('Organisation B');

    expect($userA->switchOrganization($orgB))->toBeFalse()
        ->and($userA->fresh()->current_organization_id)->not->toBe($orgB->id);
});

it('accepte de basculer vers une organisation dont l utilisateur est membre', function () {
    [$user] = organizerWithOrganization('Organisation A');
    [, $orgB] = organizerWithOrganization('Organisation B');

    OrganizationMember::create([
        'organization_id' => $orgB->id,
        'user_id' => $user->id,
        'joined_at' => now(),
    ]);

    expect($user->switchOrganization($orgB))->toBeTrue()
        ->and($user->fresh()->current_organization_id)->toBe($orgB->id);
});

it('repond en JSON sans contexte pour une requete d API', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/dashboard')
        ->assertForbidden()
        ->assertJson(['message' => 'Aucune organisation active.']);
});

it('ne fait jamais confiance a un identifiant d organisation envoye dans la requete', function () {
    [$userA, $orgA] = organizerWithOrganization('Organisation A');
    [, $orgB] = organizerWithOrganization('Organisation B');

    // Section 80.4 : l'organisation vient de l'utilisateur authentifie, jamais
    // de la requete. Ces parametres doivent rester sans aucun effet.
    $this->actingAs($userA)
        ->get('/dashboard?organizationId='.$orgB->id.'&organization_id='.$orgB->id)
        ->assertOk()
        ->assertSee($orgA->name, escape: false)
        ->assertDontSee($orgB->name, escape: false);
});

it('applique la portee des roles a l organisation courante', function () {
    [$user, $orgA] = organizerWithOrganization('Organisation A');
    [, $orgB] = organizerWithOrganization('Organisation B');

    // Meme utilisateur, membre de B mais sans role : les droits obtenus dans A
    // ne doivent rien autoriser dans B (section 9).
    OrganizationMember::create([
        'organization_id' => $orgB->id,
        'user_id' => $user->id,
        'joined_at' => now(),
    ]);

    actAsOrganization($orgA);
    expect($user->fresh()->can('finances.view'))->toBeTrue();

    actAsOrganization($orgB);
    expect($user->fresh()->can('finances.view'))->toBeFalse();
});

it('n autorise pas un membre d une autre organisation a lire ses parametres', function () {
    [$userA] = organizerWithOrganization('Organisation A');
    [, $orgB] = organizerWithOrganization('Organisation B');

    $this->actingAs($userA)
        ->get('/parametres')
        ->assertOk()
        ->assertDontSee($orgB->name, escape: false);

    expect(Organization::count())->toBe(2);
});
