<?php

use App\Enums\RoleName;
use App\Livewire\Dashboard\Campaigns\Donations as DonationsScreen;
use App\Livewire\Public\CampaignPage;
use App\Models\Campaign;
use App\Models\Donation;
use App\Services\Fundraising\CampaignStatistics;
use App\Support\Tenancy\OrganizationContext;
use Livewire\Livewire;

/*
 * Transparence financiere d'une collecte (sections 37, 40, 41, 43, 48, 55).
 *
 * Le point le plus important de ce fichier tient en une phrase : l'organisateur
 * voit exactement ce que le public voit. Les memes chiffres, calcules au meme
 * endroit, et le meme « Donateur anonyme » — un anonymat que celui qui recoit
 * l'argent peut lever n'est pas un anonymat.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->campaign, $this->donations] = forOrganization($this->organization, function () {
        $campaign = Campaign::factory()->published()->create([
            'name' => 'Operation cartables 2026',
            'goal_amount' => 100_000,
        ]);

        return [$campaign, collect([
            Donation::factory()->for($campaign)->confirmed()->create([
                'donor_name' => 'Marie Nzeng',
                'subtotal' => 25_000,
                'amount' => 25_000,
                'payer_phone' => '074000001',
                'confirmed_at' => now()->subDays(2),
            ]),
            Donation::factory()->for($campaign)->confirmed()->anonymous()->create([
                'donor_name' => 'Paul Obame',
                'subtotal' => 15_000,
                'amount' => 15_000,
                'payer_phone' => '074000002',
                'confirmed_at' => now()->subDay(),
            ]),
            // En attente : ne doit apparaitre nulle part, ni dans les totaux,
            // ni dans l'historique (section 39).
            Donation::factory()->for($campaign)->create([
                'donor_name' => 'Yves Bekale',
                'subtotal' => 500_000,
                'amount' => 500_000,
                'payer_phone' => '074000003',
            ]),
        ])];
    });
});

/*
 * ---------------------------------------------------------------------------
 * La page publique (sections 37, 40, 43)
 * ---------------------------------------------------------------------------
 */

it('publie l historique des dons avec leur montant', function () {
    app(OrganizationContext::class)->forget();
    auth()->logout();

    Livewire::test(CampaignPage::class, ['slug' => $this->campaign->slug])
        ->assertOk()
        ->assertSee('Marie Nzeng')
        // Section 40 : le montant est visible dans les deux cas.
        ->assertSee('25', escape: false)
        ->assertSee('15', escape: false);
});

it('n affiche jamais le nom d un donateur anonyme', function () {
    app(OrganizationContext::class)->forget();
    auth()->logout();

    Livewire::test(CampaignPage::class, ['slug' => $this->campaign->slug])
        ->assertOk()
        ->assertSee('Donateur anonyme')
        ->assertDontSee('Paul Obame');
});

it('n affiche pas les dons non confirmes dans l historique public', function () {
    app(OrganizationContext::class)->forget();
    auth()->logout();

    // Un don en attente est une intention, pas de l'argent recu (section 39).
    Livewire::test(CampaignPage::class, ['slug' => $this->campaign->slug])
        ->assertOk()
        ->assertDontSee('Yves Bekale')
        ->assertSee('40', escape: false);
});

it('annonce le nombre total de dons, pas seulement ceux affiches', function () {
    forOrganization($this->organization, fn () => Donation::factory()
        ->for($this->campaign)
        ->confirmed()
        ->count(15)
        ->create(['subtotal' => 1_000, 'amount' => 1_000]));

    app(OrganizationContext::class)->forget();
    auth()->logout();

    $page = Livewire::test(CampaignPage::class, ['slug' => $this->campaign->slug]);

    // 17 dons confirmes, 10 affiches : c'est le total qui fait la transparence,
    // pas la longueur de la liste.
    $page->assertSee('17 dons au total')
        ->assertSee('Voir plus de dons');

    $page->call('loadMore')->assertOk();
});

