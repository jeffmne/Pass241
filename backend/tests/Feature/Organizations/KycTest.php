<?php

use App\Actions\Organizations\ReviewKyc;
use App\Actions\Organizations\SubmitKyc;
use App\Enums\AuditAction;
use App\Enums\KycStatus;
use App\Enums\OrganizationType;
use App\Livewire\Organizations\Settings;
use App\Models\AuditLog;
use App\Models\OrganizationDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Verification d'identite (section 10.1).
 *
 * Regle centrale a proteger : le KYC ne bloque jamais la vente, il bloque
 * uniquement le reversement. Un test qui verifierait l'inverse signalerait une
 * derive du produit.
 */

beforeEach(function () {
    Storage::fake('local');
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');
    actAsOrganization($this->organization);
    $this->actingAs($this->owner);
});

it('demarre en attente et sans droit au reversement', function () {
    expect($this->organization->kyc_status)->toBe(KycStatus::Pending)
        ->and($this->organization->canReceivePayouts())->toBeFalse();
});

it('n empeche jamais la vente, seulement le reversement', function () {
    // Section 10.1 : les fonds collectes avant le KYC restent comptabilises
    // normalement. Rien dans le modele ne doit suspendre l'activite.
    expect($this->organization->isSuspended())->toBeFalse()
        ->and($this->organization->kyc_status->allowsPayout())->toBeFalse();
});

it('passe en verification une fois toutes les pieces fournies', function () {
    app(SubmitKyc::class)->handle($this->organization, $this->owner, [
        'IDENTITY' => UploadedFile::fake()->image('cni.jpg'),
        'RCCM' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf'),
    ]);

    $organization = $this->organization->fresh();

    expect($organization->kyc_status)->toBe(KycStatus::Submitted)
        ->and($organization->kyc_submitted_at)->not->toBeNull()
        ->and($organization->canReceivePayouts())->toBeFalse()
        ->and(OrganizationDocument::count())->toBe(2);
});

it('refuse une soumission incomplete', function () {
    // Une association doit fournir piece d'identite ET RCCM : se declarer
    // « en cours de verification » sans piece ferait attendre un reversement
    // qui ne viendrait jamais.
    expect(fn () => app(SubmitKyc::class)->handle($this->organization, $this->owner, [
        'IDENTITY' => UploadedFile::fake()->image('cni.jpg'),
    ]))->toThrow(RuntimeException::class);

    expect($this->organization->fresh()->kyc_status)->toBe(KycStatus::Pending);
});

it('n exige qu une piece d identite pour un particulier', function () {
    [$owner, $organization] = organizerWithOrganization('Jean Nguema', OrganizationType::Individual);
    actAsOrganization($organization);

    app(SubmitKyc::class)->handle($organization, $owner, [
        'IDENTITY' => UploadedFile::fake()->image('cni.jpg'),
    ]);

    expect($organization->fresh()->kyc_status)->toBe(KycStatus::Submitted);
});

it('refuse une piece de plus de 2 Mo des le televersement', function () {
    // La limite est appliquee par la regle de televersement temporaire de
    // Livewire, donc au moment du `set` : le fichier est refuse avant meme
    // d'avoir ete transfere en entier, ce qui compte sur une connexion mobile.
    $component = Livewire::test(Settings::class)
        ->set('documents.IDENTITY', UploadedFile::fake()->create('cni.pdf', 2049, 'application/pdf'));

    $component->assertHasErrors(['documents.IDENTITY']);

    expect($component->errors()->first('documents.IDENTITY'))
        // Le message doit etre lisible en francais, pas la cle de traduction.
        ->not->toContain('validation.')
        ->toContain('2048');

    $component->call('submitKyc');

    expect($this->organization->fresh()->kyc_status)->toBe(KycStatus::Pending)
        ->and(OrganizationDocument::count())->toBe(0);
});

it('accepte une piece a la limite exacte de 2 Mo', function () {
    // La limite PHP (upload_max_filesize) est volontairement plus haute que la
    // limite metier : un fichier de taille limite doit passer la validation, et
    // non etre rejete en amont par PHP sans message lisible.
    Livewire::test(Settings::class)
        ->set('documents.IDENTITY', UploadedFile::fake()->create('cni.pdf', 2048, 'application/pdf'))
        ->set('documents.RCCM', UploadedFile::fake()->create('rccm.pdf', 2048, 'application/pdf'))
        ->call('submitKyc')
        ->assertHasNoErrors();

    expect($this->organization->fresh()->kyc_status)->toBe(KycStatus::Submitted);
});

