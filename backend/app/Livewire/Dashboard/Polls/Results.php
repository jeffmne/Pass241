<?php

namespace App\Livewire\Dashboard\Polls;

use App\Enums\PermissionName;
use App\Livewire\Concerns\RequiresPermission;
use App\Models\Poll;
use App\Models\PollResult;
use App\Services\Voting\PollResultsFreezer;
use App\Services\Voting\VotingStatistics;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Resultats d'un scrutin, vus par l'organisateur (sections 86.4, 87).
 *
 * DEUX ETATS, ET L'ECRAN NE LES CONFOND JAMAIS.
 *
 * Tant que le scrutin n'est pas clos, le classement est CALCULE : il bouge a
 * chaque bulletin, et l'ecran le dit. Une fois clos, il est LU dans
 * `poll_results`, ou il a ete fige a la cloture — c'est celui qui a ete
 * proclame, et il ne bouge plus.
 *
 * La distinction n'est pas cosmetique. Un organisateur qui capture son ecran
 * pendant le vote pour annoncer un gagnant se trompera ; l'ecran doit donc dire
 * lequel des deux il montre, sans que ce soit a deviner.
 *
 * LES REVENUS NE S'AFFICHENT QU'AVEC `finances.view`. Un co-organisateur voit
 * le classement — c'est le sujet du concours — mais pas ce qu'il a rapporte
 * (section 8, decision sur les permissions financieres).
 */
#[Layout('components.layouts.app')]
class Results extends Component
{
    use RequiresPermission;

    public Poll $poll;

    protected function requiredPermission(): PermissionName
    {
        return PermissionName::VotesView;
    }

    public function mount(Poll $poll): void
    {
        /*
         * `votes.view` et non `votes.manage` : consulter un resultat n'est pas
         * gerer un concours. Le co-organisateur, qui suit l'evenement sans
         * pouvoir le modifier, doit pouvoir lire le classement — c'est meme
         * souvent lui qui l'annonce.
         *
         * L'isolation par organisation est portee par la resolution du modele
         * dans le groupe de routes, comme partout ailleurs : un scrutin d'une
         * autre organisation n'arrive jamais jusqu'ici.
         */

        $this->poll = $poll;
    }

    public function render(VotingStatistics $statistics, PollResultsFreezer $freezer)
    {
        $categories = $this->poll->categories()
            ->with('candidates')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        /*
         * Le classement fige, s'il existe, est indexe par categorie. Une seule
         * requete pour tout le scrutin : un concours a rarement plus de cinq
         * categories, mais une requete par categorie serait gratuite a eviter.
         */
        $frozen = PollResult::query()
            ->where('poll_id', $this->poll->getKey())
            ->orderBy('rank')
            ->get()
            ->groupBy('poll_category_id');

        return view('livewire.dashboard.polls.results', [
            'categories' => $categories,

            /*
             * Pour chaque categorie : le classement fige s'il existe, le calcul
             * sinon. Les deux ont la meme forme — rang, voix, part, revenu —
             * pour que la vue n'ait pas a savoir d'ou ils viennent.
             */
            'standings' => $categories->mapWithKeys(function ($category) use ($statistics, $frozen) {
                $rows = $frozen->get($category->getKey());

                if ($rows !== null && $rows->isNotEmpty()) {
                    return [$category->getKey() => $rows->map(fn (PollResult $r) => [
                        'rank' => $r->rank,
                        'name' => $r->candidate_name,
                        'number' => $r->candidate_number,
                        'votes' => $r->votes,
                        'ballots' => $r->ballots,
                        'revenue' => $r->revenue,
                        'share' => $r->sharePercentage(),
                    ])];
                }

                $candidates = $category->candidates->keyBy('id');

                return [$category->getKey() => $statistics->standings($category)
                    ->map(fn (array $row) => [
                        'rank' => $row['rank'],
                        'name' => $candidates->get($row['candidate_id'])?->name ?? 'Candidat retire',
                        'number' => $candidates->get($row['candidate_id'])?->number,
                        'votes' => $row['votes'],
                        'ballots' => $row['ballots'],
                        'revenue' => $row['revenue'],
                        'share' => $row['share'],
                    ])];
            }),

            // Categorie par categorie : une peut etre figee et l'autre non si
            // la tache a echoue entre les deux.
            'isFrozen' => $categories->mapWithKeys(fn ($category) => [
                $category->getKey() => ($frozen->get($category->getKey())?->isNotEmpty() ?? false),
            ]),

            'totals' => $statistics->forPoll($this->poll),

            'frozenAt' => $frozen->flatten()->first()?->frozen_at,

            /*
             * Le droit de voir les revenus est distinct de celui de voir le
             * classement : un co-organisateur gere le concours sans acceder aux
             * finances.
             */
            'canSeeRevenue' => auth()->user()?->can(PermissionName::FinancesView->value) ?? false,

            'canExport' => auth()->user()?->can(PermissionName::ExportsGenerate->value) ?? false,
        ]);
    }
}
