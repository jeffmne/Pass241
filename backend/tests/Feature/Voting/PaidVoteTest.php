<?php

use App\Actions\Payments\ConfirmPayment;
use App\Actions\Voting\CreatePaidBallot;
use App\Enums\AuditAction;
use App\Enums\BallotStatus;
use App\Enums\CommissionType;
use App\Enums\PaymentProvider;
use App\Jobs\ExpireBallotIfUnpaid;
use App\Models\AuditLog;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Commission;
use App\Models\Poll;
use App\Models\PollCategory;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PayableResolver;
use App\Services\Voting\VoteQuotaLedger;
use App\Services\Voting\VotingStatistics;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * Vote payant — jalon 9.4 (sections 86.4, 86.5).
 *
 * LA REGLE QUE CE FICHIER DEFEND TIENT EN UNE PHRASE : « le vote n'est VALIDE
 * qu'apres confirmation reelle du paiement par le PSP » (section 86.4). Tout le
 * reste en decoule — un bulletin en attente ne compte pas, un bulletin echoue
 * rend sa voix, et rien de tout cela ne se decide dans le navigateur.
 *
 * Le chemin de l'argent lui-meme n'est pas retesté ici : il est commun aux trois
 * modules depuis la decision D-041, et la billetterie comme les collectes le
 * couvrent deja. Ce qui est verifie, c'est que le bulletin s'y branche
 * correctement — et ce qui lui est PROPRE : le quota.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Comite Miss Estuaire');

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $this->poll = Poll::factory()->active()->create();

    $this->category = PollCategory::factory()
        ->for($this->poll)
        ->paid(500)
        ->create(['name' => 'Prix du public']);

    $this->candidate = Candidate::factory()
        ->for($this->category, 'category')
        ->create(['name' => 'Awa Ndong']);

    $this->rival = Candidate::factory()
        ->for($this->category, 'category')
        ->create(['name' => 'Sylvie Mba']);

    $this->buy = fn (int $quantity = 1, string $phone = '077123456', ?Candidate $on = null) => forOrganization(
        $this->organization,
        fn () => app(CreatePaidBallot::class)->handle(
            $on ?? $this->candidate,
            $quantity,
            PaymentProvider::Airtel,
            $phone,
            Request::create('/vote', 'POST', server: [
                'REMOTE_ADDR' => '41.158.0.10',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 12)',
            ]),
        ),
    );

    $this->confirm = fn (Ballot $ballot) => app(ConfirmPayment::class)
        ->handle($ballot, 'FAKE-REF-1');

    $this->standings = fn () => app(VotingStatistics::class)
        ->standings($this->category->fresh(), fresh: true);
});

/*
 * ---------------------------------------------------------------------------
 * Un bulletin paye nait EN ATTENTE
 * ---------------------------------------------------------------------------
 */

it('cree un bulletin en attente, jamais confirme d\'emblee', function () {
    $ballot = ($this->buy)(2);

    expect($ballot->status)->toBe(BallotStatus::Pending)
        ->and($ballot->confirmed_at)->toBeNull()
        ->and($ballot->expires_at)->not->toBeNull()
        ->and($ballot->reference)->toStartWith('VT-');
});

it('ne compte pas une voix tant que le paiement n\'est pas confirme', function () {
    /*
     * C'est ce qui empeche de gonfler un candidat en lancant des paiements
     * qu'on n'honore jamais. Le bulletin existe en base ; il ne pese rien.
     */
    ($this->buy)(5);

    $row = ($this->standings)()->firstWhere('candidate_id', $this->candidate->id);

    expect($row['votes'])->toBe(0)
        ->and($row['revenue'])->toBe(0)
        ->and(Ballot::query()->acrossOrganizations()->count())->toBe(1);
});

it('compte la voix, et elle seule, une fois le paiement confirme', function () {
    $ballot = ($this->buy)(3);

    ($this->confirm)($ballot);

    $row = ($this->standings)()->firstWhere('candidate_id', $this->candidate->id);

    expect($row['votes'])->toBe(3)
        // Le revenu est le SOUS-TOTAL : les frais de l'operateur ne reviennent
        // pas a l'organisateur (decision D-023).
        ->and($row['revenue'])->toBe(1_500)
        ->and($ballot->fresh()->status)->toBe(BallotStatus::Confirmed)
        ->and($ballot->fresh()->expires_at)->toBeNull();
});

