<?php

use App\Enums\AuditAction;
use App\Enums\KycStatus;
use App\Enums\RoleName;
use App\Livewire\Auth\Register;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

it('affiche le formulaire d inscription', function () {
    $this->get('/inscription')->assertOk()->assertSeeLivewire(Register::class);
});

it('cree le compte, l organisation, l appartenance et le role en une fois', function () {
    Livewire::test(Register::class)
        ->set('name', 'Awa Ndong')
        ->set('email', 'awa@example.test')
        ->set('phone', '074000000')
        ->set('organizationName', 'Association des jeunes')
        ->set('organizationType', 'ASSOCIATION')
        ->set('password', 'motdepasse123')
        ->set('passwordConfirmation', 'motdepasse123')
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'awa@example.test')->firstOrFail();
    $organization = Organization::where('name', 'Association des jeunes')->firstOrFail();

    expect($user->current_organization_id)->toBe($organization->id)
        ->and($organization->owner_id)->toBe($user->id)
        ->and($user->belongsToOrganization($organization))->toBeTrue()
        ->and($organization->slug)->toBe('association-des-jeunes');

    actAsOrganization($organization);
    expect($user->fresh()->hasRole(RoleName::Organizer->value))->toBeTrue();
});

it('n exige aucune verification d identite pour s inscrire', function () {
    // Section 10.1 : l'inscription est libre. Seul le reversement attendra.
    Livewire::test(Register::class)
        ->set('name', 'Paul Obame')
        ->set('email', 'paul@example.test')
        ->set('organizationName', 'Studio Obame')
        ->set('organizationType', 'COMPANY')
        ->set('password', 'motdepasse123')
        ->set('passwordConfirmation', 'motdepasse123')
        ->call('register')
        ->assertHasNoErrors();

    $organization = Organization::where('name', 'Studio Obame')->firstOrFail();

    expect($organization->kyc_status)->toBe(KycStatus::Pending)
        ->and($organization->canReceivePayouts())->toBeFalse();
});

it('distingue deux organisations portant le meme nom', function () {
    foreach (['a@example.test', 'b@example.test'] as $email) {
        Livewire::test(Register::class)
            ->set('name', 'Organisateur')
            ->set('email', $email)
            ->set('organizationName', 'BDE')
            ->set('organizationType', 'ASSOCIATION')
            ->set('password', 'motdepasse123')
            ->set('passwordConfirmation', 'motdepasse123')
            ->call('register')
            ->assertHasNoErrors();
    }

    expect(Organization::pluck('slug')->all())->toBe(['bde', 'bde-2']);
});

it('refuse un email deja utilise', function () {
    User::factory()->create(['email' => 'deja@example.test']);

    Livewire::test(Register::class)
        ->set('name', 'Doublon')
        ->set('email', 'deja@example.test')
        ->set('organizationName', 'Une organisation')
        ->set('organizationType', 'ASSOCIATION')
        ->set('password', 'motdepasse123')
        ->set('passwordConfirmation', 'motdepasse123')
        ->call('register')
        ->assertHasErrors(['email']);

    expect(Organization::count())->toBe(0);
});

it('refuse un mot de passe non confirme', function () {
    Livewire::test(Register::class)
        ->set('name', 'Test')
        ->set('email', 'test@example.test')
        ->set('organizationName', 'Une organisation')
        ->set('organizationType', 'ASSOCIATION')
        ->set('password', 'motdepasse123')
        ->set('passwordConfirmation', 'autrechose456')
        ->call('register')
        ->assertHasErrors(['password']);

    expect(User::count())->toBe(0);
});

it('refuse un type d organisation invente', function () {
    Livewire::test(Register::class)
        ->set('name', 'Test')
        ->set('email', 'test@example.test')
        ->set('organizationName', 'Une organisation')
        ->set('organizationType', 'PLATFORM_OWNER')
        ->set('password', 'motdepasse123')
        ->set('passwordConfirmation', 'motdepasse123')
        ->call('register')
        ->assertHasErrors(['organizationType']);
});

it('n ecrit rien du tout si une etape echoue', function () {
    // La creation est transactionnelle : un email en doublon ne doit pas
    // laisser derriere lui une organisation orpheline.
    User::factory()->create(['email' => 'occupe@example.test']);

    Livewire::test(Register::class)
        ->set('name', 'Test')
        ->set('email', 'occupe@example.test')
        ->set('organizationName', 'Organisation fantome')
        ->set('organizationType', 'ASSOCIATION')
        ->set('password', 'motdepasse123')
        ->set('passwordConfirmation', 'motdepasse123')
        ->call('register');

    expect(Organization::where('name', 'Organisation fantome')->exists())->toBeFalse();
});

it('journalise la creation de l organisation', function () {
    Livewire::test(Register::class)
        ->set('name', 'Awa Ndong')
        ->set('email', 'awa@example.test')
        ->set('organizationName', 'Association des jeunes')
        ->set('organizationType', 'ASSOCIATION')
        ->set('password', 'motdepasse123')
        ->set('passwordConfirmation', 'motdepasse123')
        ->call('register');

    $log = AuditLog::action(AuditAction::OrganizationCreated)->firstOrFail();
    $organization = Organization::firstOrFail();

    expect($log->organization_id)->toBe($organization->id)
        ->and($log->auditable_id)->toBe($organization->id)
        ->and($log->metadata['type'])->toBe('ASSOCIATION');
});
