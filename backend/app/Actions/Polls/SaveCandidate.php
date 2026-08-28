<?php

namespace App\Actions\Polls;

use App\Enums\AuditAction;
use App\Models\Candidate;
use App\Models\PollCategory;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Candidats d'une categorie (section 86.4).
 *
 * L'organisateur peut ajouter, modifier ou desactiver un candidat — JAMAIS
 * modifier son nombre de votes. Cette derniere regle n'a pas besoin d'etre
 * verifiee ici : le compteur n'existe pas en colonne, il n'y a rien a ecrire
 * (section 86.5). C'est la raison pour laquelle cette action n'a aucun garde-fou
 * sur les totaux — il n'y a pas de total a garder.
 *
 * TOUT PASSE PAR LE JOURNAL. Ajouter ou desactiver un candidat en cours de
 * scrutin change ce sur quoi le public vote : c'est un geste que l'organisateur
 * doit pouvoir justifier.
 */
class SaveCandidate
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(PollCategory $category, array $attributes, User $actor, ?UploadedFile $photo = null): Candidate
    {
        $poll = $category->poll;

        /*
         * Un candidat ne s'ajoute pas a un scrutin clos.
         *
         * Il arriverait apres le classement fige, avec zero vote, et
         * apparaitrait dans le rapport final comme un perdant qui n'a jamais
         * concouru.
         */
        if ($poll->status->isClosed()) {
            throw new RuntimeException(
                'Un vote termine ou annule n\'accepte plus de nouveau candidat.'
            );
        }

        return DB::transaction(function () use ($category, $poll, $attributes, $actor, $photo) {
            $candidate = new Candidate($this->sanitize($attributes));

            $candidate->poll_category_id = $category->getKey();
            $candidate->position = $attributes['position']
                ?? ((int) $category->candidates()->max('position') + 1);

            if ($photo) {
                $candidate->photo_path = $photo->store('polls/'.$poll->getKey().'/candidats', 'public');
            }

            $candidate->save();

            $this->audit->log(
                AuditAction::CandidateSaved,
                $poll,
                [
                    'candidate_id' => $candidate->getKey(),
                    'category_id' => $category->getKey(),
                    'name' => $candidate->name,
                    'created' => true,
                    // Un ajout APRES l'ouverture merite d'etre reperable au
                    // journal : le public a deja commence a voter sur une liste
                    // qui vient de changer.
                    'poll_open' => $poll->acceptsBallots(),
                ],
                actor: $actor,
            );

            return $candidate->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Candidate $candidate, array $attributes, User $actor, ?UploadedFile $photo = null): Candidate
    {
        $poll = $candidate->category->poll;

        if ($poll->status->isClosed()) {
            throw new RuntimeException('Un vote termine ou annule ne se modifie plus.');
        }

        return DB::transaction(function () use ($candidate, $poll, $attributes, $actor, $photo) {
            $candidate->fill($this->sanitize($attributes));

            if ($photo) {
                $previous = $candidate->photo_path;

                $candidate->photo_path = $photo->store(
                    'polls/'.$poll->getKey().'/candidats',
                    'public'
                );

                if ($previous) {
                    Storage::disk('public')->delete($previous);
                }
            }

            $changed = array_keys($candidate->getDirty());
            $candidate->save();

            $this->audit->log(
                AuditAction::CandidateSaved,
                $poll,
                [
                    'candidate_id' => $candidate->getKey(),
                    'name' => $candidate->name,
                    'changed' => $changed,
                ],
                actor: $actor,
            );

            return $candidate->refresh();
        });
    }

    /**
     * Desactive un candidat.
     *
     * IL RESTE VISIBLE, et ses votes restent comptes. C'est tout l'objet de la
     * desactivation : un candidat disqualifie en cours de concours ne peut plus
     * recevoir de vote, mais faire disparaitre les votes deja exprimes pour lui
     * fausserait le total de la categorie — et le public qui a vote pour lui
     * verrait sa voix s'evaporer.
     */
    public function disable(Candidate $candidate, User $actor, ?string $reason = null): Candidate
    {
        $poll = $candidate->category->poll;

        return DB::transaction(function () use ($candidate, $poll, $actor, $reason) {
            $candidate->forceFill(['is_active' => false])->save();

            $this->audit->log(
                AuditAction::CandidateDisabled,
                $poll,
                [
                    'candidate_id' => $candidate->getKey(),
                    'name' => $candidate->name,
                    'reason' => $reason,
                    'poll_open' => $poll->acceptsBallots(),
                ],
                actor: $actor,
            );

            return $candidate->refresh();
        });
    }

    public function enable(Candidate $candidate, User $actor): Candidate
    {
        $candidate->forceFill(['is_active' => true])->save();

        $this->audit->log(
            AuditAction::CandidateSaved,
            $candidate->category->poll,
            ['candidate_id' => $candidate->getKey(), 'reactivated' => true],
            actor: $actor,
        );

        return $candidate->refresh();
    }

    /**
     * Supprime un candidat.
     *
     * Reservee au scrutin non publie. Ensuite, la desactivation est le seul
     * geste possible : supprimer emporterait les bulletins deja exprimes.
     */
    public function delete(Candidate $candidate, User $actor): void
    {
        $poll = $candidate->category->poll;

        if ($poll->isPublished()) {
            throw new RuntimeException(
                'Un candidat d\'un vote publie ne se supprime pas : desactivez-le, '
                .'les votes deja exprimes pour lui doivent rester comptes.'
            );
        }

        DB::transaction(function () use ($candidate, $poll, $actor) {
            $this->audit->log(
                AuditAction::CandidateSaved,
                $poll,
                ['candidate_id' => $candidate->getKey(), 'name' => $candidate->name, 'deleted' => true],
                actor: $actor,
            );

            if ($candidate->photo_path) {
                Storage::disk('public')->delete($candidate->photo_path);
            }

            $candidate->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function sanitize(array $attributes): array
    {
        $clean = array_intersect_key($attributes, array_flip([
            'name', 'biography', 'number', 'position', 'is_active',
        ]));

        // Une chaine vide n'est pas un numero : elle violerait l'unicite de la
        // categorie des le deuxieme candidat sans numero. Le null, lui, y
        // echappe — c'est le comportement voulu pour un champ facultatif.
        if (array_key_exists('number', $clean)) {
            $number = trim((string) $clean['number']);
            $clean['number'] = $number === '' ? null : $number;
        }

        return $clean;
    }
}
