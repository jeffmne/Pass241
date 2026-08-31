<?php

use App\Actions\Voting\CastFreeBallot;
use App\Enums\BallotStatus;
use App\Livewire\Public\PollPage;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Poll;
use App\Models\PollCategory;
use App\Services\Voting\VoteQuotaLedger;
use App\Services\Voting\VoterIdentity;
use App\Services\Voting\VotingStatistics;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Page publique et vote gratuit — jalon 9.3 (sections 86.4, 86.5).
 *
 * Ce fichier verifie deux choses de nature differente.
 *
 * D'abord le CONTRAT DE LA PAGE PUBLIQUE : elle s'ouvre sans compte, elle
 * refuse un brouillon, et surtout elle ne laisse jamais transiter un classement
 * annonce comme scelle — un resultat masque a l'affichage mais present dans le
 * HTML se lit en ouvrant l'inspecteur.
 *
 * Ensuite les GARANTIES ANTI-FRAUDE du vote gratuit (section 86.5) : le plafond
 * tenu par un UPDATE conditionnel, la fenetre verifiee sur les dates et non sur
 * l'etiquette, le numero jamais stocke en clair, et l'immuabilite du bulletin
 * tenue par la base. Chacune est verifiee la ou elle vit — plusieurs d'entre
 * elles ne valent que si PostgreSQL les tient, pas seulement une action PHP.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Comite des fetes');

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);
});

/**
 * Un scrutin actif, une categorie gratuite, deux candidats.
 *
 * @return array{0: Poll, 1: PollCategory, 2: Candidate, 3: Candidate}
 */
function activePollWithCandidates(array $categoryState = []): array
{
    $poll = Poll::factory()->active()->create();

    $category = PollCategory::factory()->for($poll)->state($categoryState)->create();

    return [
        $poll,
        $category,
        Candidate::factory()->for($category, 'category')->create(['name' => 'Awa Ndong']),
        Candidate::factory()->for($category, 'category')->create(['name' => 'Paul Obame']),
    ];
}

/**
 * Se placer dans la peau d'un visiteur : aucun compte, aucun contexte
 * d'organisation.
 *
 * Sans cela, un test de page publique passerait grace au contexte laisse par la
 * creation des donnees, et masquerait exactement le piege que `publicBySlug`
 * existe pour eviter — une requete qui revient vide chez un vrai visiteur.
 */
function asVisitor(): void
{
    auth()->logout();
    app(OrganizationContext::class)->forget();
    setPermissionsTeamId(null);
}

/** Une requete de vote, avec une adresse et un navigateur plausibles. */
function voterRequest(string $ip = '41.158.0.10'): Request
{
    return Request::create('/vote/test', 'POST', server: [
        'REMOTE_ADDR' => $ip,
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 12)',
    ]);
}

/**
 * Execute une ecriture dont on ATTEND qu'elle soit refusee par la base.
 *
 * Le point de sortie n'est pas evident : les tests tournent dans une
 * transaction, et une contrainte violee abandonne toute la transaction —
 * PostgreSQL refuse alors la moindre requete suivante avec « current
 * transaction is aborted ». Sans point de reprise, il devenait impossible de
 * verifier APRES coup que la ligne n'avait pas bouge, ce qui est precisement ce
 * que ces tests doivent montrer.
 *
 * Une transaction imbriquee pose un SAVEPOINT : l'echec ne defait que
 * l'ecriture refusee, et la transaction du test survit.
 */
function refusedByDatabase(callable $write): void
{
    expect(fn () => DB::transaction($write))
        ->toThrow(Illuminate\Database\QueryException::class);
}

/*
 * ---------------------------------------------------------------------------
 * La page publique
 * ---------------------------------------------------------------------------
 */

it('ouvre la page d\'un scrutin publie, sans compte', function () {
    [$poll, , $candidate] = activePollWithCandidates();

    asVisitor();

    $this->get('/vote/'.$poll->slug)
        ->assertOk()
        ->assertSee($poll->name, escape: false)
        ->assertSee($candidate->name, escape: false);
});