it('refuse un format de fichier non prevu', function () {
    Livewire::test(Settings::class)
        ->set('documents.IDENTITY', UploadedFile::fake()->create('piece.exe', 10, 'application/octet-stream'))
        ->call('submitKyc')
        ->assertHasErrors(['documents.IDENTITY']);
});

it('stocke les pieces sur un disque prive', function () {
    app(SubmitKyc::class)->handle($this->organization, $this->owner, [
        'IDENTITY' => UploadedFile::fake()->image('cni.jpg'),
        'RCCM' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf'),
    ]);

    $document = OrganizationDocument::firstOrFail();

    // Section 66 : une piece d'identite ne doit jamais etre servie depuis une
    // URL publique devinable.
    expect($document->path)->toStartWith("kyc/{$this->organization->id}/")
        ->and($document->path)->not->toStartWith('public/');

    Storage::disk('local')->assertExists($document->path);
});

it('debloque le reversement apres verification par un administrateur', function () {
    app(SubmitKyc::class)->handle($this->organization, $this->owner, [
        'IDENTITY' => UploadedFile::fake()->image('cni.jpg'),
        'RCCM' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf'),
    ]);

    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    app(ReviewKyc::class)->verify($this->organization->fresh(), $admin);

    $organization = $this->organization->fresh();

    expect($organization->kyc_status)->toBe(KycStatus::Verified)
        ->and($organization->kyc_verified_at)->not->toBeNull()
        // Depuis la decision D-025, la verification ne suffit plus a elle
        // seule : sans numero de destination, il n'y a rien vers quoi virer.
        ->and($organization->canReceivePayouts())->toBeFalse()
        ->and($organization->payoutBlocker())->toContain('numero mobile money');
});

it('interdit a un organisateur de verifier sa propre organisation', function () {
    app(SubmitKyc::class)->handle($this->organization, $this->owner, [
        'IDENTITY' => UploadedFile::fake()->image('cni.jpg'),
        'RCCM' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf'),
    ]);

    expect(fn () => app(ReviewKyc::class)->verify($this->organization->fresh(), $this->owner))
        ->toThrow(RuntimeException::class);

    expect($this->organization->fresh()->kyc_status)->toBe(KycStatus::Submitted);
});

it('interdit de verifier une organisation qui n a rien soumis', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    expect(fn () => app(ReviewKyc::class)->verify($this->organization, $admin))
        ->toThrow(RuntimeException::class);
});

it('exige un motif pour refuser', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    expect(fn () => app(ReviewKyc::class)->reject($this->organization, $admin, '   '))
        ->toThrow(RuntimeException::class);
});

it('conserve le motif du refus et permet une nouvelle soumission', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    app(ReviewKyc::class)->reject($this->organization, $admin, 'Document illisible.');

    $organization = $this->organization->fresh();
    expect($organization->kyc_status)->toBe(KycStatus::Rejected)
        ->and($organization->kyc_rejection_reason)->toBe('Document illisible.')
        ->and($organization->needsKycAttention())->toBeTrue();

    app(SubmitKyc::class)->handle($organization, $this->owner, [
        'IDENTITY' => UploadedFile::fake()->image('cni.jpg'),
        'RCCM' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf'),
    ]);

    $organization = $organization->fresh();
    expect($organization->kyc_status)->toBe(KycStatus::Submitted)
        ->and($organization->kyc_rejection_reason)->toBeNull();
});

it('journalise chaque etape de la verification', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    app(SubmitKyc::class)->handle($this->organization, $this->owner, [
        'IDENTITY' => UploadedFile::fake()->image('cni.jpg'),
        'RCCM' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf'),
    ]);
    app(ReviewKyc::class)->verify($this->organization->fresh(), $admin);

    expect(AuditLog::action(AuditAction::KycSubmitted)->exists())->toBeTrue()
        ->and(AuditLog::action(AuditAction::KycVerified)->where('user_id', $admin->id)->exists())->toBeTrue();
});
