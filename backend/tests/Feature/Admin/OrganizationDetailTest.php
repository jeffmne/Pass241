<?php

use App\Enums\AuditAction;
use App\Enums\CommissionType;
use App\Livewire\Admin\OrganizationDetail;
use App\Livewire\Admin\Settings as AdminSettings;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Fiche d'organisation et reglages de la plateforme (section 58).
 *
 * La console vit HORS du contexte d'organisation : ces tests n'en posent donc
 * aucun, comme une vraie requete de Super Admin.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->event = forOrganization($this->organization, fn () => Event::factory()
        ->published()
        ->create(['name' => 'Concert Afro 2026']));

    $this->admin = User::factory()->create(['name' => 'Super Admin']);
    $this->admin->forceFill(['is_super_admin' => true])->save();

    app(OrganizationContext::class)->forget();
});

/*
 * ---------------------------------------------------------------------------
 * Acces
 * ---------------------------------------------------------------------------
 */

it('ouvre la fiche d une organisation', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.organization', $this->organization))
        ->assertOk()
        ->assertSee('Association test')
        ->assertSee('Concert Afro 2026');
});

it('cache la fiche a qui n est pas super admin', function () {
    // 404 et non 403 : l'existence de la console n'a pas a etre confirmee.
    $this->actingAs($this->owner)
        ->get(route('admin.organization', $this->organization))
        ->assertNotFound();
});

it('envoie le super admin sur sa console apres connexion', function () {
    /*
     * Le defaut d'origine : envoye sur `/dashboard`, le Super Admin etait
     * redirige vers « creez votre organisation » et n'avait aucun moyen de
     * deviner l'adresse de la console.
     */
    $this->actingAs($this->admin)
        ->get('/dashboard')
        ->assertRedirect(route('admin.overview'));
});

/*
 * ---------------------------------------------------------------------------
 * Suspension (section 10)
 * ---------------------------------------------------------------------------
 */

it('suspend une organisation avec son motif', function () {
    Livewire::actingAs($this->admin)
        ->test(OrganizationDetail::class, ['organization' => $this->organization])
        ->call('startDecision')
        ->set('reason', 'Signalements repetes, compte gele.')
        ->call('decide')
        ->assertHasNoErrors();

    $organization = $this->organization->fresh();

    expect($organization->isSuspended())->toBeTrue()
        ->and($organization->suspension_reason)->toBe('Signalements repetes, compte gele.');
});

it('exige un motif pour suspendre', function () {
    Livewire::actingAs($this->admin)
        ->test(OrganizationDetail::class, ['organization' => $this->organization])
        ->call('startDecision')
        ->call('decide')
        ->assertHasErrors('reason');

    expect($this->organization->fresh()->isSuspended())->toBeFalse();
});

it('reactive une organisation suspendue', function () {
    $this->organization->forceFill([
        'suspended_at' => now(),
        'suspension_reason' => 'Verification en cours.',
    ])->save();

    Livewire::actingAs($this->admin)
        ->test(OrganizationDetail::class, ['organization' => $this->organization->fresh()])
        ->call('startDecision')
        ->set('reason', 'Verification terminee, compte conforme.')
        ->call('decide')
        ->assertHasNoErrors();

    $organization = $this->organization->fresh();

    // Le motif d'origine survit a la reactivation : trois suspensions
    // successives ne doivent pas se lire comme trois incidents isoles.
    expect($organization->isSuspended())->toBeFalse()
        ->and($organization->suspension_reason)->toBe('Verification en cours.');
});

it('refuse en base une suspension sans motif', function () {
    // La contrainte tient meme pour une commande artisan ou une correction
    // manuelle : `suspended_at` sans explication ne doit pas exister.
    expect(fn () => DB::transaction(
        fn () => $this->organization->forceFill(['suspended_at' => now()])->save()
    ))->toThrow(QueryException::class);
});

it('journalise la suspension avec son auteur', function () {
    Livewire::actingAs($this->admin)
        ->test(OrganizationDetail::class, ['organization' => $this->organization])
        ->call('startDecision')
        ->set('reason', 'Signalements repetes.')
        ->call('decide');

    $log = AuditLog::action(AuditAction::OrganizationSuspended)->latest('id')->firstOrFail();

    expect($log->user_id)->toBe($this->admin->id)
        ->and($log->metadata['suspended'])->toBeTrue()
        ->and($log->metadata['reason'])->toBe('Signalements repetes.')
        ->and($log->ip_address)->not->toBeNull();
});

it('ne touche a aucune donnee financiere en suspendant', function () {
    $before = DB::table('events')->where('id', $this->event->id)->first();

    Livewire::actingAs($this->admin)
        ->test(OrganizationDetail::class, ['organization' => $this->organization])
        ->call('startDecision')
        ->set('reason', 'Signalements repetes.')
        ->call('decide');

    /*
     * Suspendre ferme une porte, ca ne reecrit pas l'histoire : les evenements,
     * les billets vendus et les commissions restent intacts (regle 80.1).
     */
    expect(DB::table('events')->where('id', $this->event->id)->first())->toEqual($before);
});

/*
 * ---------------------------------------------------------------------------
 * Reglages de la plateforme
 * ---------------------------------------------------------------------------
 */

it('lit le taux de commission depuis la configuration par defaut', function () {
    // La table peut etre vide : la plateforme retombe sur `config/commission`.
    DB::table('platform_settings')->delete();
    app(PlatformSettings::class)->forget();

    expect(CommissionType::Ticketing->basisPoints())->toBe(500)
        ->and(CommissionType::Donation->basisPoints())->toBe(250);
});

it('change le taux de commission depuis l ecran', function () {
    Livewire::actingAs($this->admin)
        ->test(AdminSettings::class)
        ->set('ticketingRate', '7,5')
        ->call('save')
        ->assertHasNoErrors();

    app(PlatformSettings::class)->forget();

    expect(CommissionType::Ticketing->basisPoints())->toBe(750);
});

it('refuse un taux aberrant', function () {
    Livewire::actingAs($this->admin)
        ->test(AdminSettings::class)
        ->set('ticketingRate', '80')
        ->call('save')
        ->assertHasErrors('ticketingRate');

    app(PlatformSettings::class)->forget();

    expect(CommissionType::Ticketing->basisPoints())->toBe(500);
});

it('journalise le changement avec l ancienne et la nouvelle valeur', function () {
    Livewire::actingAs($this->admin)
        ->test(AdminSettings::class)
        ->set('donationRate', '3')
        ->call('save');

    $log = AuditLog::action(AuditAction::PlatformSettingChanged)->latest('id')->firstOrFail();

    /*
     * Un taux qui bouge sans trace serait indefendable le jour ou un
     * organisateur conteste son reversement.
     */
    expect($log->metadata['from'])->toBe(250)
        ->and($log->metadata['to'])->toBe(300)
        ->and($log->user_id)->toBe($this->admin->id);
});

it('n affiche aucun secret sur l ecran des parametres', function () {
    config(['payments.default' => 'fake']);

    $html = $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk()->getContent();

    // Regle 16 : les cles vivent en variables d'environnement, et rien ne les
    // fait sortir a l'ecran.
    expect($html)->not->toContain((string) config('app.key'))
        ->and($html)->toContain('Configuration technique')
        ->and($html)->toContain('Commission Pass241');
});

it('ferme les parametres a qui n est pas super admin', function () {
    $this->actingAs($this->owner)->get(route('admin.settings'))->assertNotFound();
});
