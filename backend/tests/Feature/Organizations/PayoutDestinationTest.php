<?php

use App\Actions\Organizations\SetPayoutDestination;
use App\Enums\AuditAction;
use App\Enums\KycStatus;
use App\Enums\PaymentProvider;
use App\Livewire\Organizations\Settings;
use App\Models\AuditLog;
use App\Notifications\PayoutDestinationChanged;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Destination de reversement (section 21.2, decision D-025).
 *
 * Le numero sur lequel l'organisateur recoit le net de ses ventes. Aucune
 * verification par code n'est demandee : le compte mobile money est deja
 * verifie par Airtel ou Moov, qui reclament une piece d'identite a
 * l'ouverture.
 *
 * Ce qui est teste ici est surtout ce qui protege le sortant. Rediriger les
 * fonds vers un autre numero est le vecteur de fraude central une fois qu'une
 * plateforme sait payer.
 */

beforeEach(function () {
    Notification::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->organization->update(['contact_email' => 'contact@example.test']);

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);
});

it('enregistre le numero de reversement', function () {
    $organization = app(SetPayoutDestination::class)->handle(
        organization: $this->organization,
        phone: '074 00 00 00',
        operator: PaymentProvider::Airtel,
        actor: $this->owner,
    );

    // Le numero est normalise : la saisie contient des espaces, le virement
    // n'en veut pas.
    expect($organization->payout_phone)->toBe('074000000')
        ->and($organization->payout_operator)->toBe(PaymentProvider::Airtel)
        ->and($organization->payout_updated_at)->not->toBeNull()
        ->and($organization->hasPayoutDestination())->toBeTrue();
});

it('conserve le titulaire renvoye par le prestataire', function () {
    $organization = app(SetPayoutDestination::class)->handle(
        $this->organization, '074123456', PaymentProvider::Airtel, $this->owner,
    );

    // Montre a l'organisateur avant le premier versement : un virement mobile
    // money ne se rappelle pas, une faute de frappe est definitive.
    expect($organization->payout_name)->toContain('3456');
});

it('debloque le reversement une fois le numero renseigne', function () {
    expect($this->organization->canReceivePayouts())->toBeFalse();

    app(SetPayoutDestination::class)->handle(
        $this->organization, '074000000', PaymentProvider::Airtel, $this->owner,
    );

    expect($this->organization->fresh()->canReceivePayouts())->toBeTrue()
        ->and($this->organization->fresh()->payoutBlocker())->toBeNull();
});

it('n exige aucune piece justificative', function () {
    // Decision D-025 : le KYC documentaire n'est plus demande. Exiger
    // VERIFIED bloquerait tout le monde, plus personne ne soumettant de
    // dossier.
    app(SetPayoutDestination::class)->handle(
        $this->organization, '074000000', PaymentProvider::Airtel, $this->owner,
    );

    $organization = $this->organization->fresh();

    expect($organization->kyc_status)->toBe(KycStatus::Pending)
        ->and($organization->canReceivePayouts())->toBeTrue();
});

it('refuse le reversement a une organisation refusee par le super admin', function () {
    app(SetPayoutDestination::class)->handle(
        $this->organization, '074000000', PaymentProvider::Airtel, $this->owner,
    );

    $this->organization->forceFill(['kyc_status' => KycStatus::Rejected])->save();

    // `kyc_status` subsiste comme levier du Super Admin, meme sans dossier a
    // examiner : c'est ce qui permet de couper les fonds d'un compte suspect.
    expect($this->organization->fresh()->canReceivePayouts())->toBeFalse();
});

it('refuse le reversement a une organisation suspendue', function () {
    app(SetPayoutDestination::class)->handle(
        $this->organization, '074000000', PaymentProvider::Airtel, $this->owner,
    );

    $this->organization->forceFill(['suspended_at' => now(), 'suspension_reason' => 'Suspendue pour ce test.'])->save();

    expect($this->organization->fresh()->canReceivePayouts())->toBeFalse();
});

