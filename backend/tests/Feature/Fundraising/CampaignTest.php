<?php

use App\Actions\Campaigns\PublishCampaign;
use App\Actions\Campaigns\SaveCampaign;
use App\Data\CampaignDraft;
use App\Enums\AuditAction;
use App\Enums\CampaignStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Services\Fundraising\CampaignProgress;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Schema;

/*
 * Campagnes de collecte (sections 34 a 38).
 *
 * Ce fichier verifie surtout ce que le produit REFUSE : saisir un montant
 * collecte, deplacer l'objectif apres publication, supprimer une collecte
 * partagee. Ce sont les regles 80.1 et les sections 44 a 46, et elles ne valent
 * que si elles sont tenues par la base et par les actions, pas par l'ecran.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    // `array_key_exists` et non `??` : un test qui passe `description: null`
    // veut precisement une description absente, pas la valeur par defaut.
    $value = fn (array $overrides, string $key, mixed $default) => array_key_exists($key, $overrides)
        ? $overrides[$key]
        : $default;

    $this->draft = fn (array $overrides = []) => new CampaignDraft(
        name: $value($overrides, 'name', 'Operation cartables 2026'),
        goalAmount: $value($overrides, 'goalAmount', 1_000_000),
        beneficiaryLabel: $value($overrides, 'beneficiaryLabel', 'Ecole publique de Nzeng-Ayong'),
        description: $value($overrides, 'description', 'Cent cartables pour la rentree.'),
        startsAt: $value($overrides, 'startsAt', null),
        endsAt: $value($overrides, 'endsAt', now()->addMonth()),
    );

    /*
     * L'image est exigee a la publication : elle sert d'apercu quand le lien
     * est partage. Un televersement reel n'apporterait rien a ces tests, seul
     * le chemin en base compte.
     */
    $this->create = function (array $overrides = []) {
        $campaign = app(SaveCampaign::class)->create(($this->draft)($overrides), $this->owner);

        $campaign->forceFill(['poster_path' => 'campaigns/demo/image.jpg'])->save();

        return $campaign->refresh();
    };
});

/*
 * ---------------------------------------------------------------------------
 * Ce qui ne peut pas etre saisi
 * ---------------------------------------------------------------------------
 */

it('ne stocke aucun montant collecte, nulle part', function () {
    /*
     * Regle 80.1, traduite en schema. Le montant collecte est la somme des dons
     * confirmes : une colonne le porterait en double, et cette seconde verite
     * serait modifiable. On ne peut pas falsifier ce qui n'existe pas.
     */
    foreach (['collected_amount', 'percentage', 'donations_count', 'donors_count'] as $forbidden) {
        expect(Schema::hasColumn('campaigns', $forbidden))->toBeFalse();
    }
});

it('calcule la progression cote serveur', function () {
    $campaign = ($this->create)(['goalAmount' => 2_000_000]);

    $progress = app(CampaignProgress::class)->forCampaign($campaign);

    // Aucun don confirme : c'est la reponse exacte, pas une valeur par defaut.
    expect($progress['goal'])->toBe(2_000_000)
        ->and($progress['collected'])->toBe(0)
        ->and($progress['donations'])->toBe(0)
        ->and($progress['percentage'])->toBe(0.0)
        ->and($progress['remaining'])->toBe(2_000_000);
});

