<?php

use App\Enums\AuditAction;
use App\Livewire\Auth\Login;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    RateLimiter::clear(strtolower('awa@example.test').'|127.0.0.1');
});

it('affiche le formulaire de connexion', function () {
    $this->get('/connexion')->assertOk()->assertSeeLivewire(Login::class);
});

it('connecte un organisateur avec les bons identifiants', function () {
    [$user] = organizerWithOrganization();
    $user->forceFill(['email' => 'awa@example.test', 'password' => Hash::make('motdepasse123')])->save();

    Livewire::test(Login::class)
        ->set('email', 'awa@example.test')
        ->set('password', 'motdepasse123')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('refuse un mot de passe errone', function () {
    User::factory()->create(['email' => 'awa@example.test', 'password' => Hash::make('motdepasse123')]);

    Livewire::test(Login::class)
        ->set('email', 'awa@example.test')
        ->set('password', 'mauvais')
        ->call('login')
        ->assertHasErrors(['email']);

    $this->assertGuest();
});

it('ne revele pas si une adresse email existe', function () {
    User::factory()->create(['email' => 'connu@example.test', 'password' => Hash::make('motdepasse123')]);

    $connu = Livewire::test(Login::class)
        ->set('email', 'connu@example.test')->set('password', 'mauvais')->call('login')
        ->errors()->get('email');

    RateLimiter::clear(strtolower('inconnu@example.test').'|127.0.0.1');

    $inconnu = Livewire::test(Login::class)
        ->set('email', 'inconnu@example.test')->set('password', 'mauvais')->call('login')
        ->errors()->get('email');

    // Message strictement identique : sinon le formulaire devient un moyen de
    // savoir qui possede un compte sur la plateforme (section 66).
    expect($connu)->toBe($inconnu);
});

it('bloque apres cinq tentatives infructueuses', function () {
    User::factory()->create(['email' => 'awa@example.test', 'password' => Hash::make('motdepasse123')]);

    foreach (range(1, 5) as $ignored) {
        Livewire::test(Login::class)
            ->set('email', 'awa@example.test')->set('password', 'mauvais')->call('login');
    }

    $errors = Livewire::test(Login::class)
        ->set('email', 'awa@example.test')
        ->set('password', 'motdepasse123')
        ->call('login')
        ->errors()->get('email');

    // Meme le bon mot de passe est refuse pendant la periode de blocage.
    expect($errors[0])->toContain('Trop de tentatives');
    $this->assertGuest();
});

it('journalise la connexion', function () {
    [$user] = organizerWithOrganization();
    $user->forceFill(['email' => 'awa@example.test', 'password' => Hash::make('motdepasse123')])->save();

    Livewire::test(Login::class)
        ->set('email', 'awa@example.test')->set('password', 'motdepasse123')->call('login');

    $log = AuditLog::action(AuditAction::Login)->firstOrFail();

    expect($log->user_id)->toBe($user->id)
        ->and($log->organization_id)->toBe($user->current_organization_id);
});

it('journalise une tentative de connexion echouee', function () {
    User::factory()->create(['email' => 'awa@example.test', 'password' => Hash::make('motdepasse123')]);

    Livewire::test(Login::class)
        ->set('email', 'awa@example.test')->set('password', 'mauvais')->call('login');

    $log = AuditLog::action(AuditAction::LoginFailed)->firstOrFail();

    expect($log->metadata['email'])->toBe('awa@example.test')
        ->and($log->user_id)->toBeNull();
});

it('journalise la deconnexion et invalide la session', function () {
    [$user] = organizerWithOrganization();

    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();
    expect(AuditLog::action(AuditAction::Logout)->where('user_id', $user->id)->exists())->toBeTrue();
});
