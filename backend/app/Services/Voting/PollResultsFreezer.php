<?php

namespace App\Services\Voting;

use App\Models\Poll;
use App\Models\PollCategory;
use App\Models\PollResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fige le classement d'un scrutin a sa cloture (sections 86.4, 86.5).
 *
 * POURQUOI FIGER, ALORS QUE TOUT EST CALCULE AILLEURS.
 *
 * Pendant le scrutin, le classement est agrege en SQL sur les bulletins : rien
 * n'est stocke, donc rien n'est falsifiable. Cette propriete est le fondement
 * du module, et elle n'est pas remise en cause ici.
 *
 * Mais un classement PROCLAME doit cesser de bouger. Un remboursement arrive
 * apres la cloture retire de l'argent, et un recalcul ferait alors varier un
 * revenu deja annonce. Pire : un bulletin qui changerait de statut pourrait
 * deplacer un rang deja proclame. Un concours dont le gagnant change trois
 * jours apres l'annonce n'est plus un concours.
 *
 * L'instantane ne remplace donc pas le calcul, il le CONSERVE. Avant la
 * cloture, le classement se calcule ; apres, il se lit.
 *
 * REJOUABLE SANS DANGER. L'unicite en base sur le couple categorie-candidat
 * fait qu'une seconde execution n'ecrit rien — la tache planifiee peut donc
 * repasser sur un scrutin deja clos a la main, et deux executions simultanees
 * ne produisent pas deux classements concurrents.
 */
class PollResultsFreezer
{
    public function __construct(private readonly VotingStatistics $statistics) {}

    /**
     * Fige toutes les categories d'un scrutin.
     *
     * @return int nombre de lignes de resultat ecrites
     */
    public function freeze(Poll $poll): int
    {
        $written = 0;

        foreach ($poll->categories()->with('candidates')->get() as $category) {
            $written += $this->freezeCategory($poll, $category);
        }

        return $written;
    }

    /**
     * Fige une categorie.
     *
     * LES CANDIDATS DESACTIVES Y FIGURENT. Leurs voix ont ete exprimees et
     * comptent (section 86.4) : les retirer du classement final ferait mentir
     * les totaux, et donnerait a l'organisateur le moyen d'effacer un candidat
     * genant apres coup — exactement ce que le module interdit.
     */
    public function freezeCategory(Poll $poll, PollCategory $category): int
    {
        // Deja fige : on ne touche a rien. La table etant en ajout seul, une
        // seconde tentative echouerait de toute facon sur l'unicite — autant
        // sortir proprement.
        if ($this->isFrozen($category)) {
            return 0;
        }

        /*
         * Classement relu au plus frais, sans passer par le cache.
         *
         * Le cache de dix secondes est fait pour absorber la charge d'une page
         * publique ; figer un resultat definitif sur une valeur vieille de
         * quelques secondes serait absurde, et l'ecart pourrait porter sur un
         * bulletin confirme entre-temps.
         */
        $standings = $this->statistics->standings($category, fresh: true);

        if ($standings->isEmpty()) {
            return 0;
        }

        $candidates = $category->relationLoaded('candidates')
            ? $category->candidates
            : $category->candidates()->get();

        $frozenAt = now();

        $rows = $standings->map(function (array $row) use ($poll, $category, $candidates, $frozenAt): array {
            $candidate = $candidates->firstWhere('id', $row['candidate_id']);

            return [
                'organization_id' => $poll->organization_id,
                'poll_id' => $poll->getKey(),
                'poll_category_id' => $category->getKey(),
                'candidate_id' => $row['candidate_id'],
                // Recopie : un rapport relu dans deux ans doit porter le nom
                // sous lequel le public a vote, meme si l'organisateur le
                // corrige ensuite.
                'candidate_name' => (string) ($candidate->name ?? 'Candidat retire'),
                'candidate_number' => $candidate->number ?? null,
                'rank' => $row['rank'],
                'votes' => $row['votes'],
                'ballots' => $row['ballots'],
                'revenue' => $row['revenue'],
                // La part est convertie en centiemes de point pour rester
                // entiere en base : 42,35 % devient 4235.
                'share_basis_points' => (int) round($row['share'] * 100),
                'frozen_at' => $frozenAt,
            ];
        })->all();

        /*
         * `insertOrIgnore` plutot qu'un `insert` : deux executions simultanees
         * — la tache planifiee et une cloture manuelle — tenteraient toutes
         * deux l'ecriture, et la seconde violerait l'unicite. Ici elle est
         * simplement ignoree, ce qui est le comportement voulu : le premier
         * classement fige est le bon.
         */
        return DB::table('poll_results')->insertOrIgnore($rows);
    }

    /** Cette categorie a-t-elle deja son classement fige ? */
    public function isFrozen(PollCategory $category): bool
    {
        return PollResult::query()
            ->acrossOrganizations()
            ->where('poll_category_id', $category->getKey())
            ->exists();
    }

    /**
     * Classement fige d'un scrutin, par categorie.
     *
     * @return Collection<int, Collection<int, PollResult>>
     */
    public function results(Poll $poll)
    {
        return PollResult::query()
            ->acrossOrganizations()
            ->where('poll_id', $poll->getKey())
            ->orderBy('poll_category_id')
            ->orderBy('rank')
            ->get()
            ->groupBy('poll_category_id');
    }
}
