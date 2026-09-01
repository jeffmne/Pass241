<?php

use App\Actions\Payments\ConfirmPayment;
use App\Actions\Polls\PublishPoll;
use App\Actions\Voting\CastFreeBallot;
use App\Actions\Voting\CreatePaidBallot;
use App\Enums\BallotStatus;
use App\Enums\PaymentProvider;
use App\Enums\PollStatus;
use App\Livewire\Dashboard\Polls\Results as PollResultsScreen;
use App\Models\Candidate;
use App\Models\Poll;
use App\Models\PollCategory;
use App\Models\PollResult;
use App\Services\Voting\PollResultsFreezer;
use App\Services\Voting\VotingStatistics;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Resultats, cloture et rapport — jalon 9.5 (sections 86.4, 86.5).
 *
 * CE FICHIER DEFEND UNE SEULE IDEE, DECLINEE : un classement PROCLAME ne bouge
 * plus.
 *
 * Pendant le scrutin, rien n'est stocke — tout est agrege sur les bulletins,
 * pour qu'il n'y ait rien a falsifier. A la cloture, ce calcul est CONSERVE
 * dans `poll_results`, en ajout seul. Un remboursement arrive ensuite retire de
 * l'argent, mais ne peut plus deplacer un rang deja annonce.
 *
 * L'ecran et le rapport disent lequel des deux ils montrent : un organisateur
 * qui capture son ecran pendant le vote ne doit pas croire annoncer un gagnant.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Comite Miss Estuaire');

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $this->poll = Poll::factory()->active()->create(['name' => 'Miss Estuaire']);

    $this->category = PollCategory::factory()
        ->for($this->poll)
        ->unlimited()
        ->create(['name' => 'Titre principal']);

    $this->tete = Candidate::factory()->for($this->category, 'category')
        ->create(['name' => 'Awa Ndong', 'number' => '1']);

    $this->second = Candidate::factory()->for($this->category, 'category')
        ->create(['name' => 'Sylvie Mba', 'number' => '2']);

    $this->voter = fn (Candidate $c, int $n) => forOrganization(
        $this->organization,
        fn () => app(CastFreeBallot::class)->handle(
            $c, $n, null,
            Request::create('/v', 'POST', server: ['REMOTE_ADDR' => '41.158.0.'.random_int(1, 250)]),
        ),
    );

    $this->freezer = fn () => app(PollResultsFreezer::class);
});

/*
 * ---------------------------------------------------------------------------
 * Figer
 * ---------------------------------------------------------------------------
 */

it('fige le classement d\'un scrutin, rang par rang', function () {
    ($this->voter)($this->tete, 7);
    ($this->voter)($this->second, 3);

    ($this->freezer)()->freeze($this->poll);

    $results = PollResult::query()->acrossOrganizations()->orderBy('rank')->get();

    expect($results)->toHaveCount(2)
        ->and($results[0]->rank)->toBe(1)
        ->and($results[0]->candidate_name)->toBe('Awa Ndong')
        ->and($results[0]->votes)->toBe(7)
        ->and($results[0]->isWinner())->toBeTrue()
        ->and($results[1]->rank)->toBe(2)
        ->and($results[1]->votes)->toBe(3);
});

it('recopie le nom du candidat, pour qu\'un rapport relu dans deux ans tienne', function () {
    /*
     * Si l'organisateur corrige l'orthographe apres la cloture, le classement
     * archive doit garder le nom sous lequel le public a vote. Une jointure
     * vers `candidates` ferait dire au rapport autre chose que ce qui a ete
     * proclame.
     */
    ($this->voter)($this->tete, 1);
    ($this->freezer)()->freeze($this->poll);

    $this->tete->forceFill(['name' => 'Awa NDONG-Obame'])->save();

    expect(PollResult::query()->acrossOrganizations()->first()->candidate_name)
        ->toBe('Awa Ndong');
});