/*
 * ---------------------------------------------------------------------------
 * Tracabilite du changement
 * ---------------------------------------------------------------------------
 */

it('journalise le premier enregistrement et le changement separement', function () {
    app(SetPayoutDestination::class)->handle(
        $this->organization, '074000000', PaymentProvider::Airtel, $this->owner,
    );

    expect(AuditLog::action(AuditAction::PayoutDestinationSet)->count())->toBe(1);

    app(SetPayoutDestination::class)->handle(
        $this->organization->fresh(), '062000000', PaymentProvider::Moov, $this->owner,
    );

    // Un changement n'est pas un enregistrement : le distinguer permet de
    // reperer une redirection de fonds dans le journal sans la confondre avec
    // une premiere saisie.
    expect(AuditLog::action(AuditAction::PayoutDestinationChanged)->count())->toBe(1);
});

it('conserve le numero precedent dans le journal, masque', function () {
    app(SetPayoutDestination::class)->handle(
        $this->organization, '074111111', PaymentProvider::Airtel, $this->owner,
    );

    app(SetPayoutDestination::class)->handle(
        $this->organization->fresh(), '062222222', PaymentProvider::Moov, $this->owner,
    );

    $log = AuditLog::action(AuditAction::PayoutDestinationChanged)->firstOrFail();

    // Sans le numero precedent, une trace ne dit pas vers ou les fonds ont ete
    // detournes. Masque, parce que le journal est consulte et exporte : il
    // doit permettre de reconnaitre un numero, pas de le recopier (section 52).
    expect($log->metadata['previous_phone'])->toEndWith('1111')
        ->and($log->metadata['previous_phone'])->toStartWith('*')
        ->and($log->metadata['previous_phone'])->not->toContain('074')
        ->and($log->metadata['phone'])->toEndWith('2222')
        ->and($log->metadata['phone'])->not->toContain('062');
});

it('signale tout changement par email', function () {
    app(SetPayoutDestination::class)->handle(
        $this->organization, '074000000', PaymentProvider::Airtel, $this->owner,
    );

    // C'est le dispositif de detection d'une prise de controle de compte : la
    // victime recoit un message qu'elle n'attendait pas.
    Notification::assertSentTo($this->organization, PayoutDestinationChanged::class);
});

it('n ecrit ni ne notifie quand la destination est identique', function () {
    app(SetPayoutDestination::class)->handle(
        $this->organization, '074000000', PaymentProvider::Airtel, $this->owner,
    );

    Notification::fake();

    app(SetPayoutDestination::class)->handle(
        $this->organization->fresh(), '074 00 00 00', PaymentProvider::Airtel, $this->owner,
    );

    // Une alerte qui n'apprend rien banalise les vraies.
    Notification::assertNothingSent();

    expect(AuditLog::action(AuditAction::PayoutDestinationChanged)->count())->toBe(0);
});

/*
 * ---------------------------------------------------------------------------
 * Ecran organisateur
 * ---------------------------------------------------------------------------
 */

it('enregistre le numero depuis les reglages', function () {
    Livewire::test(Settings::class)
        ->set('payoutOperator', PaymentProvider::Airtel->value)
        ->set('payoutPhone', '074000000')
        ->call('savePayoutDestination')
        ->assertHasNoErrors();

    expect($this->organization->fresh()->payout_phone)->toBe('074000000');
});

it('refuse un numero mal forme', function () {
    Livewire::test(Settings::class)
        ->set('payoutOperator', PaymentProvider::Airtel->value)
        ->set('payoutPhone', '12')
        ->call('savePayoutDestination')
        ->assertHasErrors(['payoutPhone']);

    expect($this->organization->fresh()->hasPayoutDestination())->toBeFalse();
});

it('refuse d enregistrer sans operateur', function () {
    Livewire::test(Settings::class)
        ->set('payoutPhone', '074000000')
        ->call('savePayoutDestination')
        ->assertHasErrors(['payoutOperator']);
});
