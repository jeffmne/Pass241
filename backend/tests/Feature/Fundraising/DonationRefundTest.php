<?php

use App\Actions\Donations\RefundDonation;
use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Livewire\Dashboard\Campaigns\Donations as DonationsScreen;
use App\Livewire\Public\CampaignPage;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Commission;
use App\Models\Donation;
use App\Models\DonationRefund;
use App\Services\Fundraising\CampaignProgress;
use App\Services\Fundraising\CampaignStatistics;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Remboursement d'un don, total ou partiel (sections 46, 47).
 *
 * La regle centrale tient en une phrase : LE DON N'EST JAMAIS MODIFIE. Une
 * operation nouvelle s'ajoute a lui, et tout le reste — montant collecte,
 * pourcentage, historique public, commission — se recalcule a partir de la.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->campaign, $this->donation] = forOrganization($this->organization, function () {
        $campaign = Campaign::factory()->published()->create(['goal_amount' => 1_000_000]);

        $donation = Donation::factory()->for($campaign)->confirmed()->create([
            'donor_name' => 'Marie Nzeng',
            'subtotal' => 100_000,
            'amount' => 100_000,
        ]);

        // La commission d'origine, telle que ConfirmPayment l'aurait ecrite.
        Commission::create([
            'organization_id' => $campaign->organization_id,
            'commissionable_type' => $donation->getMorphClass(),
            'commissionable_id' => $donation->getKey(),
            'type' => 'DONATION',
            'gross_amount' => 100_000,
            'rate_basis_points' => 250,
            'commission_amount' => 2_500,
            'net_amount' => 97_500,
        ]);

        return [$campaign, $donation];
    });

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $this->refund = fn (?int $amount, string $reason = 'Don en double.') => app(RefundDonation::class)
        ->handle($this->donation->fresh(), $amount, $reason, $this->owner);

    $this->progress = fn () => app(CampaignProgress::class)->forCampaign($this->campaign->fresh());
});

/*
 * ---------------------------------------------------------------------------
 * Le don n'est pas touche (section 46)
 * ---------------------------------------------------------------------------
 */

it('rembourse partiellement sans annuler le don', function () {
    ($this->refund)(30_000);

    $donation = $this->donation->fresh();

    /*
     * Section 47 : « un DonationRefund partiel n'annule pas la Donation, elle
     * reste CONFIRMED ». Le don a bien eu lieu ; ce qui change est ce qu'il
     * represente.
     */
    expect($donation->isConfirmed())->toBeTrue()
        ->and($donation->subtotal)->toBe(100_000)
        ->and($donation->refundedAmount())->toBe(30_000)
        ->and($donation->netAmount())->toBe(70_000);
});

it('recalcule le montant collecte tout seul', function () {
    expect(($this->progress)()['collected'])->toBe(100_000);

    ($this->refund)(40_000);

    /*
     * L'exemple de la section 47 : objectif 1 000 000, collecte 100 000, un
     * remboursement de 40 000 donne 60 000. Aucune valeur n'a ete corrigee —
     * il n'y en a aucune a corriger.
     */
    expect(($this->progress)()['collected'])->toBe(60_000)
        ->and(($this->progress)()['percentage'])->toBe(6.0);
});

it('rembourse la totalite du restant quand aucun montant n est donne', function () {
    ($this->refund)(25_000);
    ($this->refund)(null);

    expect($this->donation->fresh()->netAmount())->toBe(0)
        ->and(($this->progress)()['collected'])->toBe(0)
        // Le don reste dans l'historique : il ne s'en retire pas (section 45).
        ->and(Donation::query()->count())->toBe(1);
});

it('ne rend jamais plus qu il n a recu', function () {
    ($this->refund)(80_000);

    expect(fn () => ($this->refund)(30_000))->toThrow(RuntimeException::class);

    expect($this->donation->fresh()->netAmount())->toBe(20_000);
});

it('refuse de rembourser un don deja rembourse en totalite', function () {
    ($this->refund)(null);

    expect(fn () => ($this->refund)(1_000))->toThrow(RuntimeException::class);
});

it('exige un motif', function () {
    expect(fn () => ($this->refund)(1_000, '   '))->toThrow(RuntimeException::class);

    expect(DonationRefund::query()->count())->toBe(0);
});

/*
 * ---------------------------------------------------------------------------
 * Commission (sections 24.1, 47)
 * ---------------------------------------------------------------------------
 */