it('fige la part des voix en entiers, jamais en flottants', function () {
    // Decision D-003 : 42,35 % se stocke 4235. Un flottant finirait par
    // produire un total de 99,99 % sur un rapport officiel.
    ($this->voter)($this->tete, 2);
    ($this->voter)($this->second, 1);

    ($this->freezer)()->freeze($this->poll);

    $winner = PollResult::query()->acrossOrganizations()->where('rank', 1)->first();

    expect($winner->share_basis_points)->toBeInt()
        ->and($winner->share_basis_points)->toBe(6670)
        ->and($winner->sharePercentage())->toBe(66.7);
});

it('ne fige rien deux fois', function () {
    /*
     * La tache planifiee repasse sur les scrutins clos, y compris ceux clos a
     * la main. Sans idempotence, elle produirait des classements concurrents —
     * ou echouerait bruyamment chaque heure.
     */
    ($this->voter)($this->tete, 3);

    /*
     * Deux lignes pour un seul candidat vote : le classement porte TOUS les
     * candidats, y compris ceux a zero. Un palmares qui omettrait les perdants
     * ne serait pas un classement.
     */
    expect(($this->freezer)()->freeze($this->poll))->toBe(2)
        ->and(($this->freezer)()->freeze($this->poll))->toBe(0)
        ->and(PollResult::query()->acrossOrganizations()->count())->toBe(2);
});

it('fige aussi les candidats desactives, dont les voix comptent', function () {
    /*
     * Section 86.4 : les voix exprimees restent comptees. Retirer un candidat
     * desactive du classement final ferait mentir les totaux, et donnerait a
     * l'organisateur le moyen d'effacer un candidat genant apres coup.
     */
    ($this->voter)($this->tete, 2);
    ($this->voter)($this->second, 5);

    $this->second->forceFill(['is_active' => false])->save();

    ($this->freezer)()->freeze($this->poll);

    expect(PollResult::query()->acrossOrganizations()->where('rank', 1)->first()->candidate_name)
        ->toBe('Sylvie Mba');
});

/*
 * ---------------------------------------------------------------------------
 * Ce que le fige garantit
 * ---------------------------------------------------------------------------
 */

it('ne bouge plus quand un bulletin est rembourse apres la cloture', function () {
    /*
     * LE TEST QUI JUSTIFIE TOUTE CETTE TABLE.
     *
     * Un remboursement rend l'argent mais pas la voix. Le classement calcule
     * verrait donc son revenu changer ; le classement fige, lui, garde ce qui a
     * ete proclame.
     */
    $paid = PollCategory::factory()->for($this->poll)->paid(500)->unlimited()->create();
    $candidate = Candidate::factory()->for($paid, 'category')->create(['name' => 'Grace Ondo']);

    $ballot = forOrganization($this->organization, fn () => app(CreatePaidBallot::class)->handle(
        $candidate, 4, PaymentProvider::Airtel, '074000000',
        Request::create('/v', 'POST', server: ['REMOTE_ADDR' => '41.158.9.9']),
    ));

    app(ConfirmPayment::class)->handle($ballot, 'REF-1');

    ($this->freezer)()->freeze($this->poll->refresh());

    $frozen = PollResult::query()->acrossOrganizations()
        ->where('poll_category_id', $paid->getKey())->first();

    expect($frozen->votes)->toBe(4)
        ->and($frozen->revenue)->toBe(2_000);

    // Le bulletin passe en rembourse : la voix reste, l'argent part.
    DB::table('ballots')->where('id', $ballot->getKey())
        ->update(['status' => BallotStatus::Refunded->value]);

    app(VotingStatistics::class)->forget($paid);

    // Le calcul a bouge...
    expect(app(VotingStatistics::class)->standings($paid->fresh(), fresh: true)
        ->firstWhere('candidate_id', $candidate->id)['revenue'])->toBe(0);

    // ... mais pas ce qui a ete proclame.
    expect($frozen->fresh()->revenue)->toBe(2_000);
});

