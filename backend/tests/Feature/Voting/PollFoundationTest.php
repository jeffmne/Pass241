<?php

use App\Enums\CommissionType;
use App\Enums\PermissionName;
use App\Enums\PollStatus;
use App\Enums\ResultsVisibility;
use App\Enums\RoleName;
use App\Models\Candidate;
use App\Models\Poll;
use App\Models\PollCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Fondations du module Votes — jalon 9.1 (sections 86.4, 86.5).
 *
 * Ce fichier verifie surtout ce que le schema REND IMPOSSIBLE : stocker un
 * compteur de votes, tarifer une categorie gratuite, laisser voter apres
 * l'echeance. Ce sont les garanties de la strategie anti-fraude, et elles ne
 * valent que si la BASE les tient — pas seulement un formulaire.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Comite des fetes');

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);
});

/*
 * ---------------------------------------------------------------------------
 * Ce qui ne peut pas etre stocke
 * ---------------------------------------------------------------------------
 */

it('ne stocke aucun compteur de votes, nulle part', function () {
    /*
     * Section 86.5, point 1. Le total d'un candidat est la somme de ses
     * bulletins confirmes, agregee en SQL. Une colonne la porterait en double,
     * et cette seconde verite serait modifiable — un organisateur ne peut pas
     * falsifier un chiffre qui n'est stocke nulle part.
     */
    foreach (['votes', 'votes_count', 'total_votes', 'score', 'rank'] as $forbidden) {
        expect(Schema::hasColumn('candidates', $forbidden))->toBeFalse(
            "La colonne candidates.$forbidden ne doit pas exister : un total de votes se calcule."
        );
        expect(Schema::hasColumn('poll_categories', $forbidden))->toBeFalse(
            "La colonne poll_categories.$forbidden ne doit pas exister."
        );
        expect(Schema::hasColumn('polls', $forbidden))->toBeFalse(
            "La colonne polls.$forbidden ne doit pas exister."
        );
    }
});

it('ne stocke aucun revenu sur le scrutin', function () {
    foreach (['revenue', 'total_amount', 'collected_amount'] as $forbidden) {
        expect(Schema::hasColumn('polls', $forbidden))->toBeFalse();
    }
});

/*
 * ---------------------------------------------------------------------------
 * Contraintes de base
 * ---------------------------------------------------------------------------
 */

it('refuse un scrutin dont la fin precede le debut', function () {
    Poll::factory()->create([
        'organization_id' => $this->organization->id,
        'starts_at' => now()->addMonth(),
        'ends_at' => now()->addWeek(),
    ]);
})->throws(QueryException::class);

it('refuse un scrutin dont la fenetre est vide', function () {
    // Ouvrir et fermer a la meme seconde : personne ne pourrait voter, et
    // l'organisateur ne comprendrait pas pourquoi.
    $instant = now();

    Poll::factory()->create([
        'organization_id' => $this->organization->id,
        'starts_at' => $instant,
        'ends_at' => $instant,
    ]);
})->throws(QueryException::class);

it('refuse un statut annule sans date d\'annulation', function () {
    Poll::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => PollStatus::Cancelled,
        'cancelled_at' => null,
    ]);
})->throws(QueryException::class);

it('refuse une categorie gratuite qui porte un prix', function () {
    PollCategory::factory()->create([
        'poll_id' => Poll::factory()->create(['organization_id' => $this->organization->id]),
        'is_paid' => false,
        'unit_price' => 500,
    ]);
})->throws(QueryException::class);

it('refuse une categorie payante a zero franc', function () {
    /*
     * Elle laisserait passer des bulletins payants d'un montant nul, que la
     * couche paiement accepterait sans rien encaisser. On ne le decouvrirait
     * qu'en lisant un rapport de revenus qui ne tombe pas juste.
     */
    PollCategory::factory()->create([
        'poll_id' => Poll::factory()->create(['organization_id' => $this->organization->id]),
        'is_paid' => true,
        'unit_price' => 0,
    ]);
})->throws(QueryException::class);

it('refuse un plafond de votes nul ou negatif', function () {
    PollCategory::factory()->create([
        'poll_id' => Poll::factory()->create(['organization_id' => $this->organization->id]),
        'max_votes_per_voter' => 0,
    ]);
})->throws(QueryException::class);

