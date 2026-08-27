<?php

use App\Enums\CampaignStatus;
use App\Enums\RoleName;
use App\Livewire\Dashboard\Campaigns\Form as CampaignForm;
use App\Livewire\Dashboard\Campaigns\Index as CampaignIndex;
use App\Livewire\Public\CampaignPage;
use App\Models\Campaign;
use App\Support\Tenancy\OrganizationContext;
use Livewire\Livewire;

/*
 * Ecrans des collectes (sections 34 a 37, 56).
 *
 * Deux surfaces, deux publics : l'organisateur qui prepare sa collecte, et le
 * visiteur qui recoit un lien dans un message et decide en quelques secondes.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $this->fill = fn ($component) => $component
        ->set('name', 'Operation cartables 2026')
        ->set('description', 'Cent cartables pour la rentree.')
        ->set('goalAmount', 1_000_000)
        ->set('beneficiaryLabel', 'Ecole publique de Nzeng-Ayong')
        ->set('endsAt', now()->addMonth()->format('Y-m-d\TH:i'));
});

/*
 * ---------------------------------------------------------------------------
 * L'organisateur
 * ---------------------------------------------------------------------------
 */

it('cree une collecte depuis l ecran', function () {
    ($this->fill)(Livewire::test(CampaignForm::class))
        ->call('save')
        ->assertHasNoErrors();

    $campaign = Campaign::query()->firstOrFail();

    expect($campaign->name)->toBe('Operation cartables 2026')
        ->and($campaign->goal_amount)->toBe(1_000_000)
        ->and($campaign->status)->toBe(CampaignStatus::Draft);
});

it('refuse un objectif absent ou derisoire', function () {
    ($this->fill)(Livewire::test(CampaignForm::class))
        ->set('goalAmount', 10)
        ->call('save')
        ->assertHasErrors('goalAmount');

    expect(Campaign::query()->count())->toBe(0);
});

it('refuse une fin anterieure au debut', function () {
    ($this->fill)(Livewire::test(CampaignForm::class))
        ->set('startsAt', now()->addMonth()->format('Y-m-d\TH:i'))
        ->set('endsAt', now()->addDay()->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertHasErrors('endsAt');
});

it('publie depuis l ecran quand tout est renseigne', function () {
    $campaign = Campaign::factory()->create();

    Livewire::test(CampaignForm::class, ['campaign' => $campaign])
        ->set('description', 'Cent cartables pour la rentree.')
        ->call('saveAndPublish')
        ->assertHasNoErrors();

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Published);
});

it('explique le refus de publication au lieu de publier', function () {
    $campaign = Campaign::factory()->create(['description' => null]);
    $campaign->forceFill(['poster_path' => null])->save();

    Livewire::test(CampaignForm::class, ['campaign' => $campaign->refresh()])
        ->set('description', '')
        ->call('saveAndPublish')
        ->assertHasErrors('publication');

    // La saisie est enregistree malgre le refus : rien du travail n'est perdu.
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
});

it('suspend, rouvre et cloture depuis l ecran', function () {
    $campaign = Campaign::factory()->published()->create();

    $screen = Livewire::test(CampaignForm::class, ['campaign' => $campaign]);

    $screen->call('pause');
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused);

    $screen->call('resume');
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Published);

    $screen->call('complete');
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Completed);
});

it('exige un motif pour annuler depuis l ecran', function () {
    $campaign = Campaign::factory()->published()->create();

    Livewire::test(CampaignForm::class, ['campaign' => $campaign])
        ->call('cancel')
        ->assertHasErrors('cancellationReason');

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Published);
});

it('liste les collectes avec leur progression', function () {
    Campaign::factory()->published()->create(['name' => 'Operation cartables']);

    Livewire::test(CampaignIndex::class)
        ->assertOk()
        ->assertSee('Operation cartables')
        // La progression affichee vient du serveur, jamais d'une colonne
        // (regle 80.1). Sans don confirme, elle vaut zero.
        ->assertSee('0 %');
});

it('ferme l ecran a qui ne peut pas gerer les collectes', function () {
    $agent = addMemberTo($this->organization, RoleName::AccessAgent);

    actAsOrganization($this->organization);
    $this->actingAs($agent);

    $this->get(route('campaigns.index'))->assertForbidden();
    $this->get(route('campaigns.create'))->assertForbidden();
});

/*
 * ---------------------------------------------------------------------------
 * Le visiteur
 * ---------------------------------------------------------------------------
 */

it('affiche la page publique d une collecte publiee', function () {
    $campaign = Campaign::factory()->published()->create([
        'name' => 'Operation cartables 2026',
        'beneficiary_label' => 'Ecole publique de Nzeng-Ayong',
        'goal_amount' => 1_000_000,
    ]);

    app(OrganizationContext::class)->forget();
    auth()->logout();

    Livewire::test(CampaignPage::class, ['slug' => $campaign->slug])
        ->assertOk()
        ->assertSee('Operation cartables 2026')
        // Sections 37, 40 : objectif et beneficiaire sont publics.
        ->assertSee('Ecole publique de Nzeng-Ayong')
        ->assertSee('Association test');
});

it('ne publie pas la page d un brouillon', function () {
    $campaign = Campaign::factory()->create();

    app(OrganizationContext::class)->forget();
    auth()->logout();

    // 404 plutot qu'une page vide : un brouillon n'a jamais ete partage.
    $this->get(route('campaigns.public', $campaign->slug))->assertNotFound();
});

it('affiche le motif d annulation sur la page publique', function () {
    $campaign = Campaign::factory()->cancelled('Fonds reunis autrement.')->create();

    app(OrganizationContext::class)->forget();
    auth()->logout();

    Livewire::test(CampaignPage::class, ['slug' => $campaign->slug])
        ->assertOk()
        ->assertSee('Collecte annulee')
        ->assertSee('Fonds reunis autrement.');
});

it('sert la page publique sans contexte d organisation', function () {
    $campaign = Campaign::factory()->published()->create();

    app(OrganizationContext::class)->forget();
    auth()->logout();

    /*
     * LE PIEGE QUI S'EST DEJA REFERME PLUSIEURS FOIS : hors contexte, la portee
     * globale masque la campagne ou son organisation, et la page plante sur une
     * propriete manquante.
     */
    $this->get(route('campaigns.public', $campaign->slug))
        ->assertOk()
        ->assertSee($campaign->name, escape: false);
});
