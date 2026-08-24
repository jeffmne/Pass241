<?php

use App\Actions\Organizations\CreateOrganization;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Les roles et permissions sont une donnee de reference, pas un jeu
        // d'essai : sans eux, toute verification d'autorisation serait fausse.
        $this->seed(RolesAndPermissionsSeeder::class);

        // Chaque test demarre hors contexte d'organisation, comme une requete
        // qui n'a pas encore traverse EnsureOrganizationContext.
        app(OrganizationContext::class)->forget();
        setPermissionsTeamId(null);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| Section 80.5 : les montants sont stockes en entiers XAF, jamais en flottant.
|
*/

expect()->extend('toBeXaf', function () {
    expect($this->value)->toBeInt();

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Cree un organisateur avec son organisation, son appartenance et son role,
 * par le meme chemin que l'application (section 9). Passer par l'action plutot
 * que par des factories brutes garantit que les tests d'isolation portent sur
 * la configuration reelle, et non sur un montage qui lui ressemble.
 *
 * @return array{0: User, 1: Organization}
 */
function organizerWithOrganization(
    string $organizationName = 'Organisation de test',
    OrganizationType $type = OrganizationType::Association,
): array {
    $user = User::factory()->create();

    $organization = app(CreateOrganization::class)->handle(
        owner: $user,
        name: $organizationName,
        type: $type,
    );

    app(OrganizationContext::class)->forget();
    setPermissionsTeamId(null);

    return [$user->refresh(), $organization];
}

/**
 * Place le contexte comme le ferait EnsureOrganizationContext, pour les tests
 * unitaires qui n'effectuent pas de requete HTTP.
 */
function actAsOrganization(Organization $organization): void
{
    app(OrganizationContext::class)->set($organization);
    setPermissionsTeamId($organization->getKey());
}

/**
 * Cree des donnees dans le contexte d'une organisation precise, puis restaure
 * le contexte precedent.
 *
 * Indispensable des qu'un test manipule plusieurs organisations : le trait
 * BelongsToOrganization refuse toute creation hors contexte, ou visant une
 * autre organisation que celle en cours. Passer par ici plutot que de forcer
 * `organization_id` garantit que les tests empruntent le meme chemin que
 * l'application (section 9).
 *
 * @template TReturn
 *
 * @param  callable(): TReturn  $callback
 * @return TReturn
 */
function forOrganization(Organization $organization, callable $callback): mixed
{
    return app(OrganizationContext::class)->runFor($organization, $callback);
}

/**
 * Ajoute un membre a une organisation avec le role demande, par le meme chemin
 * que l'application : appartenance en base, puis role attribue dans le contexte
 * de cette organisation.
 */
function addMemberTo(Organization $organization, RoleName $role): User
{
    $user = User::factory()->create();

    OrganizationMember::create([
        'organization_id' => $organization->getKey(),
        'user_id' => $user->getKey(),
        'joined_at' => now(),
    ]);

    $user->forceFill(['current_organization_id' => $organization->getKey()])->save();

    setPermissionsTeamId($organization->getKey());
    $user->assignRole($role->value);

    return $user->fresh();
}