it('renvoie 404 sur un brouillon', function () {
    // Un brouillon n'est pas un scrutin prive : il n'existe pas encore. Rendre
    // une page vide laisserait croire a une erreur de lien.
    $poll = Poll::factory()->create();

    asVisitor();

    $this->get('/vote/'.$poll->slug)->assertNotFound();
});

it('garde la page consultable apres la cloture', function () {
    // Section 86.4 : « l'historique conserve ». Le lien partage continue de
    // fonctionner apres la fin du concours.
    $poll = Poll::factory()->closed()->create();

    asVisitor();

    $this->get('/vote/'.$poll->slug)->assertOk();
});

it('n\'affiche pas les categories desactivees', function () {
    $poll = Poll::factory()->active()->create();

    PollCategory::factory()->for($poll)->create(['name' => 'Miss Libreville']);
    PollCategory::factory()->for($poll)->inactive()->create(['name' => 'Categorie retiree']);

    asVisitor();

    $this->get('/vote/'.$poll->slug)
        ->assertOk()
        ->assertSee('Miss Libreville', escape: false)
        ->assertDontSee('Categorie retiree', escape: false);
});

it('affiche plusieurs categories, ouverte et scellee, sur la meme page', function () {
    /*
     * Ce test existe parce que son absence a laisse passer un 500.
     *
     * Tous les autres tests de cette page n'avaient qu'UNE categorie, et la
     * page ne cassait qu'a partir de DEUX : la boucle ecrasait la collection
     * des totaux au premier tour, puis lisait une cle de categorie dans un
     * tableau de compteurs au second. Un scrutin reel en a presque toujours
     * plusieurs — c'est la forme normale, pas un cas limite.
     *
     * Les deux visibilites sont melangees a dessein : c'est la combinaison du
     * scrutin de demonstration, et celle qui a revele le defaut.
     */
    $poll = Poll::factory()->active()->create();

    $ouverte = PollCategory::factory()->for($poll)->resultsLive()->create(['name' => 'Titre principal']);
    $scellee = PollCategory::factory()->for($poll)->create(['name' => 'Prix du public']);

    foreach ([$ouverte, $scellee] as $category) {
        Candidate::factory()->count(2)->for($category, 'category')->create();
    }

    asVisitor();

    $this->get('/vote/'.$poll->slug)
        ->assertOk()
        ->assertSee('Titre principal', escape: false)
        ->assertSee('Prix du public', escape: false);
});

it('dit a quoi sert le numero, la ou il est demande', function () {
    /*
     * Ce test remplace celui qui verifiait la mention du caractere indicatif
     * du resultat, retiree a la demande de Jeff (decision D-056).
     *
     * Ce qui reste doit rester : le numero est la donnee personnelle que la
     * page reclame, et le visiteur doit lire a quoi elle sert AVANT de la
     * donner, pas dans des conditions generales.
     */
    [$poll] = activePollWithCandidates(['max_votes_per_voter' => 1]);

    asVisitor();

    /*
     * Les fragments sont choisis pour tenir sur UNE ligne du fichier Blade :
     * le rendu conserve les retours a la ligne du source, et une assertion a
     * cheval sur deux lignes echoue sur un espace qu'on ne voit pas.
     */
    $this->get('/vote/'.$poll->slug)
        ->assertOk()
        ->assertSee('sert uniquement a compter', escape: false)
        ->assertSee('jamais conserve en clair', escape: false)
        // La mention retiree ne doit pas revenir par megarde.
        ->assertDontSee('indicatif', escape: false);
});