/*
 * ---------------------------------------------------------------------------
 * Les montants viennent du serveur
 * ---------------------------------------------------------------------------
 */

it('calcule le montant depuis le prix en base, jamais depuis le navigateur', function () {
    /*
     * Section 86.5, point 4. L'action ne recoit que le candidat et une
     * quantite : le prix unitaire est relu sur la categorie, et les trois
     * montants en decoulent.
     */
    $ballot = ($this->buy)(4);

    expect($ballot->unit_price)->toBe(500)
        ->and($ballot->subtotal)->toBe(2_000)
        ->and($ballot->psp_fee_amount)->toBeGreaterThanOrEqual(0)
        // La contrainte en base verifie elle-meme cette egalite.
        ->and($ballot->amount)->toBe($ballot->subtotal + $ballot->psp_fee_amount);
});

it('fige le bareme de frais sur le bulletin', function () {
    // Sans cet instantane, une transaction devient inexplicable apres un
    // changement de prestataire : on saurait ce qu'on a preleve, sans plus
    // pouvoir dire pourquoi.
    $ballot = ($this->buy)();

    expect($ballot->psp)->not->toBeNull();
});

it('refuse un vote payant sur une categorie gratuite', function () {
    $free = PollCategory::factory()->for($this->poll)->create();
    $candidate = Candidate::factory()->for($free, 'category')->create();

    forOrganization($this->organization, fn () => app(CreatePaidBallot::class)->handle(
        $candidate, 1, PaymentProvider::Airtel, '077123456', Request::create('/vote'),
    ));
})->throws(RuntimeException::class, 'gratuite');

/*
 * ---------------------------------------------------------------------------
 * La commission (decision D-047 : 5 %, comme la billetterie)
 * ---------------------------------------------------------------------------
 */

it('preleve 5 % sur les voix, et rien sur les frais de l\'operateur', function () {
    $ballot = ($this->buy)(10); // 5 000 F de voix

    ($this->confirm)($ballot);

    $commission = Commission::query()
        ->acrossOrganizations()
        ->where('commissionable_id', $ballot->getKey())
        ->where('commissionable_type', $ballot->getMorphClass())
        ->first();

    expect($commission)->not->toBeNull()
        ->and($commission->type)->toBe(CommissionType::Voting)
        // L'assiette est le sous-total, jamais le montant debite : preleve sur
        // le total, la plateforme prendrait sa part de l'argent du prestataire.
        ->and((int) $commission->gross_amount)->toBe(5_000)
        ->and((int) $commission->commission_amount)->toBe(250)
        // Le net revenant a l'organisateur : les voix moins la commission.
        ->and((int) $commission->net_amount)->toBe(4_750);
});

it('ne preleve aucune commission tant que le paiement n\'est pas confirme', function () {
    ($this->buy)(10);

    expect(Commission::query()->acrossOrganizations()->count())->toBe(0);
});

/*
 * ---------------------------------------------------------------------------
 * Le branchement sur le chemin de l'argent (decision D-041)
 * ---------------------------------------------------------------------------
 */

it('retrouve un bulletin depuis sa reference, comme une commande ou un don', function () {
    /*
     * Un operateur ne renvoie qu'une chaine. Sans cette resolution, un webhook
     * de vote paye n'aurait rien a quoi se rattacher — et l'argent serait
     * encaisse sans qu'aucune voix ne soit comptee.
     */
    $ballot = ($this->buy)();

    $found = app(PayableResolver::class)->byReference($ballot->reference);

    expect($found)->toBeInstanceOf(Ballot::class)
        ->and($found->getKey())->toBe($ballot->getKey());
});

it('reste idempotent : deux confirmations ne font qu\'une voix', function () {
    // Section 23. Un webhook rejoue ne doit rien produire de plus.
    $ballot = ($this->buy)(2);

    ($this->confirm)($ballot);
    ($this->confirm)($ballot->fresh());

    expect(Commission::query()->acrossOrganizations()->count())->toBe(1)
        ->and(($this->standings)()->firstWhere('candidate_id', $this->candidate->id)['votes'])
        ->toBe(2);
});

