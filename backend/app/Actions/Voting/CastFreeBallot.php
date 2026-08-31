<?php

namespace App\Actions\Voting;

use App\Enums\BallotStatus;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Services\Voting\VoteQuotaLedger;
use App\Services\Voting\VoterIdentity;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Enregistre un vote gratuit (sections 86.4, 86.5).
 *
 * UN VOTE GRATUIT NAIT CONFIRME : il n'y a rien a attendre, aucun prestataire a
 * consulter. C'est toute la difference avec un vote payant, qui nait en attente
 * et ne compte qu'a la confirmation du paiement (jalon 9.4).
 *
 * L'ORDRE DES OPERATIONS EST LE SUJET DE CETTE CLASSE :
 *
 * 1. verifier que le vote est ouvert — sur les DATES, pas sur l'etiquette ;
 * 2. reserver le quota, par un UPDATE atomique ;
 * 3. seulement ensuite, ecrire le bulletin.
 *
 * Reserver AVANT d'ecrire n'est pas un detail. Ecrire d'abord et compter
 * ensuite laisserait passer deux soumissions simultanees : les deux
 * s'inscriraient, puis les deux constateraient le depassement — trop tard, les
 * voix seraient en base. Le quota est le point de serialisation, il doit venir
 * en premier.
 */
class CastFreeBallot
{
    public function __construct(
        private readonly VoterIdentity $identity,
        private readonly VoteQuotaLedger $quotas,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(Candidate $candidate, int $quantity, ?string $phone, Request $request): Ballot
    {
        /*
         * `resolvedCategory` et non `$candidate->category` : l'action est
         * appelee depuis des chemins qui n'ont pas forcement pose la relation —
         * la page publique la pose, le seeder et une future commande, non. Une
         * action ne doit pas dependre de ce que l'appelant a pense a
         * pre-charger, surtout quand l'oubli ne se voit qu'en developpement.
         */
        $category = $candidate->resolvedCategory();

        if (! $category) {
            throw new RuntimeException('Cette categorie n\'existe plus.');
        }

        /*
         * `resolvedPoll` et non `$category->poll` : le scrutin porte une portee
         * par organisation, et le votant n'est membre d'aucune. La relation
         * chargee en differe revenait donc `null` sur le seul chemin qui compte
         * — celui d'un vrai visiteur — et l'action echouait juste apres, en
         * lisant l'organisation d'un scrutin inexistant.
         */
        $poll = $category->resolvedPoll();

        if (! $poll) {
            throw new RuntimeException('Ce vote n\'existe plus.');
        }

        if ($category->is_paid) {
            throw new RuntimeException(
                'Cette categorie est payante : le vote passe par le paiement.'
            );
        }

        /*
         * La fenetre est verifiee A CHAQUE BULLETIN, jamais seulement par la
         * tache planifiee (section 86.5, point 8). Entre le moment ou la date de
         * fin passe et celui ou la tache s'execute, la colonne `status` dit
         * encore « ACTIVE » — un vote arrive dans cet intervalle serait accepte
         * hors delai, et fausserait un classement deja annonce comme fige.
         */
        if (! $candidate->acceptsBallots()) {
            throw new RuntimeException(
                $category->closureReason() ?? 'Ce candidat n\'est plus ouvert au vote.'
            );
        }

        $quantity = max(1, $quantity);

        $limit = $category->voteLimit();

        if ($limit !== null && $quantity > $limit) {
            throw new RuntimeException(sprintf(
                'Cette categorie autorise %d vote%s par personne.',
                $limit,
                $limit > 1 ? 's' : '',
            ));
        }

        $voterHash = $this->identity->hash($category->getKey(), $phone, $request);

        // Point de serialisation. Deux soumissions simultanees se departagent
        // ici, avant que quoi que ce soit ne soit ecrit.
        if (! $this->quotas->reserve($category, $voterHash, $quantity)) {
            throw new RuntimeException(
                'Vous avez deja utilise tous vos votes dans cette categorie.'
            );
        }

        try {
            return DB::transaction(function () use ($candidate, $category, $poll, $quantity, $voterHash, $request) {
                /*
                 * La portee par organisation est suspendue : le votant n'est
                 * membre d'aucune organisation, et c'est le cas d'usage prevu
                 * par la levee explicite (section 9). L'organisation est celle
                 * du scrutin, lue en base — jamais recue du navigateur.
                 */
                return $this->context->runFor($poll->organization, function () use ($candidate, $category, $poll, $quantity, $voterHash, $request) {
                    $ballot = new Ballot;

                    $ballot->forceFill([
                        'organization_id' => $poll->organization_id,
                        'poll_id' => $poll->getKey(),
                        'poll_category_id' => $category->getKey(),
                        'candidate_id' => $candidate->getKey(),
                        'reference' => $this->uniqueReference(),
                        'quantity' => $quantity,
                        // Gratuit : le montant est nul, et la contrainte
                        // `amount = quantity * unit_price` le verifie.
                        'unit_price' => 0,
                        'amount' => 0,
                        'status' => BallotStatus::Confirmed,
                        'confirmed_at' => now(),
                        'voter_hash' => $voterHash,
                        'ip_address' => $request->ip(),
                        'user_agent_hash' => $this->identity->userAgentHash($request),
                    ])->save();

                    return $ballot->refresh();
                });
            });
        } catch (\Throwable $e) {
            /*
             * L'ecriture a echoue apres la reservation : les voix doivent etre
             * rendues, sinon le votant reste bloque sur son plafond sans avoir
             * vote. Le cas est rare — il suppose une panne entre les deux — mais
             * il laisserait une trace impossible a diagnostiquer.
             */
            $this->quotas->release($category, $voterHash, $quantity);

            throw $e;
        }
    }

    /**
     * Reference lisible, du type VT-4KX9-27B.
     *
     * Meme forme que les commandes et les dons : elle est citee au support, et
     * portee par les webhooks quand le vote est payant. Les chiffres et lettres
     * ambigus sont exclus — un votant qui lit sa reference au telephone ne doit
     * pas avoir a distinguer un zero d'un O.
     */
    private function uniqueReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $block = fn (int $length) => collect(range(1, $length))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');

            $reference = 'VT-'.$block(4).'-'.$block(3);
        } while (Ballot::query()->acrossOrganizations()->where('reference', $reference)->exists());

        return $reference;
    }
}
