<?php

use App\Actions\Campaigns\PublishCampaign;
use App\Actions\Campaigns\SaveCampaign;
use App\Actions\Donations\CreateDonation;
use App\Actions\Payments\ConfirmPayment;
use App\Data\CampaignDraft;
use App\Enums\AuditAction;
use App\Enums\DonationStatus;
use App\Enums\PaymentProvider;
use App\Jobs\ProcessPaymentWebhook;
use App\Livewire\Dashboard\Campaigns\Donations as DonationsScreen;
use App\Livewire\Public\CampaignPage;
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
use Livewire\Livewire;

/*
 * LES SEPT TESTS ANTI-FRAUDE OBLIGATOIRES (section 75).
 *
 * La specification les nomme un par un et les declare obligatoires. Ils sont
 * rassembles ici, dans l'ordre et avec leur numero, pour qu'on puisse verifier
 * d'un coup d'oeil qu'aucun ne manque — plutot que de les chercher disperses
 * dans six fichiers.
 *
 * Chacun est ecrit contre le CHEMIN REEL : l'ecran, l'action ou la base, jamais
 * un raccourci qui prouverait seulement que le test sait appeler une methode.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->campaign = forOrganization($this->organization, fn () => Campaign::factory()
        ->published()
        ->create(['goal_amount' => 1_000_000]));

    app(OrganizationContext::class)->forget();

    $this->give = fn (int $amount = 50_000) => app(CreateDonation::class)->handle(
        campaign: $this->campaign,
        amount: $amount,
        provider: PaymentProvider::Airtel,
        payerPhone: '074000001',
        donorName: 'Marie Nzeng',
    );

    $this->confirm = fn (Donation $d) => app(ConfirmPayment::class)->handle($d, 'FAKE-1');

    $this->collected = fn () => app(CampaignProgress::class)
        ->forCampaign($this->campaign->fresh())['collected'];
});

/*
 * ---------------------------------------------------------------------------
 * Test 1 — L'organisateur tente de supprimer un don confirme
 * ---------------------------------------------------------------------------
 */

it('test 1 : refuse la suppression d un don confirme', function () {
    $donation = ($this->confirm)(($this->give)());

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    // Aucun ecran ne l'offre — et la base le refuserait de toute facon, y
    // compris depuis une commande artisan.
    expect(fn () => DB::transaction(fn () => $donation->delete()))
        ->toThrow(QueryException::class);

    $ecriture = collect(get_class_methods(DonationsScreen::class))
        ->filter(fn (string $m) => (bool) preg_match('/^(delete|destroy|remove|hide)/i', $m));

    expect($ecriture)->toBeEmpty()
        ->and(Donation::query()->acrossOrganizations()->count())->toBe(1);
});

/*
 * ---------------------------------------------------------------------------
 * Test 2 — L'organisateur tente de modifier le montant d'un don
 * ---------------------------------------------------------------------------
 */

it('test 2 : refuse la modification du montant d un don', function () {
    $donation = ($this->confirm)(($this->give)());

    expect(fn () => DB::transaction(
        fn () => $donation->forceFill(['subtotal' => 900_000, 'amount' => 900_000])->save()
    ))->toThrow(QueryException::class);

    expect($donation->fresh()->subtotal)->toBe(50_000)
        ->and(($this->collected)())->toBe(50_000);
});

/*
 * ---------------------------------------------------------------------------
 * Test 3 — Le client envoie { "collectedAmount": 10000000 }
 * ---------------------------------------------------------------------------
 */

it('test 3 : ignore un montant collecte envoye par le client', function () {
    ($this->confirm)(($this->give)());

    /*
     * La requete n'a nulle part ou atterrir : il n'existe aucune colonne
     * `collected_amount`, `percentage` ni `donations_count` (regle 80.1). Le
     * chiffre est recalcule a chaque lecture.
     */
    foreach (['collected_amount', 'percentage', 'donations_count'] as $forbidden) {
        expect(Schema::hasColumn('campaigns', $forbidden))->toBeFalse();
    }

    // Et le composant public n'expose aucune propriete de ce nom qu'un client
    // Livewire pourrait pousser.
    $page = Livewire::test(CampaignPage::class, ['slug' => $this->campaign->slug]);

    expect(property_exists($page->instance(), 'collectedAmount'))->toBeFalse()
        ->and(($this->collected)())->toBe(50_000);
});

