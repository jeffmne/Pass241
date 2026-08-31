<?php

use App\Actions\Polls\PublishPoll;
use App\Actions\Polls\SaveCandidate;
use App\Actions\Polls\SavePoll;
use App\Actions\Polls\SavePollCategory;
use App\Data\PollDraft;
use App\Enums\AuditAction;
use App\Enums\PollStatus;
use App\Enums\ResultsVisibility;
use App\Livewire\Dashboard\Polls\Categories as PollCategories;
use App\Livewire\Dashboard\Polls\Form as PollForm;
use App\Livewire\Dashboard\Polls\Index as PollIndex;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Poll;
use App\Models\PollCategory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/*
 * Dashboard organisateur des votes — jalon 9.2 (sections 86.4, 87).
 *
 * Ce fichier verifie surtout ce que le produit REFUSE : changer les regles du
 * jeu une fois le vote ouvert, supprimer un candidat qui a pu recevoir des
 * voix, publier un concours incomplet. Ce sont les garanties de la section
 * 86.4, et elles ne valent que si les ACTIONS les tiennent — pas seulement les
 * champs desactives a l'ecran.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Comite Miss Estuaire');

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $this->draft = fn (array $overrides = []) => new PollDraft(
        name: $overrides['name'] ?? 'Election Miss Estuaire 2026',
        startsAt: $overrides['startsAt'] ?? now()->addDay(),
        endsAt: $overrides['endsAt'] ?? now()->addMonth(),
        description: array_key_exists('description', $overrides)
            ? $overrides['description']
            : 'Le concours annuel du comite.',
        resultsVisibility: $overrides['resultsVisibility'] ?? ResultsVisibility::AfterClose,
    );

    /*
     * L'image est exigee a la publication : elle sert d'apercu quand le lien
     * est partage. Un televersement reel n'apporterait rien a ces tests, seul
     * le chemin en base compte.
     */
    $this->makePoll = function (array $overrides = []) {
        $poll = app(SavePoll::class)->create(($this->draft)($overrides), $this->owner);

        $poll->forceFill(['poster_path' => 'polls/demo/image.jpg'])->save();

        return $poll->refresh();
    };

    /** Un scrutin complet, publiable : une categorie, deux candidats. */
    $this->makeReadyPoll = function (array $overrides = []) {
        $poll = ($this->makePoll)($overrides);

        $category = app(SavePollCategory::class)->create(
            $poll,
            ['name' => 'Miss Libreville', 'is_paid' => false, 'max_votes_per_voter' => 1],
            $this->owner,
        );

        foreach (['Awa Ndong', 'Ines Moussavou'] as $index => $name) {
            app(SaveCandidate::class)->create(
                $category,
                ['name' => $name, 'number' => (string) ($index + 1)],
                $this->owner,
            );
        }

        return $poll->refresh();
    };
});

/*
 * ---------------------------------------------------------------------------
 * Creation et modification
 * ---------------------------------------------------------------------------
 */

it('cree un vote en brouillon, avec un lien unique', function () {
    $poll = ($this->makePoll)();

    expect($poll->status)->toBe(PollStatus::Draft)
        ->and($poll->slug)->not->toBeEmpty()
        ->and($poll->created_by)->toBe($this->owner->getKey())
        ->and($poll->organization_id)->toBe($this->organization->getKey());
});

it('journalise la creation d\'un vote', function () {
    $poll = ($this->makePoll)();

    expect(AuditLog::query()
        ->where('action', AuditAction::PollCreated->value)
        ->where('auditable_id', $poll->getKey())
        ->exists())->toBeTrue();
});

it('laisse le lien suivre le nom tant que le vote n\'est pas publie', function () {
    $poll = ($this->makePoll)();
    $before = $poll->slug;

    $poll = app(SavePoll::class)->update($poll, ($this->draft)(['name' => 'Grand Prix du Public']), $this->owner);

    expect($poll->slug)->not->toBe($before)
        ->and($poll->slug)->toContain('grand-prix');
});

