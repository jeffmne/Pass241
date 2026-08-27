<?php

use App\Actions\Donations\CreateDonation;
use App\Actions\Payments\ConfirmPayment;
use App\Enums\AuditAction;
use App\Enums\CommissionType;
use App\Enums\DonationStatus;
use App\Enums\PaymentProvider;
use App\Jobs\ExpireDonationIfUnpaid;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Commission;
use App\Models\Donation;
use App\Models\PaymentWebhook;
use App\Services\Fundraising\CampaignProgress;
use App\Services\Payments\FakeProvider;
use App\Services\Payments\PayableResolver;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Dons (sections 37 a 46, 63, 75).
 *
 * Ce fichier verifie surtout ce que le produit REFUSE. Les sept tests
 * anti-fraude obligatoires de la section 75 y sont couverts : suppression d'un
 * don, modification de son montant, montant collecte envoye par le client,
 * suppression d'un donateur, valeur affichee par le frontend, webhook rejoue,
 * don echoue presente comme confirme.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->campaign = forOrganization($this->organization, fn () => Campaign::factory()
        ->published()
        ->create(['name' => 'Operation cartables 2026', 'goal_amount' => 1_000_000]));

    // Le donateur n'a pas de compte : tout ce qui suit s'execute hors contexte,
    // comme une vraie requete publique.
    app(OrganizationContext::class)->forget();

    $this->give = fn (int $amount = 10_000, array $overrides = []) => app(CreateDonation::class)->handle(
        campaign: $this->campaign,
        amount: $amount,
        provider: $overrides['provider'] ?? PaymentProvider::Airtel,
        payerPhone: $overrides['phone'] ?? '074000001',
        donorName: array_key_exists('name', $overrides) ? $overrides['name'] : 'Marie Nzeng',
        donorEmail: $overrides['email'] ?? null,
        isAnonymous: $overrides['anonymous'] ?? false,
    );

    $this->confirm = fn (Donation $donation) => app(ConfirmPayment::class)
        ->handle($donation, 'FAKE-REF-1');

    $this->progress = fn () => app(CampaignProgress::class)->forCampaign($this->campaign->fresh());
});

/*
 * ---------------------------------------------------------------------------
 * Ce qui compte, et ce qui ne compte pas
 * ---------------------------------------------------------------------------
 */

it('ne compte pas un don tant qu il n est pas confirme', function () {
    ($this->give)(50_000);

    /*
     * Section 39 : un don en attente n'est pas de l'argent recu, c'est une
     * intention. Le compter ferait mentir la page publique a chaque paiement
     * abandonne.
     */
    expect(($this->progress)()['collected'])->toBe(0)
        ->and(($this->progress)()['donations'])->toBe(0);
});

it('compte le don des qu il est confirme', function () {
    ($this->confirm)(($this->give)(50_000));

    $progress = ($this->progress)();

    expect($progress['collected'])->toBe(50_000)
        ->and($progress['donations'])->toBe(1)
        ->and($progress['donors'])->toBe(1)
        ->and($progress['percentage'])->toBe(5.0);
});

it('ne compte jamais les frais du prestataire dans le collecte', function () {
    $donation = ($this->give)(10_000);

    /*
     * Decision D-023 : les frais sont avances par le donateur pour que la cause
     * recoive le montant annonce. Les compter gonflerait la progression d'un
     * argent que la campagne ne recevra jamais.
     */
    expect($donation->amount)->toBeGreaterThan($donation->subtotal);

    ($this->confirm)($donation);

    expect(($this->progress)()['collected'])->toBe(10_000);
});

it('compte un donateur qui donne deux fois comme un seul donateur', function () {
    ($this->confirm)(($this->give)(5_000, ['phone' => '074000009']));
    ($this->confirm)(($this->give)(7_000, ['phone' => '074000009']));

    $progress = ($this->progress)();

    expect($progress['donations'])->toBe(2)
        ->and($progress['donors'])->toBe(1)
        ->and($progress['collected'])->toBe(12_000);
});

it('preleve 2,5 % sur le don, jamais sur le montant debite', function () {
    $donation = ($this->confirm)(($this->give)(100_000));

    $commission = Commission::query()->acrossOrganizations()->firstOrFail();

    expect($commission->type)->toBe(CommissionType::Donation)
        // 2,5 % de 100 000, et non du total debite frais compris.
        ->and($commission->gross_amount)->toBe(100_000)
        ->and($commission->commission_amount)->toBe(2_500)
        ->and($commission->net_amount)->toBe(97_500)
        // Taux fige : changer la configuration demain ne doit rien changer aux
        // transactions d'hier.
        ->and($commission->rate_basis_points)->toBe(250)
        ->and($donation->subtotal)->toBe(100_000);
});

/*
 * ---------------------------------------------------------------------------
 * Anonymat (sections 40, 41)
 * ---------------------------------------------------------------------------
 */