it('ajuste la commission par une ligne nouvelle, au taux d origine', function () {
    ($this->refund)(40_000);

    $adjustment = Commission::query()->whereNotNull('parent_commission_id')->firstOrFail();

    // Une correction s'ajoute au passe, elle ne le reecrit pas (section 46).
    expect($adjustment->gross_amount)->toBe(40_000)
        ->and($adjustment->commission_amount)->toBe(1_000)
        ->and($adjustment->rate_basis_points)->toBe(250)
        // La ligne d'origine est intacte.
        ->and(Commission::query()->originals()->firstOrFail()->gross_amount)->toBe(100_000);
});

/*
 * ---------------------------------------------------------------------------
 * Immuabilite de l'operation elle-meme
 * ---------------------------------------------------------------------------
 */

$attempt = fn (callable $operation) => expect(fn () => DB::transaction($operation))
    ->toThrow(QueryException::class);

it('refuse en base de modifier le montant d un remboursement', function () use ($attempt) {
    ($this->refund)(30_000);

    $refund = DonationRefund::query()->firstOrFail();

    $attempt(fn () => $refund->forceFill(['amount' => 90_000])->save());

    expect($refund->fresh()->amount)->toBe(30_000);
});

it('refuse en base de supprimer un remboursement', function () use ($attempt) {
    ($this->refund)(30_000);

    // Supprimer la ligne rendrait l'argent sans laisser de trace, donc
    // fausserait le montant collecte affiche publiquement.
    $attempt(fn () => DonationRefund::query()->firstOrFail()->delete());

    expect(DonationRefund::query()->count())->toBe(1);
});

/*
 * ---------------------------------------------------------------------------
 * Ce que le public voit (section 47)
 * ---------------------------------------------------------------------------
 */

it('affiche le montant net du don dans l historique public', function () {
    ($this->refund)(70_000);

    app(OrganizationContext::class)->forget();
    auth()->logout();

    Livewire::test(CampaignPage::class, ['slug' => $this->campaign->slug])
        ->assertOk()
        // « Le montant affiche publiquement pour ce don individuel doit
        // refleter le montant net apres remboursement partiel. »
        ->assertSee('Partiellement rembourse')
        ->assertSee('Marie Nzeng');
});

it('met a jour les statistiques de l organisateur', function () {
    ($this->refund)(40_000);

    $stats = app(CampaignStatistics::class)->forCampaign($this->campaign->fresh());

    expect($stats['collected'])->toBe(60_000)
        // 2,5 % de 60 000 : le net suit le collecte, sans intervention.
        ->and($stats['net'])->toBe(58_500);
});

/*
 * ---------------------------------------------------------------------------
 * Ecran et permissions
 * ---------------------------------------------------------------------------
 */

it('rembourse depuis l ecran des dons', function () {
    Livewire::test(DonationsScreen::class, ['campaign' => $this->campaign])
        ->call('startRefund', $this->donation->id)
        ->set('refundAmount', 25_000)
        ->set('refundReason', 'Don en double')
        ->call('refund')
        ->assertHasNoErrors();

    expect($this->donation->fresh()->netAmount())->toBe(75_000);
});

it('refuse le remboursement a qui n a pas le droit financier', function () {
    $member = addMemberTo($this->organization, RoleName::CoOrganizer);

    actAsOrganization($this->organization);
    $this->actingAs($member);

    /*
     * Rembourser, c'est faire sortir de l'argent : consulter les dons ne suffit
     * pas (section 10). Le co-organisateur n'a pas les droits financiers par
     * defaut.
     */
    Livewire::test(DonationsScreen::class, ['campaign' => $this->campaign])
        ->call('startRefund', $this->donation->id)
        ->assertForbidden();
});

it('refuse d atteindre le don d une autre collecte', function () {
    $other = forOrganization($this->organization, fn () => Campaign::factory()->published()->create());

    // Regle 80.4 : un identifiant venu du navigateur ne doit pas atteindre le
    // don d'une autre collecte. La requete est bornee a la campagne de l'ecran,
    // et ne trouve donc rien.
    expect(fn () => Livewire::test(DonationsScreen::class, ['campaign' => $other])
        ->call('startRefund', $this->donation->id)
        ->set('refundReason', 'Tentative')
        ->call('refund'))
        ->toThrow(ModelNotFoundException::class);

    expect($this->donation->fresh()->netAmount())->toBe(100_000);
});

it('journalise le remboursement', function () {
    ($this->refund)(40_000);

    $log = AuditLog::action(AuditAction::DonationRefunded)->firstOrFail();

    expect($log->metadata['amount'])->toBe(40_000)
        ->and($log->metadata['net'])->toBe(60_000)
        ->and($log->metadata['reason'])->toBe('Don en double.');
});