it('refuse une categorie dont une seule borne de fenetre est renseignee', function () {
    // Une fenetre a moitie heritee, a moitie propre serait impossible a
    // expliquer et impossible a verifier d'un coup d'oeil.
    PollCategory::factory()->create([
        'poll_id' => Poll::factory()->create(['organization_id' => $this->organization->id]),
        'starts_at' => now(),
        'ends_at' => null,
    ]);
})->throws(QueryException::class);

it('refuse deux candidats portant le meme numero dans une categorie', function () {
    $category = PollCategory::factory()->create([
        'poll_id' => Poll::factory()->create(['organization_id' => $this->organization->id]),
    ]);

    Candidate::factory()->create(['poll_category_id' => $category->id, 'number' => '7']);
    Candidate::factory()->create(['poll_category_id' => $category->id, 'number' => '7']);
})->throws(QueryException::class);

it('accepte le meme numero dans deux categories differentes', function () {
    /*
     * Le candidat 1 de « Miss Libreville » et le candidat 1 de « Meilleur
     * Artiste » sont deux personnes differentes. Se numeroter a partir de 1
     * dans chaque categorie est ce que tout le monde fait.
     */
    $poll = Poll::factory()->create(['organization_id' => $this->organization->id]);

    $first = PollCategory::factory()->create(['poll_id' => $poll->id]);
    $second = PollCategory::factory()->create(['poll_id' => $poll->id]);

    Candidate::factory()->create(['poll_category_id' => $first->id, 'number' => '1']);
    Candidate::factory()->create(['poll_category_id' => $second->id, 'number' => '1']);

    expect(Candidate::where('number', '1')->count())->toBe(2);
});

it('accepte plusieurs candidats sans numero dans une meme categorie', function () {
    // Le null echappe a l'unicite en PostgreSQL : c'est le comportement voulu
    // pour un concours qui ne numerote pas.
    $category = PollCategory::factory()->create([
        'poll_id' => Poll::factory()->create(['organization_id' => $this->organization->id]),
    ]);

    Candidate::factory()->unnumbered()->count(3)->create(['poll_category_id' => $category->id]);

    expect($category->candidates()->count())->toBe(3);
});

/*
 * ---------------------------------------------------------------------------
 * Fenetre de vote — la date prime sur l'etiquette
 * ---------------------------------------------------------------------------
 */

it('refuse un bulletin des l\'echeance passee, meme si le statut dit encore actif', function () {
    /*
     * Section 86.5, point 8. Entre le moment ou la date de fin passe et celui ou
     * la tache planifiee s'execute, la colonne `status` dit encore « ACTIVE ».
     * Le scrutin doit refuser immediatement : la verification porte sur la DATE.
     */
    $poll = Poll::factory()->lapsed()->create(['organization_id' => $this->organization->id]);

    expect($poll->status)->toBe(PollStatus::Active)
        ->and($poll->acceptsBallots())->toBeFalse()
        ->and($poll->displayStatus())->toBe(PollStatus::Closed);
});

it('refuse un bulletin avant l\'ouverture', function () {
    $poll = Poll::factory()->scheduled()->create(['organization_id' => $this->organization->id]);

    expect($poll->acceptsBallots())->toBeFalse();
});

it('accepte un bulletin dans la fenetre', function () {
    $poll = Poll::factory()->active()->create(['organization_id' => $this->organization->id]);

    expect($poll->acceptsBallots())->toBeTrue();
});

/*
 * ---------------------------------------------------------------------------
 * Categories independantes, et subordonnees au scrutin
 * ---------------------------------------------------------------------------
 */

it('herite de la fenetre du scrutin quand la categorie n\'en regle pas', function () {
    $poll = Poll::factory()->active()->create(['organization_id' => $this->organization->id]);
    $category = PollCategory::factory()->create(['poll_id' => $poll->id]);

    expect($category->hasOwnWindow())->toBeFalse()
        ->and($category->effectiveStartsAt()->timestamp)->toBe($poll->starts_at->timestamp)
        ->and($category->effectiveEndsAt()->timestamp)->toBe($poll->ends_at->timestamp);
});

it('n\'ecrit jamais la fenetre heritee en base', function () {
    /*
     * Une copie se desynchroniserait le jour ou l'organisateur decale la
     * fenetre du scrutin. Le null porte l'information « pas de reglage propre »,
     * qui n'est pas la meme chose qu'une valeur recopiee.
     */
    $poll = Poll::factory()->active()->create(['organization_id' => $this->organization->id]);
    $category = PollCategory::factory()->create(['poll_id' => $poll->id]);

    $row = DB::table('poll_categories')->find($category->id);

    expect($row->starts_at)->toBeNull()
        ->and($row->ends_at)->toBeNull();
});