it('journalise l\'engagement puis la confirmation', function () {
    $ballot = ($this->buy)();

    ($this->confirm)($ballot);

    $actions = AuditLog::query()->pluck('action');

    expect($actions)->toContain(AuditAction::BallotCreated)
        ->and($actions)->toContain(AuditAction::BallotConfirmed);
});

/*
 * ---------------------------------------------------------------------------
 * Le quota, qui est ce que le vote a de propre
 * ---------------------------------------------------------------------------
 */

it('reserve la voix des la creation, avant tout paiement', function () {
    /*
     * Sans cette reservation, deux personnes pourraient payer pour la meme
     * derniere voix d'une categorie plafonnee, et l'une des deux serait
     * remboursee apres avoir cru avoir vote.
     */
    $this->category->forceFill(['max_votes_per_voter' => 2])->save();

    ($this->buy)(2, '077123456');

    expect(fn () => ($this->buy)(1, '077123456'))->toThrow(RuntimeException::class);
});

it('rend la voix quand l\'operateur refuse', function () {
    $this->category->forceFill(['max_votes_per_voter' => 1])->save();

    $ballot = ($this->buy)(1, '077123456');

    expect(app(VoteQuotaLedger::class)->remaining($this->category->fresh(), $ballot->voter_hash))
        ->toBe(0);

    app(ConfirmPayment::class)->markFailed($ballot, 'Solde insuffisant.');

    expect($ballot->fresh()->status)->toBe(BallotStatus::Failed)
        // La voix est rendue : sans cela, le votant resterait bloque sur son
        // plafond par un vote qu'il n'a jamais paye.
        ->and(app(VoteQuotaLedger::class)->remaining($this->category->fresh(), $ballot->voter_hash))
        ->toBe(1);

    // Et il peut recommencer.
    expect(($this->buy)(1, '077123456')->status)->toBe(BallotStatus::Pending);
});

it('rend la voix quand le paiement expire sans reponse', function () {
    // Le filet de securite : aucun callback n'arrive jamais, ce qui est le cas
    // courant chez certains operateurs.
    $this->category->forceFill(['max_votes_per_voter' => 1])->save();

    $ballot = ($this->buy)(1, '077123456');

    $this->travelTo(now()->addHour());

    (new ExpireBallotIfUnpaid($ballot->getKey()))->handle(app(AuditLogger::class));

    expect($ballot->fresh()->status)->toBe(BallotStatus::Failed)
        ->and(app(VoteQuotaLedger::class)->remaining($this->category->fresh(), $ballot->voter_hash))
        ->toBe(1);
});

it('ne fait pas expirer un bulletin deja confirme', function () {
    // Le job peut s'executer apres une confirmation arrivee entre-temps :
    // faire echouer la ligne ferait perdre une voix deja payee.
    $ballot = ($this->buy)();

    ($this->confirm)($ballot);

    $this->travelTo(now()->addHour());

    (new ExpireBallotIfUnpaid($ballot->getKey()))->handle(app(AuditLogger::class));

    expect($ballot->fresh()->status)->toBe(BallotStatus::Confirmed);
});

it('refuse de confirmer un bulletin deja echoue', function () {
    /*
     * Sa voix a ete rendue au quota, et quelqu'un d'autre a pu la prendre.
     * Confirmer par-dessus ferait deux voix la ou la categorie n'en autorisait
     * qu'une.
     */
    $ballot = ($this->buy)();

    app(ConfirmPayment::class)->markFailed($ballot, 'Solde insuffisant.');

    ($this->confirm)($ballot->fresh());
})->throws(RuntimeException::class);

/*
 * ---------------------------------------------------------------------------
 * Immuabilite, sur un bulletin qui porte de l'argent
 * ---------------------------------------------------------------------------
 */

it('refuse de reecrire le montant d\'un bulletin paye', function () {
    // Le declencheur couvre desormais `subtotal` et `psp_fee_amount` : sans
    // cela, on pourrait faire dire a un bulletin paye 500 francs qu'il en
    // valait 50 000, et deplacer l'assiette de la commission avec.
    $ballot = ($this->buy)(2);

    ($this->confirm)($ballot);

    expect(fn () => DB::transaction(
        fn () => DB::table('ballots')
            ->where('id', $ballot->getKey())
            ->update(['subtotal' => 50_000])
    ))->toThrow(QueryException::class);

    expect($ballot->fresh()->subtotal)->toBe(1_000);
});