it('fige le lien des la publication, meme si le vote n\'a pas encore ouvert', function () {
    /*
     * Le cas se distingue de celui des collectes : un scrutin programme est
     * PUBLIE — sa page circule pendant la campagne d'annonce — tout en restant
     * modifiable. La condition porte donc sur la publication, pas sur la
     * modifiabilite.
     */
    $poll = ($this->makeReadyPoll)();
    app(PublishPoll::class)->publish($poll, $this->owner);

    $poll->refresh();
    $before = $poll->slug;

    expect($poll->status)->toBe(PollStatus::Scheduled);

    $poll = app(SavePoll::class)->update($poll, ($this->draft)(['name' => 'Autre nom']), $this->owner);

    expect($poll->slug)->toBe($before);
});

/*
 * ---------------------------------------------------------------------------
 * Les regles du jeu se figent a l'ouverture
 * ---------------------------------------------------------------------------
 */

it('refuse de changer la fenetre de vote une fois le vote ouvert', function () {
    /*
     * Ceux qui ont vote — et surtout ceux qui ont paye pour voter — l'ont fait
     * sous les regles annoncees. Les changer en cours de partie reviendrait a
     * rallonger un scrutin qui se joue mal.
     */
    $poll = ($this->makeReadyPoll)(['startsAt' => now()->subHour()]);
    app(PublishPoll::class)->publish($poll, $this->owner);

    $poll->refresh();
    $originalEnd = $poll->ends_at;

    $poll = app(SavePoll::class)->update(
        $poll,
        ($this->draft)(['startsAt' => now()->subHour(), 'endsAt' => now()->addYear()]),
        $this->owner,
    );

    expect($poll->ends_at->timestamp)->toBe($originalEnd->timestamp);
});

it('refuse de devoiler un classement promis scelle', function () {
    $poll = ($this->makeReadyPoll)(['startsAt' => now()->subHour()]);
    app(PublishPoll::class)->publish($poll, $this->owner);

    $poll = app(SavePoll::class)->update(
        $poll->refresh(),
        ($this->draft)(['startsAt' => now()->subHour(), 'resultsVisibility' => ResultsVisibility::Live]),
        $this->owner,
    );

    expect($poll->results_visibility)->toBe(ResultsVisibility::AfterClose);
});

it('laisse corriger la description d\'un vote ouvert', function () {
    // Une faute de frappe sur une page partagee se repare.
    $poll = ($this->makeReadyPoll)(['startsAt' => now()->subHour()]);
    app(PublishPoll::class)->publish($poll, $this->owner);

    $poll = app(SavePoll::class)->update(
        $poll->refresh(),
        ($this->draft)(['startsAt' => now()->subHour(), 'description' => 'Texte corrige.']),
        $this->owner,
    );

    expect($poll->description)->toBe('Texte corrige.');
});

it('refuse toute modification d\'un vote clos', function () {
    $poll = Poll::factory()->closed()->create(['organization_id' => $this->organization->id]);

    app(SavePoll::class)->update($poll, ($this->draft)(), $this->owner);
})->throws(RuntimeException::class);

it('fige le prix d\'une categorie une fois le vote ouvert', function () {
    // Quelqu'un qui a paye 500 francs son vote l'a fait sous ces regles.
    $poll = ($this->makeReadyPoll)(['startsAt' => now()->subHour()]);

    $category = app(SavePollCategory::class)->create(
        $poll,
        ['name' => 'Prix du public', 'is_paid' => true, 'unit_price' => 500],
        $this->owner,
    );

    // Deux candidats : sans eux, la publication serait refusee pour une raison
    // qui n'a rien a voir avec ce que ce test verifie.
    foreach (['Artiste A', 'Artiste B'] as $name) {
        app(SaveCandidate::class)->create($category, ['name' => $name], $this->owner);
    }

    app(PublishPoll::class)->publish($poll->refresh(), $this->owner);

    $category = app(SavePollCategory::class)->update(
        $category->refresh(),
        ['name' => 'Prix du public', 'is_paid' => true, 'unit_price' => 2_000],
        $this->owner,
    );

    expect($category->unit_price)->toBe(500);
});

it('laisse renommer une categorie d\'un vote ouvert', function () {
    $poll = ($this->makeReadyPoll)(['startsAt' => now()->subHour()]);
    app(PublishPoll::class)->publish($poll, $this->owner);

    $category = $poll->refresh()->categories()->first();

    $category = app(SavePollCategory::class)->update(
        $category,
        ['name' => 'Miss Estuaire'],
        $this->owner,
    );

    expect($category->name)->toBe('Miss Estuaire');
});

