<?php

use App\Actions\Campaigns\PublishCampaign;
use App\Actions\Donations\RefundDonation;
use App\Actions\Settlements\SettleFunds;
use App\Enums\PayoutState;
use App\Models\Campaign;
use App\Models\Commission;
use App\Models\Donation;
use App\Models\Settlement;
use App\Services\Settlement\SettlementLedger;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Notification;

/*
 * Reversement du net d'une collecte (sections 21.2, 47, decisions D-041, D-045).
 *
 * Le meme chemin que la billetterie, a une difference pres qui est le sujet de
 * ce fichier : QUAND une collecte est-elle terminee ? Un evenement a une date
 * de fin ; une collecte s'arrete quand l'organisateur la cloture, quand elle est
 * annulee, ou quand son echeance passe. Tant qu'elle est ouverte, rien ne part.
 */

beforeEach(function () {
    Notification::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    // Une destination de reversement, sans quoi rien ne peut partir.
    $this->organization->forceFill([
        'payout_phone' => '074000099',
        'payout_operator' => 'AIRTEL',
        'payout_name' => 'ASSOCIATION TEST',
        'kyc_status' => 'VERIFIED',
    ])->save();

    $this->campaign = forOrganization($this->organization, fn () => Campaign::factory()
        ->published()
        ->create(['goal_amount' => 1_000_000, 'ends_at' => now()->addMonth()]));

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $this->give = function (int $amount) {
        return forOrganization($this->organization, function () use ($amount) {
            $donation = Donation::factory()->for($this->campaign)->confirmed()->create([
                'subtotal' => $amount,
                'amount' => $amount,
            ]);

            Commission::create([
                'organization_id' => $this->organization->getKey(),
                'commissionable_type' => $donation->getMorphClass(),
                'commissionable_id' => $donation->getKey(),
                'type' => 'DONATION',
                'gross_amount' => $amount,
                'rate_basis_points' => 250,
                'commission_amount' => intdiv($amount * 250 + 5_000, 10_000),
                'net_amount' => $amount - intdiv($amount * 250 + 5_000, 10_000),
            ]);

            return $donation;
        });
    };

    $this->settle = fn () => app(SettleFunds::class)->handle($this->campaign->fresh()->load('organization'));
});

/*
 * ---------------------------------------------------------------------------
 * Quand la collecte est-elle terminee ?
 * ---------------------------------------------------------------------------
 */

it('ne reverse rien tant que la collecte est ouverte', function () {
    ($this->give)(100_000);

    /*
     * Decision D-045 : une collecte ouverte peut recevoir un don a la minute
     * suivante. La reverser reviendrait a la decouper en tranches, alors que le
     * produit ne prevoit qu'un seul virement par objet.
     */
    expect(($this->settle)())->toBeNull()
        ->and(Settlement::query()->count())->toBe(0);
});

it('ne reverse rien pour une collecte seulement suspendue', function () {
    ($this->give)(100_000);

    app(PublishCampaign::class)->pause($this->campaign, $this->owner);

    // Une collecte suspendue n'est pas terminee : elle peut rouvrir.
    expect(($this->settle)())->toBeNull();
});

it('reverse le net une fois la collecte cloturee', function () {
    ($this->give)(100_000);
    ($this->give)(50_000);

    app(PublishCampaign::class)->complete($this->campaign, $this->owner);

    $settlement = ($this->settle)();

    expect($settlement)->not->toBeNull()
        ->and($settlement->gross_amount)->toBe(150_000)
        // 2,5 % : 2 500 + 1 250.
        ->and($settlement->commission_amount)->toBe(3_750)
        ->and($settlement->net_amount)->toBe(146_250)
        ->and($settlement->settlable_id)->toBe($this->campaign->id)
        ->and($settlement->settlable_type)->toBe($this->campaign->getMorphClass());
});

it('reverse aussi une collecte annulee', function () {
    ($this->give)(100_000);

    app(PublishCampaign::class)->cancel($this->campaign, $this->owner, 'Cause resolue autrement.');

    /*
     * L'annulation ne rembourse pas automatiquement (decision D-042) : les dons
     * recus restent acquis, et doivent donc pouvoir etre reverses. Ne pas le
     * faire immobiliserait l'argent des donateurs sans raison.
     */
    expect(($this->settle)())->not->toBeNull();
});

it('reverse une collecte echue', function () {
    ($this->give)(100_000);

    forOrganization($this->organization, fn () => $this->campaign->forceFill([
        'ends_at' => now()->subDays(3),
        'status' => App\Enums\CampaignStatus::Expired->value,
    ])->save());

    expect(($this->settle)())->not->toBeNull();
});

/*
 * ---------------------------------------------------------------------------
 * Les garanties partagees avec la billetterie
 * ---------------------------------------------------------------------------
 */