/*
 * ---------------------------------------------------------------------------
 * Test 4 — L'organisateur tente de supprimer un donateur
 * ---------------------------------------------------------------------------
 */

it('test 4 : refuse de retirer un donateur de l historique', function () {
    $donation = ($this->confirm)(($this->give)());

    // Effacer l'identite reviendrait a retirer la personne de l'historique
    // (section 45), et rendrait anonyme un don qui ne l'etait pas.
    expect(fn () => DB::transaction(
        fn () => $donation->forceFill(['donor_name' => null])->save()
    ))->toThrow(QueryException::class);

    // L'inverse est vrai aussi : on ne peut pas non plus lever un anonymat.
    $anonymous = ($this->confirm)(
        app(CreateDonation::class)->handle(
            campaign: $this->campaign,
            amount: 10_000,
            provider: PaymentProvider::Airtel,
            payerPhone: '074000002',
            donorName: 'Paul Obame',
            isAnonymous: true,
        )
    );

    expect(fn () => DB::transaction(
        fn () => $anonymous->forceFill(['is_anonymous' => false])->save()
    ))->toThrow(QueryException::class);

    expect(Donation::query()->acrossOrganizations()->count())->toBe(2);
});

/*
 * ---------------------------------------------------------------------------
 * Test 5 — Le frontend affiche 100 % : le backend dit la verite
 * ---------------------------------------------------------------------------
 */

it('test 5 : le serveur continue de rendre la vraie valeur', function () {
    ($this->confirm)(($this->give)(50_000));

    /*
     * Quoi qu'un navigateur modifie a l'ecran, la valeur est recalculee cote
     * serveur a chaque rendu. 50 000 sur 1 000 000 font 5 %, et rien ne peut
     * les transformer en 100 %.
     */
    $progress = app(CampaignProgress::class)->forCampaign($this->campaign->fresh());

    expect($progress['percentage'])->toBe(5.0)
        ->and($progress['collected'])->toBe(50_000);

    Livewire::test(CampaignPage::class, ['slug' => $this->campaign->slug])
        ->assertOk()
        ->assertSee('5 % de l\'objectif', escape: false)
        ->assertDontSee('100 % de l\'objectif', escape: false);
});

/*
 * ---------------------------------------------------------------------------
 * Test 6 — Un webhook de paiement est envoye deux fois
 * ---------------------------------------------------------------------------
 */

it('test 6 : un webhook rejoue ne confirme qu un seul don', function () {
    $donation = ($this->give)(50_000);

    $payload = [
        'event_id' => 'evt-'.Str::uuid(),
        'order_reference' => $donation->reference,
        'status' => 'SUCCEEDED',
        'amount' => $donation->amount,
        'provider_reference' => 'FAKE-1',
    ];

    // Le meme message, trois fois — ce que fait un operateur mobile money.
    foreach (range(1, 3) as $ignored) {
        $this->postJson('/api/webhooks/payments/fake', $payload, [
            'X-Pass241-Signature' => FakeProvider::sign($payload),
        ]);
    }

    foreach (PaymentWebhook::whereNull('processed_at')->where('signature_valid', true)->get() as $webhook) {
        (new ProcessPaymentWebhook($webhook->id))->handle(
            app(ConfirmPayment::class),
            app(PayableResolver::class),
        );
    }

    expect($donation->fresh()->status)->toBe(DonationStatus::Confirmed)
        ->and(($this->collected)())->toBe(50_000)
        // Une seule commission : c'est l'autre moitie de l'idempotence.
        ->and(Commission::query()->acrossOrganizations()->count())->toBe(1);
});

/*
 * ---------------------------------------------------------------------------
 * Test 7 — Un don echoue est presente comme confirme par le frontend
 * ---------------------------------------------------------------------------
 */