/*
 * ---------------------------------------------------------------------------
 * Publication
 * ---------------------------------------------------------------------------
 */

it('programme le vote quand son ouverture est a venir', function () {
    /*
     * Un seul geste pour l'organisateur : il publie, et la date d'ouverture
     * decide si le vote demarre tout de suite ou attend.
     */
    $poll = ($this->makeReadyPoll)(['startsAt' => now()->addWeek()]);

    $poll = app(PublishPoll::class)->publish($poll, $this->owner);

    expect($poll->status)->toBe(PollStatus::Scheduled)
        ->and($poll->published_at)->not->toBeNull();
});

it('ouvre le vote immediatement quand son heure est deja passee', function () {
    $poll = ($this->makeReadyPoll)(['startsAt' => now()->subHour()]);

    $poll = app(PublishPoll::class)->publish($poll, $this->owner);

    expect($poll->status)->toBe(PollStatus::Active)
        ->and($poll->acceptsBallots())->toBeTrue();
});

it('refuse de publier un vote incomplet, en listant tous les manques d\'un coup', function () {
    // Corriger un point pour en decouvrir un autre au clic suivant est penible.
    $poll = ($this->makePoll)(['description' => null]);

    app(PublishPoll::class)->publish($poll, $this->owner);
})->throws(RuntimeException::class, 'description');

it('ramene la date d\'ouverture quand l\'organisateur ouvre en avance', function () {
    /*
     * Sans cela, `acceptsBallots()` continuerait de refuser : la fenetre prime
     * toujours sur l'etiquette (section 86.5, point 8).
     */
    $poll = ($this->makeReadyPoll)(['startsAt' => now()->addWeek()]);
    app(PublishPoll::class)->publish($poll, $this->owner);

    $poll = app(PublishPoll::class)->open($poll->refresh(), $this->owner);

    expect($poll->status)->toBe(PollStatus::Active)
        ->and($poll->hasStarted())->toBeTrue()
        ->and($poll->acceptsBallots())->toBeTrue();
});

it('refuse d\'ouvrir un vote dont la fin est passee', function () {
    $poll = Poll::factory()->scheduled()->create([
        'organization_id' => $this->organization->id,
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->subDay(),
    ]);

    app(PublishPoll::class)->open($poll, $this->owner);
})->throws(RuntimeException::class);

it('journalise la cloture automatique sans inventer d\'acteur', function () {
    // La tache planifiee n'a pas d'utilisateur : lui en attribuer un mentirait
    // sur qui a agi.
    $poll = Poll::factory()->active()->create(['organization_id' => $this->organization->id]);

    app(PublishPoll::class)->close($poll);

    $log = AuditLog::query()
        ->where('action', AuditAction::PollClosed->value)
        ->where('auditable_id', $poll->getKey())
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBeNull();
});

it('exige un motif pour annuler', function () {
    $poll = ($this->makeReadyPoll)(['startsAt' => now()->subHour()]);
    app(PublishPoll::class)->publish($poll, $this->owner);

    app(PublishPoll::class)->cancel($poll->refresh(), $this->owner, '   ');
})->throws(RuntimeException::class);

/*
 * ---------------------------------------------------------------------------
 * Categories et candidats
 * ---------------------------------------------------------------------------
 */

it('numerote les categories dans leur ordre de creation', function () {
    $poll = ($this->makePoll)();

    $first = app(SavePollCategory::class)->create($poll, ['name' => 'Une'], $this->owner);
    $second = app(SavePollCategory::class)->create($poll->refresh(), ['name' => 'Deux'], $this->owner);

    expect($second->position)->toBeGreaterThan($first->position);
});

it('remet le prix a zero quand une categorie repasse en gratuit', function () {
    /*
     * Le cas courant : l'organisateur decoche « payant » sans vider le champ
     * prix. La base refuserait la ligne — la normalisation traite le cas avant,
     * sans rien relacher.
     */
    $poll = ($this->makePoll)();

    $category = app(SavePollCategory::class)->create(
        $poll,
        ['name' => 'Prix', 'is_paid' => true, 'unit_price' => 500],
        $this->owner,
    );

    $category = app(SavePollCategory::class)->update(
        $category,
        ['is_paid' => false, 'unit_price' => 500],
        $this->owner,
    );

    expect($category->is_paid)->toBeFalse()
        ->and($category->unit_price)->toBe(0);
});