it('ne cree jamais deux reversements pour la meme collecte', function () {
    ($this->give)(100_000);
    app(PublishCampaign::class)->complete($this->campaign, $this->owner);

    $first = ($this->settle)();
    $second = ($this->settle)();

    // Un virement rejoue envoie l'argent deux fois, et une somme envoyee en
    // double ne se recupere pas.
    expect($second->id)->toBe($first->id)
        ->and(Settlement::query()->count())->toBe(1);
});

it('deduit les remboursements du montant reverse', function () {
    $donation = ($this->give)(100_000);

    app(RefundDonation::class)->handle($donation, 40_000, 'Don en double.', $this->owner);

    app(PublishCampaign::class)->complete($this->campaign->fresh(), $this->owner);

    $settlement = ($this->settle)();

    /*
     * L'ajustement de commission se soustrait : c'est de l'argent rendu au
     * donateur, qui ne doit pas partir aussi chez l'organisateur.
     */
    expect($settlement->gross_amount)->toBe(60_000)
        ->and($settlement->net_amount)->toBe(58_500);
});

it('ne reverse rien quand aucun don n a ete confirme', function () {
    app(PublishCampaign::class)->complete($this->campaign, $this->owner);

    expect(($this->settle)())->toBeNull();
});

it('previent l organisateur que son reversement est parti', function () {
    ($this->give)(100_000);
    app(PublishCampaign::class)->complete($this->campaign, $this->owner);

    ($this->settle)();

    Notification::assertSentTo(
        $this->organization,
        App\Notifications\SettlementSent::class,
    );
});

/*
 * ---------------------------------------------------------------------------
 * La commande planifiee
 * ---------------------------------------------------------------------------
 */

it('reverse les collectes closes depuis la commande', function () {
    ($this->give)(100_000);
    app(PublishCampaign::class)->complete($this->campaign, $this->owner);

    // La cloture vient d'avoir lieu : on recule pour depasser le delai de
    // securite, quel qu'il soit.
    forOrganization($this->organization, fn () => $this->campaign->forceFill([
        'completed_at' => now()->subDays(5),
    ])->save());

    app(OrganizationContext::class)->forget();

    $this->artisan('pass241:settle-events')->assertSuccessful();

    expect(Settlement::query()->acrossOrganizations()->count())->toBe(1);
});

it('ne repasse pas sur une collecte deja reversee', function () {
    ($this->give)(100_000);
    app(PublishCampaign::class)->complete($this->campaign, $this->owner);

    forOrganization($this->organization, fn () => $this->campaign->forceFill([
        'completed_at' => now()->subDays(5),
    ])->save());

    app(OrganizationContext::class)->forget();

    $this->artisan('pass241:settle-events')->assertSuccessful();
    $this->artisan('pass241:settle-events')->assertSuccessful();

    expect(Settlement::query()->acrossOrganizations()->count())->toBe(1);
});

/*
 * ---------------------------------------------------------------------------
 * Le grand livre
 * ---------------------------------------------------------------------------
 */

it('ne melange jamais les commissions de deux collectes', function () {
    ($this->give)(100_000);

    $other = forOrganization($this->organization, fn () => Campaign::factory()->published()->create());

    forOrganization($this->organization, function () use ($other) {
        $donation = Donation::factory()->for($other)->confirmed()->create([
            'subtotal' => 999_000, 'amount' => 999_000,
        ]);

        Commission::create([
            'organization_id' => $this->organization->getKey(),
            'commissionable_type' => $donation->getMorphClass(),
            'commissionable_id' => $donation->getKey(),
            'type' => 'DONATION',
            'gross_amount' => 999_000,
            'rate_basis_points' => 250,
            'commission_amount' => 24_975,
            'net_amount' => 974_025,
        ]);
    });

    $totals = app(SettlementLedger::class)->totalsFor($this->campaign->fresh());

    expect($totals['gross'])->toBe(100_000)
        ->and($totals['transactions'])->toBe(1);
});

it('ne compte pas les dons non confirmes', function () {
    ($this->give)(100_000);

    forOrganization($this->organization, fn () => Donation::factory()
        ->for($this->campaign)
        ->create(['subtotal' => 500_000, 'amount' => 500_000]));

    // Un don en attente n'a rien encaisse : le reverser creerait de l'argent
    // qui n'existe pas.
    expect(app(SettlementLedger::class)->totalsFor($this->campaign->fresh())['gross'])
        ->toBe(100_000);
});

it('fige le beneficiaire au moment du reversement', function () {
    ($this->give)(100_000);
    app(PublishCampaign::class)->complete($this->campaign, $this->owner);

    $settlement = ($this->settle)();

    expect($settlement->recipient_phone)->toBe('074000099')
        ->and($settlement->status)->not->toBe(PayoutState::Failed);
});