it('laisse une categorie restreindre la fenetre du scrutin', function () {
    $poll = Poll::factory()->active()->create(['organization_id' => $this->organization->id]);

    $category = PollCategory::factory()
        ->window(now()->addWeek(), now()->addWeeks(2))
        ->create(['poll_id' => $poll->id]);

    expect($poll->acceptsBallots())->toBeTrue()
        ->and($category->acceptsBallots())->toBeFalse()
        ->and($category->closureReason())->toContain('ouvrent le');
});

it('empeche une categorie d\'ouvrir ce que le scrutin a ferme', function () {
    /*
     * La subordination est le point important : sans elle, une categorie mal
     * reglee laisserait voter apres la cloture du concours, et le classement
     * fige n'aurait plus de sens.
     */
    $poll = Poll::factory()->lapsed()->create(['organization_id' => $this->organization->id]);

    $category = PollCategory::factory()
        ->window(now()->subDay(), now()->addMonth())
        ->create(['poll_id' => $poll->id]);

    expect($category->acceptsBallots())->toBeFalse()
        ->and($category->closureReason())->toBe('Les votes sont termines.');
});

it('ferme une categorie desactivee sur un scrutin ouvert', function () {
    $poll = Poll::factory()->active()->create(['organization_id' => $this->organization->id]);
    $category = PollCategory::factory()->inactive()->create(['poll_id' => $poll->id]);

    expect($category->acceptsBallots())->toBeFalse();
});

/*
 * ---------------------------------------------------------------------------
 * Prix — calcule au serveur, jamais recu du navigateur
 * ---------------------------------------------------------------------------
 */

it('derive le montant du prix unitaire en base', function () {
    // Section 86.5, point 4. Une quantite venue du client est une intention ;
    // le montant, lui, se derive du prix en base.
    $category = PollCategory::factory()->paid(500)->create([
        'poll_id' => Poll::factory()->create(['organization_id' => $this->organization->id]),
    ]);

    expect($category->priceFor(1))->toBe(500)
        ->and($category->priceFor(10))->toBe(5_000)
        // Une quantite negative ne cree pas d'avoir.
        ->and($category->priceFor(-5))->toBe(0);
});

it('ne facture jamais une categorie gratuite', function () {
    $category = PollCategory::factory()->create([
        'poll_id' => Poll::factory()->create(['organization_id' => $this->organization->id]),
    ]);

    expect($category->priceFor(100))->toBe(0);
});

/*
 * ---------------------------------------------------------------------------
 * Visibilite des resultats
 * ---------------------------------------------------------------------------
 */

it('masque le classement public jusqu\'a la cloture par defaut', function () {
    $poll = Poll::factory()->active()->create(['organization_id' => $this->organization->id]);

    expect($poll->results_visibility)->toBe(ResultsVisibility::AfterClose)
        ->and($poll->showsPublicResults())->toBeFalse();
});

it('ouvre le classement public a la cloture', function () {
    $poll = Poll::factory()->closed()->create(['organization_id' => $this->organization->id]);

    expect($poll->showsPublicResults())->toBeTrue();
});

it('affiche le classement en direct quand l\'organisateur l\'a choisi', function () {
    $poll = Poll::factory()->active()->resultsLive()->create([
        'organization_id' => $this->organization->id,
    ]);

    expect($poll->showsPublicResults())->toBeTrue();
});

it('laisse une categorie regler sa visibilite independamment du scrutin', function () {
    // Un meme evenement peut porter un prix du public en direct et une election
    // principale scellee jusqu'a la fin.
    $poll = Poll::factory()->active()->create(['organization_id' => $this->organization->id]);

    $sealed = PollCategory::factory()->create(['poll_id' => $poll->id]);
    $live = PollCategory::factory()->resultsLive()->create(['poll_id' => $poll->id]);

    expect($sealed->showsPublicResults())->toBeFalse()
        ->and($live->showsPublicResults())->toBeTrue();
});

/*
 * ---------------------------------------------------------------------------
 * Page publique et isolation
 * ---------------------------------------------------------------------------
 */

it('ne donne pas de page publique a un brouillon', function () {
    $poll = Poll::factory()->create(['organization_id' => $this->organization->id]);

    expect(Poll::publicBySlug($poll->slug))->toBeNull();
});

it('garde la page publique consultable apres la cloture', function () {
    // Quelqu'un qui a vote doit pouvoir revenir voir le resultat.
    $poll = Poll::factory()->closed()->create(['organization_id' => $this->organization->id]);

    expect(Poll::publicBySlug($poll->slug)?->id)->toBe($poll->id);
});