it('traduit un plafond vide en votes illimites', function () {
    // Zero ne veut pas dire « illimite » mais « aucun vote autorise », ce que la
    // base refuse.
    $poll = ($this->makePoll)();

    $category = app(SavePollCategory::class)->create(
        $poll,
        ['name' => 'Prix', 'max_votes_per_voter' => 0],
        $this->owner,
    );

    expect($category->max_votes_per_voter)->toBeNull();
});

it('interdit de supprimer une categorie d\'un vote publie', function () {
    $poll = ($this->makeReadyPoll)();
    app(PublishPoll::class)->publish($poll, $this->owner);

    app(SavePollCategory::class)->delete($poll->refresh()->categories()->first(), $this->owner);
})->throws(RuntimeException::class, 'desactivez-la');

it('interdit d\'ajouter une categorie a un vote clos', function () {
    $poll = Poll::factory()->closed()->create(['organization_id' => $this->organization->id]);

    app(SavePollCategory::class)->create($poll, ['name' => 'Trop tard'], $this->owner);
})->throws(RuntimeException::class);

it('interdit de supprimer un candidat d\'un vote publie', function () {
    // Supprimer emporterait les bulletins deja exprimes pour lui.
    $poll = ($this->makeReadyPoll)();
    app(PublishPoll::class)->publish($poll, $this->owner);

    $candidate = $poll->refresh()->categories()->first()->candidates()->first();

    app(SaveCandidate::class)->delete($candidate, $this->owner);
})->throws(RuntimeException::class, 'desactivez-le');

it('desactive un candidat sans toucher a ses voix', function () {
    /*
     * La desactivation ferme le vote POUR LUI ; elle n'efface rien. Le public
     * qui a vote pour lui ne doit pas voir sa voix s'evaporer.
     */
    $poll = ($this->makeReadyPoll)(['startsAt' => now()->subHour()]);
    app(PublishPoll::class)->publish($poll, $this->owner);

    $candidate = $poll->refresh()->categories()->first()->candidates()->first();

    $candidate = app(SaveCandidate::class)->disable($candidate, $this->owner, 'Disqualifie.');

    expect($candidate->is_active)->toBeFalse()
        ->and($candidate->exists)->toBeTrue()
        ->and($candidate->acceptsBallots())->toBeFalse();
});

it('journalise la desactivation d\'un candidat, en notant si le vote etait ouvert', function () {
    // Retirer un candidat en cours de scrutin change ce sur quoi le public
    // vote : c'est un geste que l'organisateur doit pouvoir justifier.
    $poll = ($this->makeReadyPoll)(['startsAt' => now()->subHour()]);
    app(PublishPoll::class)->publish($poll, $this->owner);

    $candidate = $poll->refresh()->categories()->first()->candidates()->first();
    app(SaveCandidate::class)->disable($candidate, $this->owner, 'Disqualifie.');

    $log = AuditLog::query()
        ->where('action', AuditAction::CandidateDisabled->value)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['poll_open'])->toBeTrue()
        ->and($log->metadata['reason'])->toBe('Disqualifie.');
});

it('traduit un numero vide en absence de numero', function () {
    /*
     * Une chaine vide violerait l'unicite de la categorie des le deuxieme
     * candidat sans numero. Le null, lui, y echappe.
     */
    $poll = ($this->makePoll)();
    $category = app(SavePollCategory::class)->create($poll, ['name' => 'Prix'], $this->owner);

    $first = app(SaveCandidate::class)->create($category, ['name' => 'A', 'number' => '  '], $this->owner);
    $second = app(SaveCandidate::class)->create($category, ['name' => 'B', 'number' => ''], $this->owner);

    expect($first->number)->toBeNull()
        ->and($second->number)->toBeNull();
});

/*
 * ---------------------------------------------------------------------------
 * Ecrans et permissions
 * ---------------------------------------------------------------------------
 */

it('affiche la liste des votes de l\'organisation', function () {
    $poll = ($this->makePoll)();

    Livewire::test(PollIndex::class)
        ->assertOk()
        ->assertSee($poll->name);
});

it('ne montre pas les votes d\'une autre organisation', function () {
    // Section 80.4.
    $mine = ($this->makePoll)();

    [$otherOwner, $other] = organizerWithOrganization('Autre comite');

    actAsOrganization($other);
    $this->actingAs($otherOwner);

    Livewire::test(PollIndex::class)
        ->assertOk()
        ->assertDontSee($mine->name);
});