it('ne fait jamais transiter un classement scelle dans le HTML', function () {
    /*
     * Le point le plus important de cette page.
     *
     * Une categorie dont les resultats sont masques jusqu'a la cloture ne doit
     * pas seulement les cacher a l'affichage : le total ne doit pas figurer
     * dans la reponse. Sinon il suffit d'ouvrir l'inspecteur pour lire un
     * classement annonce comme scelle — et le concours est ouvert avant l'heure.
     */
    [, $category, $winner] = activePollWithCandidates(['max_votes_per_voter' => null]);

    $poll = $category->poll;

    // Un score sans ambiguite : s'il fuitait, on le verrait.
    forOrganization($this->organization, function () use ($winner) {
        app(CastFreeBallot::class)->handle($winner, 7, null, voterRequest());
    });

    expect($category->fresh()->showsPublicResults())->toBeFalse();

    asVisitor();

    $html = $this->get('/vote/'.$poll->slug)->assertOk()->getContent();

    expect($html)->not->toContain('"votes":7')
        ->and($html)->not->toContain('>7 voix')
        ->and($html)->not->toContain('>7 votes');
});

it('affiche le classement quand la categorie l\'ouvre au public', function () {
    [$poll, $category, $winner] = activePollWithCandidates([
        'max_votes_per_voter' => null,
    ]);

    $category->forceFill(['results_visibility' => App\Enums\ResultsVisibility::Live])->save();

    forOrganization($this->organization, function () use ($winner) {
        app(CastFreeBallot::class)->handle($winner, 3, null, voterRequest());
    });

    asVisitor();

    $this->get('/vote/'.$poll->slug)
        ->assertOk()
        ->assertSee($winner->name, escape: false)
        ->assertSee('3', escape: false);
});

/*
 * ---------------------------------------------------------------------------
 * Le vote gratuit
 * ---------------------------------------------------------------------------
 */

it('enregistre un vote gratuit, confirme et a montant nul', function () {
    [$poll, $category, $candidate] = activePollWithCandidates();

    $ballot = forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 1, '077123456', voterRequest()));

    expect($ballot->status)->toBe(BallotStatus::Confirmed)
        ->and($ballot->confirmed_at)->not->toBeNull()
        ->and($ballot->quantity)->toBe(1)
        ->and($ballot->unit_price)->toBe(0)
        ->and($ballot->amount)->toBe(0)
        ->and($ballot->candidate_id)->toBe($candidate->getKey())
        ->and($ballot->poll_id)->toBe($poll->getKey())
        ->and($ballot->organization_id)->toBe($this->organization->getKey())
        ->and($ballot->reference)->toStartWith('VT-');
});

it('compte le bulletin dans les totaux de la categorie', function () {
    [, $category, $candidate] = activePollWithCandidates(['max_votes_per_voter' => null]);

    forOrganization($this->organization, function () use ($candidate) {
        app(CastFreeBallot::class)->handle($candidate, 4, null, voterRequest());
    });

    $totals = app(VotingStatistics::class)->forCategory($category->fresh());

    // `votes` compte les voix, `ballots` les bulletins : un bulletin peut en
    // porter plusieurs. Comparaison souple sur les valeurs : PostgreSQL rend
    // ses agregats en chaines, et ce test porte sur le comptage, pas sur le
    // type que renvoie le pilote.
    expect((int) $totals['votes'])->toBe(4)
        ->and((int) $totals['ballots'])->toBe(1)
        ->and((int) $totals['revenue'])->toBe(0);
});

it('refuse un vote apres l\'echeance, meme si le statut dit encore ACTIVE', function () {
    /*
     * Section 86.5, point 8. Entre le moment ou la date de fin passe et celui
     * ou la tache planifiee s'execute, la colonne `status` ment. La fenetre est
     * donc verifiee sur les DATES, a chaque bulletin — un vote arrive dans cet
     * intervalle fausserait un classement deja annonce comme fige.
     */
    $poll = Poll::factory()->lapsed()->create();
    $category = PollCategory::factory()->for($poll)->create();
    $candidate = Candidate::factory()->for($category, 'category')->create();

    // L'etiquette dit encore ACTIVE : c'est tout l'interet du cas.
    expect($poll->status)->toBe(App\Enums\PollStatus::Active)
        ->and($poll->hasLapsed())->toBeTrue();

    forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 1, '077123456', voterRequest()));
})->throws(RuntimeException::class);

