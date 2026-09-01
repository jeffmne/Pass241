<?php

namespace App\Services\Export\Exports;

use App\Enums\PermissionName;
use App\Models\Poll;
use App\Models\PollCategory;
use App\Models\PollResult;
use App\Services\Export\Exportable;
use App\Services\Voting\VotingStatistics;
use Generator;

/**
 * Rapport final d'un scrutin (sections 86.4, 59).
 *
 * « Un rapport final est prevu — total des votes, classement par categorie,
 * gagnants, revenus, periode » (section 86.4).
 *
 * DEUX SOURCES, ET LA PREFERENCE VA TOUJOURS AU FIGE. Un scrutin clos a son
 * classement archive dans `poll_results` : c'est celui-la qui part dans le
 * fichier, parce que c'est celui qui a ete proclame. Le recalculer donnerait un
 * resultat qui pourrait avoir bouge — un remboursement arrive depuis retire de
 * l'argent — et un rapport qui contredit l'annonce publique est pire que pas de
 * rapport du tout.
 *
 * Un scrutin encore ouvert n'a rien de fige : le fichier porte alors le
 * classement CALCULE, et le dit — la colonne « Etat » distingue « definitif »
 * de « en cours ». Sans cette mention, un fichier telecharge pendant le vote
 * circulerait comme s'il etait le resultat.
 *
 * LES REVENUS SONT DES SOUS-TOTAUX, jamais les totaux debites : les frais de
 * l'operateur ne reviennent pas a l'organisateur (decision D-023). Meme
 * definition que partout ailleurs dans le module.
 */
class PollResultsExport implements Exportable
{
    public function __construct(
        private readonly Poll $poll,
        private readonly VotingStatistics $statistics,
    ) {}

    public function label(): string
    {
        return 'Resultats du vote';
    }

    public function filename(): string
    {
        return 'resultats-'.$this->poll->slug.'-'.now()->format('Y-m-d');
    }

    public function permission(): PermissionName
    {
        return PermissionName::VotesView;
    }

    public function headings(): array
    {
        return [
            'Categorie',
            'Rang',
            'Numero',
            'Candidat',
            'Votes',
            'Bulletins',
            'Part des voix',
            'Revenu',
            'Etat',
            'Fige le',
        ];
    }

    public function rows(): Generator
    {
        $tz = config('app.display_timezone');

        $categories = $this->poll->categories()->with('candidates')->orderBy('position')->get();

        foreach ($categories as $category) {
            $frozen = PollResult::query()
                ->acrossOrganizations()
                ->where('poll_category_id', $category->getKey())
                ->orderBy('rank')
                ->get();

            if ($frozen->isNotEmpty()) {
                foreach ($frozen as $result) {
                    yield [
                        $category->name,
                        $result->rank,
                        $result->candidate_number,
                        $result->candidate_name,
                        $result->votes,
                        $result->ballots,
                        // Deux decimales : une part de voix a un dixieme de
                        // point pres ne veut rien dire sur un concours a
                        // quelques centaines de votants.
                        number_format($result->sharePercentage(), 2, ',', ' ').' %',
                        $result->revenue,
                        'Definitif',
                        $result->frozen_at?->timezone($tz)->format('Y-m-d H:i'),
                    ];
                }

                continue;
            }

            yield from $this->liveRows($category);
        }
    }

    /**
     * Classement calcule, pour une categorie pas encore figee.
     *
     * @return Generator<int, array<int, mixed>>
     */
    private function liveRows(PollCategory $category): Generator
    {
        $candidates = $category->candidates->keyBy('id');

        foreach ($this->statistics->standings($category, fresh: true) as $row) {
            $candidate = $candidates->get($row['candidate_id']);

            yield [
                $category->name,
                $row['rank'],
                $candidate?->number,
                $candidate?->name ?? 'Candidat retire',
                $row['votes'],
                $row['ballots'],
                number_format($row['share'], 2, ',', ' ').' %',
                $row['revenue'],
                // Dit en toutes lettres : ce fichier n'est pas le resultat.
                'En cours',
                null,
            ];
        }
    }

    public function auditContext(): array
    {
        return [
            'poll' => $this->poll->slug,
            'poll_id' => $this->poll->getKey(),
            'status' => $this->poll->status->value,
        ];
    }
}