it('refuse l\'ecran de gestion a qui n\'a pas la permission', function () {
    $member = addMemberTo($this->organization, App\Enums\RoleName::CoOrganizer);

    $this->actingAs($member);

    Livewire::test(PollForm::class)->assertForbidden();
});

it('laisse le co-organisateur consulter la liste', function () {
    $member = addMemberTo($this->organization, App\Enums\RoleName::CoOrganizer);

    $this->actingAs($member);

    Livewire::test(PollIndex::class)->assertOk();
});

it('cree un vote depuis l\'ecran', function () {
    Livewire::test(PollForm::class)
        ->set('name', 'Artiste de l\'annee')
        ->set('description', 'Le prix du public.')
        ->set('startsAt', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('endsAt', now()->addMonth()->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertHasNoErrors();

    expect(Poll::query()->where('name', 'Artiste de l\'annee')->exists())->toBeTrue();
});

it('refuse une fermeture qui precede l\'ouverture', function () {
    Livewire::test(PollForm::class)
        ->set('name', 'Concours')
        ->set('startsAt', now()->addMonth()->format('Y-m-d\TH:i'))
        ->set('endsAt', now()->addDay()->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertHasErrors('endsAt');
});

it('ajoute une categorie et ses candidats depuis un seul ecran', function () {
    $poll = ($this->makePoll)();

    Livewire::test(PollCategories::class, ['poll' => $poll])
        ->call('newCategory')
        ->set('categoryName', 'Miss Libreville')
        ->set('categoryMaxVotes', 1)
        ->call('saveCategory')
        ->assertHasNoErrors();

    $category = $poll->refresh()->categories()->first();

    expect($category)->not->toBeNull();

    Livewire::test(PollCategories::class, ['poll' => $poll])
        ->call('newCandidate', $category->id)
        ->set('candidateName', 'Awa Ndong')
        ->set('candidateNumber', '1')
        ->call('saveCandidate')
        ->assertHasNoErrors();

    expect($category->refresh()->candidates()->count())->toBe(1);
});

it('exige les deux bornes quand une categorie regle sa propre fenetre', function () {
    // La base refuse une seule borne : le message est verifie avant, pour qu'il
    // soit lisible plutot qu'une exception SQL brute.
    $poll = ($this->makePoll)();

    Livewire::test(PollCategories::class, ['poll' => $poll])
        ->call('newCategory')
        ->set('categoryName', 'Prix')
        ->set('categoryStartsAt', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('categoryEndsAt', '')
        ->call('saveCategory')
        ->assertHasErrors('categoryStartsAt');
});

it('signale un numero de candidat deja pris plutot que de laisser passer une erreur SQL', function () {
    $poll = ($this->makePoll)();
    $category = app(SavePollCategory::class)->create($poll, ['name' => 'Prix'], $this->owner);

    app(SaveCandidate::class)->create($category, ['name' => 'Awa Ndong', 'number' => '7'], $this->owner);

    Livewire::test(PollCategories::class, ['poll' => $poll])
        ->call('newCandidate', $category->id)
        ->set('candidateName', 'Ines Moussavou')
        ->set('candidateNumber', '7')
        ->call('saveCandidate')
        ->assertHasErrors('candidateNumber');
});

it('empeche d\'atteindre la categorie d\'une autre organisation par son identifiant', function () {
    /*
     * Regle 80.4. `poll_categories` ne porte pas de portee — elle descend du
     * scrutin. Chercher par `PollCategory::find()` ouvrirait le trou ; la
     * recherche part donc toujours du scrutin, deja filtre.
     */
    $mine = ($this->makePoll)();

    [$otherOwner, $other] = organizerWithOrganization('Autre comite');
    actAsOrganization($other);
    $this->actingAs($otherOwner);

    $foreignPoll = Poll::factory()->create(['organization_id' => $other->id]);
    $foreignCategory = PollCategory::factory()->create(['poll_id' => $foreignPoll->id]);

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    // `findOrFail` leve : en HTTP la reponse serait un 404, et dans un test de
    // composant l'exception remonte telle quelle. Ce qui compte est qu'AUCUNE
    // donnee de l'autre organisation ne soit atteinte.
    expect(fn () => Livewire::test(PollCategories::class, ['poll' => $mine])
        ->call('editCategory', $foreignCategory->id))
        ->toThrow(ModelNotFoundException::class);
});

it('empeche d\'atteindre le candidat d\'un autre scrutin par son identifiant', function () {
    $mine = ($this->makePoll)();

    $otherPoll = ($this->makePoll)(['name' => 'Autre scrutin']);
    $otherCategory = app(SavePollCategory::class)->create($otherPoll, ['name' => 'Prix'], $this->owner);
    $foreign = app(SaveCandidate::class)->create($otherCategory, ['name' => 'X'], $this->owner);

    expect(fn () => Livewire::test(PollCategories::class, ['poll' => $mine])
        ->call('editCandidate', $foreign->id))
        ->toThrow(ModelNotFoundException::class);
});

it('n\'expose aucun champ de nombre de votes dans le formulaire', function () {
    /*
     * Un formulaire qui porterait ce champ, meme desactive, laisserait croire
     * qu'il se saisit. Il n'existe nulle part (section 86.5).
     */
    $poll = ($this->makeReadyPoll)();

    $rendered = Livewire::test(PollCategories::class, ['poll' => $poll])->html();

    foreach (['votes_count', 'total_votes', 'wire:model="votes"'] as $forbidden) {
        expect($rendered)->not->toContain($forbidden);
    }
});

it('ne cree aucune propriete de resultat sur le modele', function () {
    $poll = ($this->makeReadyPoll)();
    $candidate = $poll->categories()->first()->candidates()->first();

    expect($candidate->getAttributes())->not->toHaveKey('votes_count')
        ->and($candidate->getAttributes())->not->toHaveKey('votes');
});

it('accepte le passage d\'un vote programme a ouvert puis clos', function () {
    $poll = ($this->makeReadyPoll)(['startsAt' => now()->addWeek()]);

    $poll = app(PublishPoll::class)->publish($poll, $this->owner);
    expect($poll->status)->toBe(PollStatus::Scheduled);

    $poll = app(PublishPoll::class)->open($poll, $this->owner);
    expect($poll->status)->toBe(PollStatus::Active);

    $poll = app(PublishPoll::class)->close($poll, $this->owner);
    expect($poll->status)->toBe(PollStatus::Closed)
        ->and($poll->closed_at)->not->toBeNull();
});

it('refuse de rouvrir un vote clos', function () {
    $poll = Poll::factory()->closed()->create(['organization_id' => $this->organization->id]);

    app(PublishPoll::class)->open($poll, $this->owner);
})->throws(RuntimeException::class);

/*
 * ---------------------------------------------------------------------------
 * Le lien public
 * ---------------------------------------------------------------------------
 */

it('donne a l\'organisateur le lien public de son scrutin, des la publication', function () {
    /*
     * Il n'y a pas de vitrine sur Pass241 : un scrutin n'existe pour le public
     * que par ce lien, que l'organisateur partage lui-meme (sections 4 a 6).
     * Il manquait des deux ecrans, alors que l'evenement et la collecte
     * l'affichent tous les deux — un concours publie dont on ne peut pas
     * recuperer l'adresse n'est pas publie.
     *
     * Verifie sur un scrutin PROGRAMME, et non ouvert : la page circule
     * pendant la campagne d'annonce, c'est precisement la qu'on a besoin du
     * lien (decision D-049).
     */
    $poll = app(PublishPoll::class)->publish(
        ($this->makeReadyPoll)(['startsAt' => now()->addWeek()]),
        $this->owner,
    );

    expect($poll->status)->toBe(PollStatus::Scheduled);

    Livewire::test(PollForm::class, ['poll' => $poll])
        ->assertSee($poll->publicUrl(), escape: false);

    Livewire::test(PollIndex::class)
        ->assertSee($poll->publicUrl(), escape: false);
});

it('ne montre aucun lien public tant que le scrutin est un brouillon', function () {
    // Un brouillon n'a pas de page : afficher son adresse ferait partager un
    // lien qui repond 404.
    $poll = ($this->makeReadyPoll)();

    expect($poll->status)->toBe(PollStatus::Draft);

    Livewire::test(PollIndex::class)
        ->assertDontSee($poll->publicUrl(), escape: false);
});