it('refuse un vote gratuit sur une categorie payante', function () {
    // Le vote payant passe par la couche paiement (jalon 9.4), jamais par
    // cette action : un bulletin payant nait en attente, pas confirme.
    [, , $candidate] = activePollWithCandidates(['is_paid' => true, 'unit_price' => 500, 'max_votes_per_voter' => null]);

    forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 1, null, voterRequest()));
})->throws(RuntimeException::class, 'payante');

it('refuse un vote pour un candidat desactive', function () {
    [, $category] = activePollWithCandidates();

    $candidate = Candidate::factory()->for($category, 'category')->inactive()->create();

    forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 1, '077123456', voterRequest()));
})->throws(RuntimeException::class);

/*
 * ---------------------------------------------------------------------------
 * Le plafond par votant
 * ---------------------------------------------------------------------------
 */

it('tient le plafond de votes par personne', function () {
    [, $category, $candidate] = activePollWithCandidates(['max_votes_per_voter' => 2]);

    forOrganization($this->organization, function () use ($candidate) {
        app(CastFreeBallot::class)->handle($candidate, 2, '077123456', voterRequest());
    });

    // Le meme numero, donc la meme empreinte : le plafond est atteint.
    forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 1, '077123456', voterRequest()));
})->throws(RuntimeException::class);

it('compare le plafond a la valeur STOCKEE, pas a une lecture prealable', function () {
    /*
     * C'est la propriete qui rend le compteur sur : la condition vit dans le
     * `WHERE` d'un UPDATE, donc elle est evaluee par PostgreSQL sur la ligne
     * verrouillee. Deux envois simultanes ne peuvent pas lire tous les deux
     * « il reste de la place » puis ecrire tous les deux.
     *
     * Le test le demontre en sequence : deux reservations de 2 sur un plafond
     * de 3 tiennent individuellement, mais pas ensemble — et la seconde ne
     * laisse aucune trace.
     */
    [, $category] = activePollWithCandidates(['max_votes_per_voter' => 3]);

    $ledger = app(VoteQuotaLedger::class);
    $hash = str_repeat('a', 64);

    expect($ledger->reserve($category, $hash, 2))->toBeTrue()
        ->and($ledger->reserve($category, $hash, 2))->toBeFalse()
        ->and($ledger->used($category, $hash))->toBe(2)
        ->and($ledger->remaining($category, $hash))->toBe(1);
});

it('rend les voix reservees quand l\'ecriture echoue', function () {
    [, $category] = activePollWithCandidates(['max_votes_per_voter' => 3]);

    $ledger = app(VoteQuotaLedger::class);
    $hash = str_repeat('b', 64);

    $ledger->reserve($category, $hash, 3);
    expect($ledger->remaining($category, $hash))->toBe(0);

    $ledger->release($category, $hash, 3);
    expect($ledger->remaining($category, $hash))->toBe(3);

    // Une liberation rejouee ne doit pas passer sous zero : la contrainte
    // refuserait la valeur, et un webhook rejoue ferait echouer la requete.
    $ledger->release($category, $hash, 3);
    expect($ledger->used($category, $hash))->toBe(0);
});

it('ne tient aucun quota quand la categorie n\'a pas de plafond', function () {
    // Rien a compter par personne : ouvrir une ligne de quota pour chaque
    // visiteur remplirait la table sans jamais servir.
    [, $category, $candidate] = activePollWithCandidates(['max_votes_per_voter' => null]);

    forOrganization($this->organization, function () use ($candidate) {
        app(CastFreeBallot::class)->handle($candidate, 5, null, voterRequest());
    });

    expect(DB::table('voter_quotas')->where('poll_category_id', $category->getKey())->count())
        ->toBe(0);
});

/*
 * ---------------------------------------------------------------------------
 * L'identite du votant
 * ---------------------------------------------------------------------------
 */