it('refuse toute modification d\'un resultat proclame', function () {
    ($this->voter)($this->tete, 4);
    ($this->freezer)()->freeze($this->poll);

    $result = PollResult::query()->acrossOrganizations()->first();

    // `DB::table` volontairement : c'est le chemin d'une commande artisan ou
    // d'une correction manuelle, que seule la base peut fermer.
    expect(fn () => DB::transaction(fn () => DB::table('poll_results')
        ->where('id', $result->getKey())->update(['votes' => 999])))
        ->toThrow(QueryException::class);

    expect($result->fresh()->votes)->toBe(4);
});

it('refuse la suppression d\'un resultat proclame', function () {
    ($this->voter)($this->tete, 1);
    ($this->freezer)()->freeze($this->poll);

    $result = PollResult::query()->acrossOrganizations()->first();

    expect(fn () => DB::transaction(fn () => DB::table('poll_results')
        ->where('id', $result->getKey())->delete()))
        ->toThrow(QueryException::class);

    expect(PollResult::query()->acrossOrganizations()->count())->toBe(2);
});

/*
 * ---------------------------------------------------------------------------
 * La tache planifiee
 * ---------------------------------------------------------------------------
 */

it('clot les scrutins echus et fige leurs resultats', function () {
    ($this->voter)($this->tete, 5);

    $this->poll->forceFill(['ends_at' => now()->subHour()])->save();

    $this->artisan('pass241:close-polls')->assertSuccessful();

    expect($this->poll->fresh()->status)->toBe(PollStatus::Closed)
        ->and(PollResult::query()->acrossOrganizations()->count())->toBe(2);
});

it('fige aussi un scrutin clos a la main, que la tache n\'a pas ferme', function () {
    // La tache balaye TOUS les scrutins clos, pas seulement ceux qu'elle vient
    // de fermer : un figeage rate la veille doit pouvoir etre repris.
    ($this->voter)($this->tete, 2);

    app(PublishPoll::class)->close($this->poll, $this->owner);

    expect(PollResult::query()->acrossOrganizations()->count())->toBe(0);

    $this->artisan('pass241:close-polls')->assertSuccessful();

    expect(PollResult::query()->acrossOrganizations()->count())->toBe(2);
});

it('ne touche pas a un scrutin encore ouvert', function () {
    ($this->voter)($this->tete, 3);

    $this->artisan('pass241:close-polls')->assertSuccessful();

    expect($this->poll->fresh()->status)->toBe(PollStatus::Active)
        ->and(PollResult::query()->acrossOrganizations()->count())->toBe(0);
});

/*
 * ---------------------------------------------------------------------------
 * L'ecran
 * ---------------------------------------------------------------------------
 */

it('annonce clairement qu\'un classement n\'est pas encore definitif', function () {
    /*
     * Un organisateur qui capture cet ecran pour annoncer un gagnant se
     * tromperait. L'ecran doit le dire, pas le laisser deviner.
     */
    ($this->voter)($this->tete, 6);

    Livewire::test(PollResultsScreen::class, ['poll' => $this->poll])
        ->assertSee('pas encore definitif', escape: false)
        ->assertDontSee('Classement definitif', escape: false);
});

it('bascule sur le classement fige une fois le scrutin clos', function () {
    ($this->voter)($this->tete, 6);

    app(PublishPoll::class)->close($this->poll, $this->owner);
    ($this->freezer)()->freeze($this->poll->refresh());

    Livewire::test(PollResultsScreen::class, ['poll' => $this->poll->fresh()])
        ->assertSee('Classement definitif', escape: false)
        ->assertSee('Gagnant', escape: false)
        ->assertDontSee('pas encore definitif', escape: false);
});

it('refuse l\'ecran a qui n\'a pas le droit de consulter les votes', function () {
    [$intrus] = organizerWithOrganization('Autre comite');

    $this->actingAs($intrus);
    actAsOrganization($this->organization);

    Livewire::test(PollResultsScreen::class, ['poll' => $this->poll])
        ->assertForbidden();
});