it('masque l identite d un donateur anonyme sans l effacer', function () {
    $donation = ($this->give)(25_000, ['anonymous' => true, 'name' => 'Marie Nzeng']);

    // L'anonymat ne porte QUE sur l'affichage : l'identite reste en base, le
    // paiement et l'audit en dependent.
    expect($donation->publicDonorName())->toBe('Donateur anonyme')
        ->and($donation->donor_name)->toBe('Marie Nzeng')
        ->and($donation->is_anonymous)->toBeTrue();
});

it('traite un don sans nom comme anonyme', function () {
    // Personne n'a a apparaitre sous une chaine vide.
    expect(($this->give)(5_000, ['name' => null])->publicDonorName())->toBe('Donateur anonyme');
});

it('publie le montant meme quand le donateur est anonyme', function () {
    $donation = ($this->confirm)(($this->give)(25_000, ['anonymous' => true]));

    // Section 40 : seule l'identite est concernee par l'anonymat.
    expect(($this->progress)()['collected'])->toBe(25_000)
        ->and($donation->formattedSubtotal())->toContain('25');
});

it('n expose jamais l identite d un donateur en JSON', function () {
    $json = ($this->give)(5_000, ['email' => 'marie@exemple.ga'])->toArray();

    foreach (['donor_name', 'donor_email', 'payer_phone', 'ip_address'] as $private) {
        expect($json)->not->toHaveKey($private);
    }
});

/*
 * ---------------------------------------------------------------------------
 * Section 42 : aucun message
 * ---------------------------------------------------------------------------
 */