it('ne stocke jamais le numero du votant en clair', function () {
    /*
     * La page de resultats est publique. La liste des numeros ayant vote pour
     * tel candidat serait une donnee personnelle exposee, sur un sujet — un
     * choix — qui peut valoir des ennuis a quelqu'un.
     */
    [, , $candidate] = activePollWithCandidates();

    $ballot = forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 1, '077123456', voterRequest()));

    expect($ballot->voter_hash)->not->toContain('077123456')
        ->and($ballot->voter_hash)->toHaveLength(64)
        // Un vote gratuit ne pousse aucun paiement : il n'y a pas de numero
        // vers lequel envoyer une demande, donc rien a conserver.
        ->and($ballot->payer_phone)->toBeNull();

    expect(DB::table('ballots')->where('id', $ballot->getKey())->first())
        ->voter_hash->not->toBe('077123456');
});

it('reconnait le meme numero ecrit de trois facons differentes', function () {
    // « 077 12 34 56 », « +241 77 12 34 56 » et « 24177123456 » sont le meme
    // numero. Sans normalisation, la meme personne obtiendrait trois plafonds.
    $identity = app(VoterIdentity::class);
    $request = voterRequest();

    $a = $identity->hash(1, '077 12 34 56', $request);
    $b = $identity->hash(1, '+241 77 12 34 56', $request);
    $c = $identity->hash(1, '24177123456', $request);

    expect($a)->toBe($b)->and($b)->toBe($c);
});

it('sale l\'empreinte par categorie, pour ne pas reconstituer un bulletin complet', function () {
    /*
     * Deux categories du meme scrutin produisent deux empreintes differentes
     * pour le meme numero. Sans ce sel, recouper les bulletins permettrait de
     * reconstituer le vote complet d'une personne a travers tout le concours :
     * « le votant 4f2a… a choisi celui-ci, puis celui-la ».
     */
    $identity = app(VoterIdentity::class);
    $request = voterRequest();

    expect($identity->hash(1, '077123456', $request))
        ->not->toBe($identity->hash(2, '077123456', $request));
});

/*
 * ---------------------------------------------------------------------------
 * L'immuabilite, tenue par la base
 * ---------------------------------------------------------------------------
 */

it('refuse de reattribuer un bulletin a un autre candidat', function () {
    /*
     * Section 86.4 : « ni changer retroactivement le candidat choisi ».
     *
     * L'ecriture passe ici par `DB::table` VOLONTAIREMENT : c'est le chemin
     * qu'emprunterait une commande artisan ou une correction manuelle, celui
     * qu'une regle ecrite dans une action PHP ne couvrirait pas. Le declencheur
     * PostgreSQL, lui, le couvre.
     */
    [, , $candidate, $rival] = activePollWithCandidates();

    $ballot = forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 1, '077123456', voterRequest()));

    refusedByDatabase(fn () => DB::table('ballots')
        ->where('id', $ballot->getKey())
        ->update(['candidate_id' => $rival->getKey()]));

    expect($ballot->fresh()->candidate_id)->toBe($candidate->getKey());
});

it('refuse de modifier le nombre de voix d\'un bulletin', function () {
    [, , $candidate] = activePollWithCandidates(['max_votes_per_voter' => null]);

    $ballot = forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 2, null, voterRequest()));

    refusedByDatabase(fn () => DB::table('ballots')
        ->where('id', $ballot->getKey())
        ->update(['quantity' => 200]));

    expect($ballot->fresh()->quantity)->toBe(2);
});

it('refuse de supprimer un bulletin confirme', function () {
    // Supprimer les bulletins d'un candidat reviendrait exactement a lui
    // retirer ses voix : c'est la faille evidente que le second declencheur
    // ferme.
    [, , $candidate] = activePollWithCandidates();

    $ballot = forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 1, '077123456', voterRequest()));

    refusedByDatabase(fn () => DB::table('ballots')->where('id', $ballot->getKey())->delete());

    expect(Ballot::query()->acrossOrganizations()->whereKey($ballot->getKey())->exists())
        ->toBeTrue();
});