it('refuse en base un objectif nul ou negatif', function () {
    // La contrainte vit en base et non dans le code : c'est la seule facon
    // qu'elle tienne aussi pour un import ou une correction manuelle.
    expect(fn () => Campaign::factory()->create(['goal_amount' => 0]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

/*
 * ---------------------------------------------------------------------------
 * Creation et publication
 * ---------------------------------------------------------------------------
 */

it('cree une collecte en brouillon avec un lien lisible', function () {
    $campaign = ($this->create)();

    expect($campaign->status)->toBe(CampaignStatus::Draft)
        ->and($campaign->slug)->toBe('operation-cartables-2026')
        ->and($campaign->isPublished())->toBeFalse()
        // Un brouillon n'a pas de page publique : il n'a jamais ete partage.
        ->and($campaign->status->hasPublicPage())->toBeFalse();
});

it('refuse de publier tant qu il manque une information', function () {
    $campaign = ($this->create)(['description' => null]);

    expect(fn () => app(PublishCampaign::class)->publish($campaign, $this->owner))
        ->toThrow(RuntimeException::class);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
});

it('annonce tous les manques d un coup', function () {
    $campaign = ($this->create)(['description' => null]);
    $campaign->forceFill(['poster_path' => null, 'beneficiary_label' => ''])->save();

    // Corriger un point pour en decouvrir un autre au clic suivant est penible :
    // le message les reprend tous.
    expect($campaign->refresh()->publicationBlockers())->toHaveCount(3);
});

it('publie la collecte et fige son lien', function () {
    $campaign = app(PublishCampaign::class)->publish(($this->create)(), $this->owner);

    expect($campaign->status)->toBe(CampaignStatus::Published)
        ->and($campaign->published_at)->not->toBeNull()
        ->and($campaign->acceptsDonations())->toBeTrue();

    /*
     * Le lien a pu partir dans un message : le modele refuse de le changer,
     * meme par une ecriture directe.
     */
    $campaign->slug = 'autre-lien';

    expect(fn () => $campaign->save())->toThrow(RuntimeException::class);
});

it('fige l objectif et le beneficiaire apres publication', function () {
    $campaign = app(PublishCampaign::class)->publish(($this->create)(), $this->owner);

    app(SaveCampaign::class)->update($campaign, ($this->draft)([
        'name' => 'Autre nom',
        'goalAmount' => 50_000,
        'beneficiaryLabel' => 'Quelqu un d autre',
        'description' => 'Description corrigee.',
    ]), $this->owner);

    $fresh = $campaign->fresh();

    /*
     * Ils engagent ceux qui ont deja donne : les changer apres coup reviendrait
     * a deplacer la cible. La description, elle, doit rester corrigeable — une
     * faute de frappe sur une page partagee se repare.
     */
    expect($fresh->goal_amount)->toBe(1_000_000)
        ->and($fresh->beneficiary_label)->toBe('Ecole publique de Nzeng-Ayong')
        ->and($fresh->name)->toBe('Operation cartables 2026')
        ->and($fresh->description)->toBe('Description corrigee.');
});

/*
 * ---------------------------------------------------------------------------
 * Cycle de vie
 * ---------------------------------------------------------------------------
 */

it('suspend puis rouvre une collecte sans toucher a sa page', function () {
    $campaigns = app(PublishCampaign::class);
    $campaign = $campaigns->publish(($this->create)(), $this->owner);

    $paused = $campaigns->pause($campaign, $this->owner);

    // La page reste en ligne : c'est tout l'interet de la suspension par
    // rapport a un retour au brouillon, qui masquerait des dons confirmes.
    expect($paused->status)->toBe(CampaignStatus::Paused)
        ->and($paused->status->hasPublicPage())->toBeTrue()
        ->and($paused->acceptsDonations())->toBeFalse();

    expect($campaigns->resume($paused, $this->owner)->status)->toBe(CampaignStatus::Published);
});

it('n autorise aucun retour au brouillon', function () {
    // Une collecte publiee a pu recevoir des dons, et son historique est public
    // (section 43). La masquer derriere un brouillon reviendrait a masquer des
    // dons confirmes.
    expect(CampaignStatus::Published->canTransitionTo(CampaignStatus::Draft))->toBeFalse()
        ->and(CampaignStatus::Paused->canTransitionTo(CampaignStatus::Draft))->toBeFalse();
});

it('exige un motif pour annuler', function () {
    $campaign = app(PublishCampaign::class)->publish(($this->create)(), $this->owner);

    expect(fn () => app(PublishCampaign::class)->cancel($campaign, $this->owner, '  '))
        ->toThrow(RuntimeException::class);

    $cancelled = app(PublishCampaign::class)->cancel($campaign, $this->owner, 'Fonds reunis autrement.');

    // La page reste en ligne et porte le motif : quelqu'un qui rouvre le lien
    // recu doit lire l'annulation, pas tomber sur une page introuvable.
    expect($cancelled->status)->toBe(CampaignStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Fonds reunis autrement.')
        ->and($cancelled->status->hasPublicPage())->toBeTrue();
});

it('refuse les dons des que la date de fin est passee', function () {
    $campaign = Campaign::factory()->lapsed()->create();

    /*
     * Le statut en base dit encore « en cours » : c'est la tache planifiee qui
     * le corrigera. La collecte, elle, doit refuser immediatement — sinon un
     * don passerait entre l'echeance et le balayage.
     */
    expect($campaign->status)->toBe(CampaignStatus::Published)
        ->and($campaign->acceptsDonations())->toBeFalse()
        ->and($campaign->displayStatus())->toBe(CampaignStatus::Expired);
});

it('ferme les collectes echues par la tache planifiee', function () {
    Campaign::factory()->lapsed()->create();
    $active = app(PublishCampaign::class)->publish(($this->create)(), $this->owner);

    app(OrganizationContext::class)->forget();

    $this->artisan('pass241:expire-campaigns')->assertSuccessful();

    actAsOrganization($this->organization);

    expect(Campaign::query()->status(CampaignStatus::Expired)->count())->toBe(1)
        // Une collecte encore dans les temps n'est pas touchee.
        ->and($active->fresh()->status)->toBe(CampaignStatus::Published);
});

/*
 * ---------------------------------------------------------------------------
 * Suppression
 * ---------------------------------------------------------------------------
 */

it('supprime un brouillon jamais publie', function () {
    $campaign = ($this->create)();

    app(SaveCampaign::class)->delete($campaign, $this->owner);

    expect(Campaign::query()->count())->toBe(0);
});

it('refuse de supprimer une collecte publiee', function () {
    $campaign = app(PublishCampaign::class)->publish(($this->create)(), $this->owner);

    /*
     * Sections 44 a 46 : la suppression effacerait avec la campagne les dons
     * qui lui sont rattaches. L'annulation existe pour arreter la collecte sans
     * rien detruire.
     */
    expect(fn () => app(SaveCampaign::class)->delete($campaign, $this->owner))
        ->toThrow(RuntimeException::class);

    expect(Campaign::query()->count())->toBe(1);
});

/*
 * ---------------------------------------------------------------------------
 * Journal et isolation
 * ---------------------------------------------------------------------------
 */

it('journalise la creation, la publication et l annulation', function () {
    $campaign = app(PublishCampaign::class)->publish(($this->create)(), $this->owner);
    app(PublishCampaign::class)->cancel($campaign, $this->owner, 'Fonds reunis autrement.');

    $actions = AuditLog::query()->pluck('action')->map(fn ($a) => $a->value);

    expect($actions)->toContain(AuditAction::CampaignCreated->value)
        ->and($actions)->toContain(AuditAction::CampaignPublished->value)
        ->and($actions)->toContain(AuditAction::CampaignCancelled->value);
});

it('n expose jamais la collecte d une autre organisation', function () {
    $mine = ($this->create)();

    [, $other] = organizerWithOrganization('Autre association');
    $theirs = forOrganization($other, fn () => Campaign::factory()->published()->create());

    actAsOrganization($this->organization);

    // Regle 80.4 : la portee globale filtre, sans qu'aucune requete n'ait a
    // ecrire `where organization_id`.
    expect(Campaign::query()->pluck('id')->all())->toBe([$mine->getKey()]);

    /*
     * La page publique, elle, sort de la portee : un visiteur n'appartient a
     * aucune organisation. L'isolation y tient au slug, unique sur la
     * plateforme.
     */
    expect(Campaign::publicBySlug($theirs->slug)?->getKey())->toBe($theirs->getKey());
});