it('test 7 : refuse une confirmation qui ne vient pas du prestataire', function () {
    $donation = ($this->give)(50_000);

    // Un message non signe ne confirme rien (section 22).
    $payload = [
        'event_id' => 'evt-'.Str::uuid(),
        'order_reference' => $donation->reference,
        'status' => 'SUCCEEDED',
        'amount' => $donation->amount,
    ];

    $this->postJson('/api/webhooks/payments/fake', $payload, [
        'X-Pass241-Signature' => 'signature-forgee',
    ]);

    foreach (PaymentWebhook::whereNull('processed_at')->where('signature_valid', true)->get() as $webhook) {
        (new ProcessPaymentWebhook($webhook->id))->handle(
            app(ConfirmPayment::class),
            app(PayableResolver::class),
        );
    }

    expect($donation->fresh()->status)->toBe(DonationStatus::Pending)
        ->and(($this->collected)())->toBe(0);

    /*
     * Et la page de suivi ne confirme rien non plus : elle lit un etat, elle ne
     * le decide pas (section 50).
     *
     * Seules les methodes DECLAREES sur le composant sont examinees : Livewire
     * en apporte les siennes — `validate`, par exemple — qui n'ont rien a voir
     * avec la confirmation d'un paiement.
     */
    $declared = collect((new ReflectionClass(App\Livewire\Public\DonationStatus::class))->getMethods())
        ->filter(fn (ReflectionMethod $m) => $m->getDeclaringClass()->getName()
            === App\Livewire\Public\DonationStatus::class)
        ->map(fn (ReflectionMethod $m) => $m->getName())
        ->filter(fn (string $m) => (bool) preg_match('/^(confirm|approve|force)/i', $m));

    expect($declared)->toBeEmpty();
});

/*
 * ---------------------------------------------------------------------------
 * Audit (section 51)
 * ---------------------------------------------------------------------------
 */

it('journalise chaque operation sensible avec son acteur et son adresse', function () {
    ($this->confirm)(($this->give)());

    $created = AuditLog::action(AuditAction::DonationCreated)->firstOrFail();

    /*
     * Section 51 : « chaque log contient acteur, action, ressource,
     * resourceId, timestamp, IP, metadata. » L'acteur est nul ici, et c'est
     * exact : un don public n'a pas d'utilisateur authentifie.
     */
    expect($created->action)->toBe(AuditAction::DonationCreated)
        ->and($created->auditable_id)->not->toBeNull()
        ->and($created->auditable_type)->toBe(Donation::class)
        ->and($created->ip_address)->not->toBeNull()
        ->and($created->created_at)->not->toBeNull()
        ->and($created->metadata)->toHaveKey('amount')
        ->and($created->organization_id)->toBe($this->organization->id);
});

it('journalise tout le cycle de vie d une collecte', function () {
    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    // Par les actions reelles, du premier au dernier geste : une campagne posee
    // par une factory ne passerait par aucun journal, et ce test ne prouverait
    // rien.
    $campaign = app(SaveCampaign::class)->create(
        new CampaignDraft(
            name: 'Collecte journalisee',
            goalAmount: 500_000,
            beneficiaryLabel: 'Ecole publique',
            description: 'Une description.',
        ),
        $this->owner,
    );

    $campaign->forceFill(['poster_path' => 'campaigns/demo/image.jpg'])->save();

    $campaigns = app(PublishCampaign::class);
    $campaigns->publish($campaign->fresh(), $this->owner);
    $campaigns->pause($campaign->fresh(), $this->owner);
    $campaigns->resume($campaign->fresh(), $this->owner);
    $campaigns->complete($campaign->fresh(), $this->owner);

    $actions = AuditLog::query()->pluck('action')->map(fn ($a) => $a->value);

    // Les actions nommees par la section 51, toutes presentes.
    foreach ([
        AuditAction::CampaignCreated,
        AuditAction::CampaignPublished,
        AuditAction::CampaignPaused,
        AuditAction::CampaignCompleted,
    ] as $expected) {
        expect($actions)->toContain($expected->value);
    }
});