it('n offre aucun champ ou ecrire un message', function () {
    $columns = Schema::getColumnListing('donations');

    // La regle ne tient que si aucune colonne ne peut en accueillir un.
    foreach (['message', 'comment', 'note', 'testimonial'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }
});

/*
 * ---------------------------------------------------------------------------
 * Immuabilite (sections 44 a 46, tests 1, 2 et 4 de la section 75)
 * ---------------------------------------------------------------------------
 */

/*
 * Chaque tentative est isolee dans une transaction imbriquee : PostgreSQL
 * abandonne la transaction courante des qu'un declencheur leve une exception,
 * et tout ce qui suivrait echouerait pour la mauvaise raison. Le point de
 * sauvegarde permet de constater le refus PUIS de verifier que rien n'a bouge.
 */
$attempt = fn (callable $operation) => expect(fn () => DB::transaction($operation))
    ->toThrow(QueryException::class);

it('refuse en base la modification du montant d un don confirme', function () use ($attempt) {
    $donation = ($this->confirm)(($this->give)(50_000));

    // Le declencheur PostgreSQL tient meme pour une commande artisan ou une
    // correction manuelle : c'est la seule protection qui ne se contourne pas.
    $attempt(fn () => $donation->forceFill(['subtotal' => 500_000, 'amount' => 500_000])->save());

    expect($donation->fresh()->subtotal)->toBe(50_000);
});

it('refuse en base la suppression d un don confirme', function () use ($attempt) {
    $donation = ($this->confirm)(($this->give)(50_000));

    $attempt(fn () => $donation->delete());

    expect(Donation::query()->acrossOrganizations()->count())->toBe(1);
});

it('refuse en base de reveler un donateur anonyme', function () use ($attempt) {
    $donation = ($this->confirm)(($this->give)(50_000, ['anonymous' => true]));

    // Section 41 : l'organisateur ne doit pas pouvoir lever l'anonymat choisi
    // par le donateur.
    $attempt(fn () => $donation->forceFill(['is_anonymous' => false])->save());

    expect($donation->fresh()->is_anonymous)->toBeTrue();
});

it('refuse en base de deplacer un don vers une autre campagne', function () use ($attempt) {
    $donation = ($this->confirm)(($this->give)(50_000));

    $other = forOrganization($this->organization, fn () => Campaign::factory()->published()->create());

    $attempt(fn () => $donation->forceFill(['campaign_id' => $other->id])->save());

    expect($donation->fresh()->campaign_id)->toBe($this->campaign->id);
});

/*
 * ---------------------------------------------------------------------------
 * Le chemin de l'argent (sections 22, 23, tests 6 et 7 de la section 75)
 * ---------------------------------------------------------------------------
 */

it('confirme le don par le webhook signe de l operateur', function () {
    $donation = ($this->give)(20_000);

    sendDonationWebhook($donation);

    expect($donation->fresh()->status)->toBe(DonationStatus::Confirmed)
        ->and(($this->progress)()['collected'])->toBe(20_000);
});

it('ne compte qu une fois un webhook rejoue', function () {
    $donation = ($this->give)(20_000);

    // Section 23 : les webhooks mobile money sont rejoues, c'est normal.
    $eventId = 'evt-'.Str::uuid();
    sendDonationWebhook($donation, ['event_id' => $eventId]);
    sendDonationWebhook($donation, ['event_id' => $eventId]);

    expect(($this->progress)()['donations'])->toBe(1)
        ->and(($this->progress)()['collected'])->toBe(20_000)
        ->and(Commission::query()->acrossOrganizations()->count())->toBe(1);
});

it('refuse de confirmer un montant qui ne correspond pas', function () {
    $donation = ($this->give)(20_000);

    // Test 5 de la section 75 : une valeur venue du client ne change rien a la
    // verite du serveur.
    sendDonationWebhook($donation, ['amount' => 5_000]);

    expect($donation->fresh()->status)->toBe(DonationStatus::Pending)
        ->and(($this->progress)()['collected'])->toBe(0)
        ->and(PaymentWebhook::firstOrFail()->outcome)->toContain('Montant incoherent');
});

it('refuse un webhook dont la signature est invalide', function () {
    $donation = ($this->give)(20_000);

    // Section 22 : un message non authentifie ne confirme jamais rien.
    sendDonationWebhook($donation, [], signed: false);

    expect($donation->fresh()->status)->toBe(DonationStatus::Pending)
        ->and(($this->progress)()['collected'])->toBe(0);
});

it('refuse de confirmer un don annule', function () {
    $donation = ($this->give)(20_000);

    $donation->forceFill(['status' => DonationStatus::Cancelled])->save();

    expect(fn () => ($this->confirm)($donation->fresh()))->toThrow(RuntimeException::class);
});

it('accepte un paiement tardif : l argent est parti du telephone', function () {
    $donation = ($this->give)(20_000);

    $donation->forceFill(['expires_at' => now()->subMinutes(5)])->save();

    /*
     * Contrairement a une commande, un don n'immobilise aucun stock. Refuser
     * reviendrait a encaisser sans rien enregistrer — le pire des deux mondes.
     */
    ($this->confirm)($donation->fresh());

    expect($donation->fresh()->status)->toBe(DonationStatus::Confirmed)
        ->and(($this->progress)()['collected'])->toBe(20_000);
});

it('fait echouer un don reste impaye, sans toucher au total', function () {
    $donation = ($this->give)(20_000);

    $donation->forceFill(['expires_at' => now()->subMinute()])->save();

    (new ExpireDonationIfUnpaid($donation->getKey()))->handle(app(App\Services\Audit\AuditLogger::class));

    expect($donation->fresh()->status)->toBe(DonationStatus::Failed)
        ->and(($this->progress)()['collected'])->toBe(0);
});

/*
 * ---------------------------------------------------------------------------
 * Regles d'ouverture
 * ---------------------------------------------------------------------------
 */

it('refuse un don sur une collecte fermee', function () {
    forOrganization($this->organization, fn () => $this->campaign->forceFill([
        'status' => App\Enums\CampaignStatus::Paused,
    ])->save());

    expect(fn () => ($this->give)(10_000))->toThrow(RuntimeException::class);
});

it('refuse un don derisoire', function () {
    // En dessous, les frais du prestataire depassent la contribution.
    expect(fn () => ($this->give)(100))->toThrow(RuntimeException::class);
});

/*
 * ---------------------------------------------------------------------------
 * Journal et isolation
 * ---------------------------------------------------------------------------
 */

it('journalise la creation et la confirmation du don', function () {
    ($this->confirm)(($this->give)(10_000));

    $actions = AuditLog::query()->pluck('action')->map(fn ($a) => $a->value);

    expect($actions)->toContain(AuditAction::DonationCreated->value)
        ->and($actions)->toContain(AuditAction::DonationConfirmed->value);
});

it('n expose jamais les dons d une autre organisation', function () {
    ($this->confirm)(($this->give)(10_000));

    [, $other] = organizerWithOrganization('Autre association');

    actAsOrganization($other);

    // Regle 80.4 : la portee globale filtre, sans qu'aucune requete n'ait a
    // ecrire `where organization_id`.
    expect(Donation::query()->count())->toBe(0);
});

/**
 * Envoie un webhook de paiement, par le VRAI chemin : route signee, journal,
 * puis job. Un raccourci qui appellerait ConfirmPayment ne testerait rien de ce
 * qui compte.
 */
function sendDonationWebhook(Donation $donation, array $overrides = [], bool $signed = true): void
{
    $payload = array_merge([
        'event_id' => 'evt-'.Str::uuid(),
        'order_reference' => $donation->reference,
        'status' => 'SUCCEEDED',
        'amount' => $donation->amount,
        'provider_reference' => 'FAKE-REF-1',
    ], $overrides);

    $headers = $signed
        ? ['X-Pass241-Signature' => FakeProvider::sign($payload)]
        : ['X-Pass241-Signature' => 'forgee'];

    test()->postJson('/api/webhooks/payments/fake', $payload, $headers);

    foreach (PaymentWebhook::whereNull('processed_at')->where('signature_valid', true)->get() as $webhook) {
        (new ProcessPaymentWebhook($webhook->id))->handle(
            app(ConfirmPayment::class),
            app(PayableResolver::class),
        );
    }
}
