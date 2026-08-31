<?php

namespace App\Services\Voting;

use App\Enums\BallotStatus;
use App\Models\Poll;
use App\Models\PollCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Comptage des voix (sections 86.5, 87).
 *
 * SEUL POINT DE CALCUL des totaux, du classement et des revenus. Rien de tout
 * cela n'est stocke : la section 86.4 interdit qu'un organisateur puisse
 * modifier le nombre de votes, et la facon la plus sure de tenir cette regle est
 * qu'il n'existe aucune colonne a modifier.
 *
 * L'agregation se fait en SQL sur les bulletins qui COMPTENT — confirmes et
 * rembourses. Un bulletin en attente n'est pas une voix : le compter ferait
 * mentir le classement a chaque paiement abandonne, et permettrait de gonfler un
 * candidat en lancant des paiements qu'on n'honore jamais.
 *
 * Les rembourses comptent, eux : le remboursement rend l'argent, il ne retire
 * pas la voix. Le raisonnement est ecrit dans `BallotStatus::countsAsVote()`.
 *
 * CACHE COURT (decision D-031). Un classement de concours est regarde par
 * beaucoup de monde en meme temps, souvent depuis la meme page rafraichie a la
 * main. Dix secondes suffisent a absorber la charge sans que personne ne
 * remarque le retard.
 */
class VotingStatistics
{
    private const CACHE_SECONDS = 10;