it('isole les scrutins entre organisations', function () {
    // Section 80.4. Le socle multi-tenant s'applique aux votes comme au reste.
    $mine = Poll::factory()->create(['organization_id' => $this->organization->id]);

    [, $other] = organizerWithOrganization('Autre association');

    actAsOrganization($other);

    expect(Poll::query()->pluck('id'))->not->toContain($mine->id);
});

/*
 * ---------------------------------------------------------------------------
 * Suppression et publication
 * ---------------------------------------------------------------------------
 */

it('interdit de supprimer un scrutin deja publie', function () {
    // La suppression effacerait des bulletins avec le scrutin, dont des
    // bulletins payes. L'annulation existe pour cela.
    $poll = Poll::factory()->active()->create(['organization_id' => $this->organization->id]);

    expect($poll->isDeletable())->toBeFalse();
});

it('refuse de publier un scrutin sans categorie', function () {
    $poll = Poll::factory()->create(['organization_id' => $this->organization->id]);

    expect($poll->publicationBlockers())->toContain('Creez au moins une categorie active.');
});

it('refuse de publier une categorie a candidat unique', function () {
    /*
     * Un candidat unique n'est pas un vote, c'est une formalite : le public
     * cliquerait sur la seule option possible.
     */
    $poll = Poll::factory()->create(['organization_id' => $this->organization->id]);
    $category = PollCategory::factory()->create(['poll_id' => $poll->id, 'name' => 'Miss Libreville']);

    Candidate::factory()->create(['poll_category_id' => $category->id]);

    expect($poll->publicationBlockers())
        ->toContain('La categorie « Miss Libreville » a besoin d\'au moins deux candidats actifs.');
});

it('accepte de publier un scrutin complet', function () {
    $poll = Poll::factory()->create(['organization_id' => $this->organization->id]);
    $category = PollCategory::factory()->create(['poll_id' => $poll->id]);

    Candidate::factory()->count(2)->create(['poll_category_id' => $category->id]);

    expect($poll->publicationBlockers())->toBe([])
        ->and($poll->canBePublished())->toBeTrue();
});

it('ne compte pas un candidat desactive parmi les candidats requis', function () {
    $poll = Poll::factory()->create(['organization_id' => $this->organization->id]);
    $category = PollCategory::factory()->create(['poll_id' => $poll->id]);

    Candidate::factory()->count(2)->create(['poll_category_id' => $category->id]);
    Candidate::factory()->inactive()->create(['poll_category_id' => $category->id]);

    expect($poll->publicationBlockers())->toBe([]);
});

it('empeche de changer le lien public d\'un scrutin publie', function () {
    // Une fois le lien parti sur une affiche, le changer casse tout ce qui a
    // deja circule.
    $poll = Poll::factory()->active()->create(['organization_id' => $this->organization->id]);

    $poll->slug = 'nouveau-lien';
    $poll->save();
})->throws(RuntimeException::class);

/*
 * ---------------------------------------------------------------------------
 * Commission et permissions
 * ---------------------------------------------------------------------------
 */

it('applique 5 % de commission sur les votes payants', function () {
    // Un vote payant est une vente de service a un public, pas un don a une
    // cause : le taux est celui de la billetterie.
    expect(CommissionType::Voting->basisPoints())->toBe(500)
        ->and(CommissionType::Voting->formattedRate())->toBe('5 %');
});

it('accepte une commission de type vote en base', function () {
    $accepted = DB::selectOne("
        SELECT pg_get_constraintdef(oid) AS def
        FROM pg_constraint
        WHERE conname = 'commissions_type_check'
    ");

    expect($accepted->def)->toContain('VOTING');
});

it('donne au co-organisateur la consultation des votes, pas leur gestion', function () {
    // Ouvrir ou cloturer un scrutin decide de qui gagne un concours : c'est un
    // acte qui engage l'organisateur devant son public.
    $permissions = RoleName::CoOrganizer->defaultPermissions();

    expect($permissions)->toContain(PermissionName::VotesView->value)
        ->and($permissions)->not->toContain(PermissionName::VotesManage->value);
});

it('donne a l\'organisateur les deux permissions de vote', function () {
    $permissions = RoleName::Organizer->defaultPermissions();

    expect($permissions)->toContain(PermissionName::VotesView->value)
        ->and($permissions)->toContain(PermissionName::VotesManage->value);
});