it('laisse le statut evoluer, lui', function () {
    /*
     * L'ajout seul pur ne conviendrait pas : un bulletin payant doit pouvoir
     * passer de PENDING a CONFIRMED quand le prestataire confirme, puis a
     * REFUNDED. Seuls le candidat, la quantite et le montant sont figes.
     */
    [, , $candidate] = activePollWithCandidates();

    $ballot = forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 1, '077123456', voterRequest()));

    DB::table('ballots')->where('id', $ballot->getKey())->update([
        'status' => BallotStatus::Refunded->value,
    ]);

    expect($ballot->fresh()->status)->toBe(BallotStatus::Refunded);
});

/*
 * ---------------------------------------------------------------------------
 * Isolation
 * ---------------------------------------------------------------------------
 */

it('refuse de deposer une voix sur le candidat d\'un autre scrutin', function () {
    /*
     * Le composant retrouve toujours le candidat EN PARTANT DU SCRUTIN de la
     * page. Un identifiant force dans une requete Livewire ne peut donc pas
     * atteindre le candidat d'un autre concours — chercher par
     * `Candidate::find()` ouvrirait exactement ce trou.
     */
    [$poll] = activePollWithCandidates();

    [, $otherOrganization] = organizerWithOrganization('Autre comite');

    $intruder = forOrganization($otherOrganization, function () {
        $otherPoll = Poll::factory()->active()->create();
        $otherCategory = PollCategory::factory()->for($otherPoll)->create();

        return Candidate::factory()->for($otherCategory, 'category')->create();
    });

    asVisitor();

    // La recherche part du scrutin de la page : le candidat d'un autre concours
    // n'y est simplement pas, et `findOrFail` le dit. En requete HTTP, Laravel
    // en fait un 404 ; ce qui compte ici est qu'aucune voix ne soit deposee.
    expect(fn () => Livewire::test(PollPage::class, ['slug' => $poll->slug])
        ->call('choose', $intruder->poll_category_id, $intruder->getKey()))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(Ballot::query()->acrossOrganizations()->count())->toBe(0);
});

it('n\'expose pas les bulletins d\'une autre organisation', function () {
    [, $category, $candidate] = activePollWithCandidates(['max_votes_per_voter' => null]);

    forOrganization($this->organization, function () use ($candidate) {
        app(CastFreeBallot::class)->handle($candidate, 3, null, voterRequest());
    });

    [, $otherOrganization] = organizerWithOrganization('Autre comite');

    actAsOrganization($otherOrganization);

    expect(Ballot::query()->count())->toBe(0);

    // Les totaux publics, eux, restent justes : ils sont bornes a UNE categorie
    // dont l'identifiant vient du serveur, et lus hors portee a dessein.
    expect(app(VotingStatistics::class)->forCategory($category->fresh())['votes'])->toBe(3);
});

/*
 * ---------------------------------------------------------------------------
 * Le parcours, vu du composant
 * ---------------------------------------------------------------------------
 */

it('enregistre un vote depuis la page et confirme au votant', function () {
    [$poll, , $candidate] = activePollWithCandidates(['max_votes_per_voter' => 2]);

    asVisitor();

    Livewire::test(PollPage::class, ['slug' => $poll->slug])
        ->call('choose', $candidate->poll_category_id, $candidate->getKey())
        ->set('quantity', 1)
        ->set('phone', '077123456')
        ->call('vote')
        ->assertHasNoErrors()
        ->assertSet('selectedCandidate', null)
        ->assertSee('enregistre');

    expect(Ballot::query()->acrossOrganizations()->count())->toBe(1);
});