    /**
     * Classement d'une categorie, du plus vote au moins vote.
     *
     * @return Collection<int, array{
     *     candidate_id: int, votes: int, ballots: int, revenue: int, rank: int, share: float
     * }>
     */
    public function standings(PollCategory $category, bool $fresh = false): Collection
    {
        $key = 'votes:standings:'.$category->getKey();

        if ($fresh) {
            Cache::forget($key);
        }

        /*
         * CE QUI TRANSITE PAR LE CACHE EST UN TABLEAU NU, jamais une
         * `Collection` ni un `stdClass`.
         *
         * Le cache de developpement est un tableau en memoire : il rend
         * l'objet tel quel, et tout se passe bien. Les caches reels — fichier,
         * base, Redis — SERIALISENT. A la relecture, PHP reconstruisait un
         * `__PHP_Incomplete_Class` et la page entiere tombait sur « The script
         * tried to call a method on an incomplete object ».
         *
         * Le defaut ne pouvait pas apparaitre en test, ou le pilote `array` ne
         * serialise rien. Il s'est vu a la premiere requete HTTP reelle, une
         * fois le cache rempli — donc PAS sur la premiere, ce qui le rendait
         * d'autant plus deroutant.
         *
         * La regle a retenir : ne mettre en cache que des types que n'importe
         * quel pilote sait porter, et reconstruire les objets au retour.
         */
        $rows = Cache::remember($key, self::CACHE_SECONDS, function () use ($category) {
            /*
             * Aucune portee par organisation n'est appliquee, et il n'y a pas de
             * fuite possible : la requete est bornee a UNE categorie, dont
             * l'identifiant vient du serveur. La page publique est lue par des
             * visiteurs qui ne sont membres d'aucune organisation — meme
             * raisonnement que `CampaignProgress`.
             */
            return DB::table('ballots')
                ->select([
                    'candidate_id',
                    // `quantity` et non `count(*)` : un bulletin peut porter
                    // plusieurs voix quand la categorie les vend par lot.
                    DB::raw('COALESCE(SUM(quantity), 0) AS votes'),
                    DB::raw('COUNT(*) AS ballots'),
                    // Le revenu ne retient QUE les confirmes : un bulletin
                    // rembourse garde sa voix mais rend son argent.
                    DB::raw(sprintf(
                        "COALESCE(SUM(CASE WHEN status = '%s' THEN amount ELSE 0 END), 0) AS revenue",
                        BallotStatus::Confirmed->value,
                    )),
                ])
                ->where('poll_category_id', $category->getKey())
                ->whereIn('status', [
                    BallotStatus::Confirmed->value,
                    BallotStatus::Refunded->value,
                ])
                ->groupBy('candidate_id')
                ->get()
                // Chaque ligne devient un tableau associatif de scalaires, et
                // l'ensemble un tableau PHP : rien qui doive etre reconstruit
                // par un autoloader a la deserialisation.
                ->map(fn (object $row): array => [
                    'candidate_id' => (int) $row->candidate_id,
                    'votes' => (int) $row->votes,
                    'ballots' => (int) $row->ballots,
                    'revenue' => (int) $row->revenue,
                ])
                ->all();
        });

        $byCandidateRow = array_column($rows, null, 'candidate_id');
        $total = array_sum(array_column($rows, 'votes'));

        /*
         * Le classement est calcule ICI et non par la base.
         *
         * Un `RANK()` SQL ferait le travail, mais les candidats SANS AUCUNE VOIX
         * n'apparaissent pas dans le groupement — ils doivent pourtant figurer
         * au classement, a zero. Les completer en PHP evite une jointure
         * externe sur `candidates` dans une requete deja mise en cache.
         */
        $candidates = $category->relationLoaded('candidates')
            ? $category->candidates
            : $category->candidates()->get();

        return $candidates
            ->map(function ($candidate) use ($byCandidateRow, $total) {
                $row = $byCandidateRow[$candidate->getKey()] ?? null;

                $votes = (int) ($row['votes'] ?? 0);

                return [
                    'candidate_id' => $candidate->getKey(),
                    'votes' => $votes,
                    'ballots' => (int) ($row['ballots'] ?? 0),
                    'revenue' => (int) ($row['revenue'] ?? 0),
                    'rank' => 0,
                    // Le pourcentage est de la PART DES VOIX, pas une
                    // progression vers un objectif : un concours n'en a pas.
                    'share' => $total > 0 ? round($votes / $total * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('votes')
            ->values()
            ->map(function (array $row, int $index) {
                $row['rank'] = $index + 1;

                return $row;
            });
    }

    /**
     * Totaux d'une categorie.
     *
     * @return array{votes: int, ballots: int, revenue: int, voters: int}
     */
    public function forCategory(PollCategory $category): array
    {
        return Cache::remember(
            'votes:category:'.$category->getKey(),
            self::CACHE_SECONDS,
            fn () => array_map('intval', (array) DB::table('ballots')
                ->selectRaw('COALESCE(SUM(quantity), 0) AS votes')
                ->selectRaw('COUNT(*) AS ballots')
                ->selectRaw(sprintf(
                    "COALESCE(SUM(CASE WHEN status = '%s' THEN amount ELSE 0 END), 0) AS revenue",
                    BallotStatus::Confirmed->value,
                ))
                // Le nombre de VOTANTS distincts, qui n'est pas le nombre de
                // voix : dans une categorie qui vend les votes par lot, une
                // seule personne peut en porter cinquante.
                ->selectRaw('COUNT(DISTINCT voter_hash) AS voters')
                ->where('poll_category_id', $category->getKey())
                ->whereIn('status', [
                    BallotStatus::Confirmed->value,
                    BallotStatus::Refunded->value,
                ])
                ->first()),
        );
    }

    /**
     * Totaux d'un scrutin entier.
     *
     * @return array{votes: int, ballots: int, revenue: int, voters: int}
     */
    public function forPoll(Poll $poll): array
    {
        return Cache::remember(
            'votes:poll:'.$poll->getKey(),
            self::CACHE_SECONDS,
            fn () => array_map('intval', (array) DB::table('ballots')
                ->selectRaw('COALESCE(SUM(quantity), 0) AS votes')
                ->selectRaw('COUNT(*) AS ballots')
                ->selectRaw(sprintf(
                    "COALESCE(SUM(CASE WHEN status = '%s' THEN amount ELSE 0 END), 0) AS revenue",
                    BallotStatus::Confirmed->value,
                ))
                ->selectRaw('COUNT(DISTINCT voter_hash) AS voters')
                ->where('poll_id', $poll->getKey())
                ->whereIn('status', [
                    BallotStatus::Confirmed->value,
                    BallotStatus::Refunded->value,
                ])
                ->first()),
        );
    }

    /**
     * Oublie les totaux mis en cache pour une categorie.
     *
     * Appelee apres un vote : sans cela, le votant verrait son propre bulletin
     * absent du classement pendant dix secondes, et croirait qu'il n'a pas ete
     * pris en compte.
     */
    public function forget(PollCategory $category): void
    {
        Cache::forget('votes:standings:'.$category->getKey());
        Cache::forget('votes:category:'.$category->getKey());
        Cache::forget('votes:poll:'.$category->poll_id);
    }
}