/*
 * ---------------------------------------------------------------------------
 * Le tableau de bord (sections 41, 44, 45, 48, 55)
 * ---------------------------------------------------------------------------
 */

it('ne revele pas non plus l anonymat a l organisateur', function () {
    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    /*
     * Section 41 : la plateforme conserve l'identite reelle pour le paiement et
     * l'audit, mais ne la montre a personne — pas meme a celui qui recoit
     * l'argent.
     */
    Livewire::test(DonationsScreen::class, ['campaign' => $this->campaign])
        ->assertOk()
        ->assertSee('Donateur anonyme')
        ->assertDontSee('Paul Obame')
        ->assertSee('Marie Nzeng');
});

it('n expose aucune methode de correction sur les dons', function () {
    $ecriture = collect(get_class_methods(DonationsScreen::class))
        ->filter(fn (string $method) => (bool) preg_match(
            '/^(save|update|delete|destroy|remove|edit|correct|fix|hide|anonymize)/i', $method
        ));

    /*
     * Sections 44 et 45 : l'organisateur ne peut ni supprimer un don, ni en
     * modifier le montant, ni retirer un donateur de l'historique. La base
     * l'interdit deja par declencheur ; l'ecran ne doit meme pas suggerer que
     * ce serait possible.
     */
    expect($ecriture)->toBeEmpty();
});

it('calcule les statistiques de la collecte', function () {
    $stats = app(CampaignStatistics::class)->forCampaign($this->campaign);

    expect($stats['collected'])->toBe(40_000)
        ->and($stats['donations'])->toBe(2)
        ->and($stats['donors'])->toBe(2)
        ->and($stats['average'])->toBe(20_000)
        ->and($stats['largest'])->toBe(25_000)
        // 2,5 % de 40 000 = 1 000.
        ->and($stats['net'])->toBe(39_000);
});

it('rend l evolution jour par jour, jours creux compris', function () {
    $evolution = app(CampaignStatistics::class)->evolution($this->campaign, days: 7);

    expect($evolution)->toHaveCount(7)
        ->and($evolution->sum('amount'))->toBe(40_000)
        // Les jours sans don sont rendus a zero : les masquer ecraserait le
        // temps et ferait lire une progression reguliere.
        ->and($evolution->where('amount', 0)->count())->toBe(5);
});

it('masque le net a qui n a pas les droits financiers', function () {
    $member = addMemberTo($this->organization, RoleName::CoOrganizer);

    actAsOrganization($this->organization);
    $this->actingAs($member);

    /*
     * Le montant collecte est public (section 37) : le masquer n'aurait aucun
     * sens. Le net apres commission, lui, ne l'est pas.
     */
    Livewire::test(DonationsScreen::class, ['campaign' => $this->campaign])
        ->assertOk()
        ->assertSee('Collecte')
        ->assertDontSee('Net a reverser');
});

it('ferme l ecran des dons a qui ne peut pas voir les collectes', function () {
    $agent = addMemberTo($this->organization, RoleName::AccessAgent);

    actAsOrganization($this->organization);
    $this->actingAs($agent);

    $this->get(route('campaigns.donations', $this->campaign))->assertForbidden();
});

it('n expose jamais les dons d une autre organisation', function () {
    [$otherOwner, $other] = organizerWithOrganization('Autre association');

    $theirs = forOrganization($other, fn () => Campaign::factory()->published()->create());

    actAsOrganization($other);
    $this->actingAs($otherOwner);

    // Regle 80.4 : la liaison de route verifie l'appartenance, la portee
    // globale filtre le reste.
    $this->get(route('campaigns.donations', $this->campaign))->assertNotFound();

    Livewire::test(DonationsScreen::class, ['campaign' => $theirs])
        ->assertOk()
        ->assertDontSee('Marie Nzeng');
});
