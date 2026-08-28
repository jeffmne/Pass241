<?php

namespace App\Actions\Polls;

use App\Enums\AuditAction;
use App\Enums\PollStatus;
use App\Models\Poll;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Changements d'etat d'un scrutin (section 86.4).
 *
 * Regroupes ici, hors de `SavePoll` : ce ne sont pas des modifications de
 * contenu mais des decisions, avec chacune leur consequence sur le vote et leur
 * trace au journal (section 51).
 *
 * AUCUN DE CES GESTES NE TOUCHE A UN BULLETIN. Cloturer ou annuler ferme le vote
 * pour l'avenir ; les bulletins deja confirmes restent confirmes et restent
 * comptes. C'est la difference entre arreter de recevoir et effacer ce qui a ete
 * recu.
 */
class PublishPoll
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Met le scrutin en ligne.
     *
     * Une seule methode pour les deux cas, parce que c'est un seul geste du
     * point de vue de l'organisateur : il publie, et la date d'ouverture decide
     * si le vote demarre tout de suite ou attend. Lui demander de choisir entre
     * « publier » et « programmer » lui ferait porter une distinction qui se
     * lit deja dans les dates qu'il vient de saisir.
     */
    public function publish(Poll $poll, User $actor): Poll
    {
        $target = $poll->hasStarted() ? PollStatus::Active : PollStatus::Scheduled;

        $this->assertTransition($poll, $target);

        $blockers = $poll->publicationBlockers();

        if ($blockers !== []) {
            // Message unique reprenant tous les manques : corriger un point pour
            // en decouvrir un autre au clic suivant est penible.
            throw new RuntimeException(implode(' ', $blockers));
        }

        return DB::transaction(function () use ($poll, $actor, $target) {
            $firstPublication = ! $poll->isPublished();

            $poll->forceFill([
                'status' => $target,
                // La date de premiere publication ne bouge plus : c'est elle qui
                // fige le lien public.
                'published_at' => $poll->published_at ?? now(),
            ])->save();

            $this->audit->log(
                AuditAction::PollPublished,
                $poll,
                [
                    'first_publication' => $firstPublication,
                    'slug' => $poll->slug,
                    'status' => $target->value,
                ],
                actor: $actor,
            );

            return $poll->refresh();
        });
    }

    /**
     * Ouvre un scrutin programme avant l'heure.
     *
     * Distinct de `publish()` : ici le scrutin est deja en ligne et annonce, et
     * l'organisateur decide d'ouvrir plus tot. La date d'ouverture est ramenee a
     * maintenant, faute de quoi `Poll::acceptsBallots()` continuerait de refuser
     * — la fenetre prime toujours sur l'etiquette (section 86.5, point 8).
     */
    public function open(Poll $poll, User $actor): Poll
    {
        $this->assertTransition($poll, PollStatus::Active);

        if ($poll->hasLapsed()) {
            throw new RuntimeException(
                'La date de fin de ce vote est passee : il ne peut plus etre ouvert.'
            );
        }

        return DB::transaction(function () use ($poll, $actor) {
            $poll->forceFill([
                'status' => PollStatus::Active,
                'starts_at' => $poll->hasStarted() ? $poll->starts_at : now(),
            ])->save();

            $this->audit->log(
                AuditAction::PollPublished,
                $poll,
                ['opened_early' => true],
                actor: $actor,
            );

            return $poll->refresh();
        });
    }

    /**
     * Cloture le scrutin.
     *
     * Geste volontaire de l'organisateur, ou passage de la tache planifiee a
     * l'echeance. Dans les deux cas, c'est ce moment qui FIGE LE CLASSEMENT
     * (jalon 9.5) : apres lui, les resultats ne bougent plus, meme si un
     * remboursement intervient.
     *
     * `$actor` est nullable : la tache planifiee n'a pas d'utilisateur, et
     * inventer un acteur au journal serait mentir sur qui a agi.
     */
    public function close(Poll $poll, ?User $actor = null): Poll
    {
        $this->assertTransition($poll, PollStatus::Closed);

        return DB::transaction(function () use ($poll, $actor) {
            $poll->forceFill([
                'status' => PollStatus::Closed,
                'closed_at' => now(),
            ])->save();

            $this->audit->log(
                AuditAction::PollClosed,
                $poll,
                ['automatic' => $actor === null],
                actor: $actor,
            );

            return $poll->refresh();
        });
    }

    /**
     * Annule le scrutin.
     *
     * La page publique reste en ligne et affiche l'annulation : quelqu'un qui
     * ouvre un lien recu doit lire que le concours est annule, pas tomber sur
     * une page introuvable.
     *
     * Le remboursement des bulletins payes n'est PAS automatique, comme pour une
     * collecte annulee (decision D-028) : il passe par le module de
     * remboursement, bulletin par bulletin. La difference avec un evenement
     * annule tient a ce qu'un billet non honore n'a plus d'objet, alors qu'un
     * vote a bien ete exprime et compte.
     */
    public function cancel(Poll $poll, User $actor, string $reason): Poll
    {
        $this->assertTransition($poll, PollStatus::Cancelled);

        if (trim($reason) === '') {
            throw new RuntimeException('Une annulation doit toujours etre motivee.');
        }

        return DB::transaction(function () use ($poll, $actor, $reason) {
            $poll->forceFill([
                'status' => PollStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($reason),
            ])->save();

            $this->audit->log(
                AuditAction::PollCancelled,
                $poll,
                ['reason' => trim($reason)],
                actor: $actor,
            );

            return $poll->refresh();
        });
    }

    private function assertTransition(Poll $poll, PollStatus $target): void
    {
        if ($poll->status->canTransitionTo($target)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Passage impossible de « %s » a « %s ».',
            $poll->status->label(),
            $target->label()
        ));
    }
}