it('exige le numero des qu\'un plafond existe', function () {
    /*
     * Decision D-055. Un plafond « par personne » suppose de savoir qui est la
     * personne, et le numero est le seul des identifiants disponibles qui se
     * normalise et qui coute quelque chose a multiplier.
     *
     * L'empreinte d'appareil ne pourrait pas porter ce plafond : deux personnes
     * derriere le meme partage de connexion — frequent au Gabon — la
     * partageraient, et la seconde se verrait refuser un vote legitime.
     */
    [$poll, , $candidate] = activePollWithCandidates(['max_votes_per_voter' => 1]);

    asVisitor();

    Livewire::test(PollPage::class, ['slug' => $poll->slug])
        ->call('choose', $candidate->poll_category_id, $candidate->getKey())
        ->set('phone', '')
        ->call('vote')
        ->assertHasErrors('phone');

    expect(Ballot::query()->acrossOrganizations()->count())->toBe(0);
});

it('compte le plafond sur le numero, pas sur la connexion', function () {
    /*
     * Deux votants DIFFERENTS derriere la meme adresse — une famille, un
     * bureau — doivent pouvoir voter tous les deux. C'est ce que l'empreinte
     * d'appareil seule rendait impossible, et la raison d'etre du numero.
     */
    [, $category, $candidate] = activePollWithCandidates(['max_votes_per_voter' => 1]);

    forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 1, '077111111', voterRequest('41.158.7.7')));

    // Meme numero, autre connexion : refuse. C'est la personne qui est comptee.
    expect(fn () => forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 1, '077111111', voterRequest('41.158.9.9'))))
        ->toThrow(RuntimeException::class);

    // Autre numero, MEME connexion : accepte.
    forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 1, '077222222', voterRequest('41.158.7.7')));

    expect((int) app(VotingStatistics::class)->forCategory($category->fresh())['votes'])->toBe(2);
});

it('enregistre tout de meme l\'adresse IP, pour l\'analyse d\'abus', function () {
    /*
     * L'adresse ne porte plus le plafond, mais elle reste consignee : c'est ce
     * qui permet de constater apres coup qu'une centaine de numeros ont vote
     * depuis une seule connexion. Elle est stockee en clair — c'est une donnee
     * technique — tandis que l'empreinte du votant, elle, reste hachee.
     */
    [, , $candidate] = activePollWithCandidates(['max_votes_per_voter' => 1]);

    $ballot = forOrganization($this->organization, fn () => app(CastFreeBallot::class)
        ->handle($candidate, 1, '077123456', voterRequest('41.158.7.7')));

    expect($ballot->ip_address)->toBe('41.158.7.7')
        ->and($ballot->user_agent_hash)->toHaveLength(64)
        ->and($ballot->voter_hash)->not->toContain('41.158.7.7');
});

it('ne demande pas de numero quand la categorie n\'a pas de plafond', function () {
    // Il n'y a rien a compter par personne : demander un numero ne servirait
    // qu'a collecter une donnee dont on n'a pas l'usage.
    [$poll, , $candidate] = activePollWithCandidates(['max_votes_per_voter' => null]);

    asVisitor();

    Livewire::test(PollPage::class, ['slug' => $poll->slug])
        ->call('choose', $candidate->poll_category_id, $candidate->getKey())
        ->set('phone', '')
        ->call('vote')
        ->assertHasNoErrors();

    expect(Ballot::query()->acrossOrganizations()->count())->toBe(1);
});

it('annonce que le vote payant n\'est pas encore ouvert', function () {
    // Le jalon 9.4 le branchera sur la couche paiement. En attendant, l'etat
    // est annonce honnetement plutot que de laisser cliquer dans le vide.
    [$poll, , $candidate] = activePollWithCandidates([
        'is_paid' => true,
        'unit_price' => 500,
        'max_votes_per_voter' => null,
    ]);

    asVisitor();

    Livewire::test(PollPage::class, ['slug' => $poll->slug])
        ->call('choose', $candidate->poll_category_id, $candidate->getKey())
        ->call('vote')
        ->assertHasErrors('vote');

    expect(Ballot::query()->acrossOrganizations()->count())->toBe(0);
});
