<?php

use App\Enums\CampaignStatus;
use App\Enums\DonationStatus;
use App\Enums\PaymentState;
use App\Livewire\Public\CampaignPage;
use App\Livewire\Public\DonationForm;
use App\Livewire\Public\DonationStatus as DonationStatusPage;
use App\Models\Campaign;
use App\Models\Donation;
use App\Services\Payments\FakeProvider;
use App\Support\Tenancy\OrganizationContext;
use Livewire\Livewire;

/*
 * Parcours public du don (sections 39, 40, 50).
 *
 * Le donateur n'a pas de compte : tout ce fichier s'execute hors contexte
 * d'organisation, comme une vraie requete publique.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->campaign = forOrganization($this->organization, fn () => Campaign::factory()
        ->published()
        ->create(['name' => 'Operation cartables 2026', 'goal_amount' => 1_000_000]));

    app(OrganizationContext::class)->forget();
    auth()->logout();

    $this->form = fn () => Livewire::test(DonationForm::class, ['campaign' => $this->campaign])
        ->set('amount', 10_000)
        ->set('provider', 'AIRTEL')
        ->set('payerPhone', '074000001');
});

it('cree un don et emmene le donateur vers son suivi', function () {
    ($this->form)()
        ->set('donorName', 'Marie Nzeng')
        ->call('donate')
        ->assertHasNoErrors();

    $donation = Donation::query()->acrossOrganizations()->firstOrFail();

    expect($donation->subtotal)->toBe(10_000)
        ->and($donation->donor_name)->toBe('Marie Nzeng')
        ->and($donation->status)->toBe(DonationStatus::Pending)
        // Le montant debite depasse le don : les frais sont a la charge du
        // donateur, pour que la cause recoive le montant annonce (D-023).
        ->and($donation->amount)->toBeGreaterThan($donation->subtotal);
});

it('respecte le choix d anonymat du donateur', function () {
    ($this->form)()
        ->set('donorName', 'Marie Nzeng')
        ->set('isAnonymous', true)
        ->call('donate')
        ->assertHasNoErrors();

    $donation = Donation::query()->acrossOrganizations()->firstOrFail();

    // Section 41 : l'identite est conservee, seul l'affichage change.
    expect($donation->is_anonymous)->toBeTrue()
        ->and($donation->publicDonorName())->toBe('Donateur anonyme');
});

it('refuse un montant derisoire avant tout appel au prestataire', function () {
    ($this->form)()
        ->set('amount', 100)
        ->call('donate')
        ->assertHasErrors('amount');

    expect(Donation::query()->acrossOrganizations()->count())->toBe(0);
});

it('refuse un numero de telephone invalide', function () {
    ($this->form)()
        ->set('payerPhone', '12')
        ->call('donate')
        ->assertHasErrors('payerPhone');

    expect(Donation::query()->acrossOrganizations()->count())->toBe(0);
});

it('n offre aucun champ de message', function () {
    // Section 42 : ni en base, ni a l'ecran.
    ($this->form)()->assertDontSee('Message');
});

it('ouvre le don sur la page publique d une collecte en cours', function () {
    Livewire::test(CampaignPage::class, ['slug' => $this->campaign->slug])
        ->assertOk()
        ->assertSee('Faire un don');
});

it('ne propose pas de donner sur une collecte suspendue', function () {
    forOrganization($this->organization, fn () => $this->campaign->forceFill([
        'status' => CampaignStatus::Paused,
    ])->save());

    Livewire::test(CampaignPage::class, ['slug' => $this->campaign->slug])
        ->assertOk()
        // `escape: false` : le texte vient du gabarit, il n'est pas echappe a
        // l'affichage.
        ->assertSee('n\'accepte plus de dons', escape: false);
});

it('confirme le don depuis la page de suivi quand l operateur a valide', function () {
    ($this->form)()->call('donate');

    $donation = Donation::query()->acrossOrganizations()->firstOrFail();

    // Le prestataire repond « paye » au polling de secours (section 21.1).
    app(FakeProvider::class)->remember($donation, PaymentState::Succeeded);

    Livewire::test(DonationStatusPage::class, ['reference' => $donation->reference])
        ->call('refreshStatus')
        ->assertOk()
        ->assertSee('Merci pour votre don');

    expect($donation->fresh()->status)->toBe(DonationStatus::Confirmed);
});

it('garde la collecte visible au rafraichissement de la page de suivi', function () {
    ($this->form)()->call('donate');

    $donation = Donation::query()->acrossOrganizations()->firstOrFail();

    app(FakeProvider::class)->remember($donation, PaymentState::Succeeded);

    /*
     * LE BUG DU 2026-08-27, ET SA REGRESSION.
     *
     * Livewire rehydrate le modele SANS ses relations a chaque rafraichissement.
     * Le premier rendu passait, parce que `publicByReference()` avait charge la
     * campagne hors portee ; le suivant refaisait la requete, scopee cette
     * fois, et la campagne revenait nulle — le donateur perdait le nom de la
     * collecte et sa progression au moment meme ou son don etait confirme.
     *
     * Le modele reconstruit a la main reproduit exactement cet etat.
     */
    $rehydrated = Donation::query()->acrossOrganizations()->findOrFail($donation->getKey());

    expect($rehydrated->relationLoaded('campaign'))->toBeFalse();

    Livewire::test(DonationStatusPage::class, ['reference' => $donation->reference])
        ->call('refreshStatus')
        ->assertOk()
        ->assertSee('Operation cartables 2026')
        ->assertSee('Merci pour votre don');
});

it('protege le suivi d un don par la signature du lien', function () {
    ($this->form)()->call('donate');

    $donation = Donation::query()->acrossOrganizations()->firstOrFail();

    // Sans signature, la porte reste fermee : un don anonyme y perdrait son
    // anonymat (sections 41, decision D-004).
    $this->get(route('donations.show', $donation->reference))->assertForbidden();

    $this->get($donation->statusUrl())->assertOk();
});
