<?php

namespace App\Actions\Events;

use App\Enums\AuditAction;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Publication, retour au brouillon et annulation d'un evenement (section 12).
 *
 * Les changements de statut sont regroupes ici, hors de SaveEvent : ce ne sont
 * pas des modifications de contenu mais des decisions, avec chacune leur
 * consequence sur la billetterie et leur trace au journal (section 68).
 */
class PublishEvent
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Met l'evenement en vente et lui donne sa page publique.
     */
    public function publish(Event $event, User $actor): Event
    {
        $this->assertTransition($event, EventStatus::Published);

        $blockers = $event->publicationBlockers();

        if ($blockers !== []) {
            // Message unique reprenant tous les manques : corriger un point
            // pour en decouvrir un autre au clic suivant est penible.
            throw new RuntimeException(implode(' ', $blockers));
        }

        return DB::transaction(function () use ($event, $actor) {
            $firstPublication = ! $event->isPublished();

            $event->forceFill([
                'status' => EventStatus::Published,
                // La date de premiere publication ne bouge plus : c'est elle
                // qui fige le lien public (section 13).
                'published_at' => $event->published_at ?? now(),
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ])->save();

            $this->audit->log(
                AuditAction::EventPublished,
                $event,
                ['first_publication' => $firstPublication, 'slug' => $event->slug],
                actor: $actor,
            );

            return $event->refresh();
        });
    }

    /**
     * Retire l'evenement de la vente sans l'annuler.
     *
     * `published_at` est conserve : le lien reste fige, de sorte qu'une remise
     * en vente reprenne exactement la meme adresse publique.
     */
    public function unpublish(Event $event, User $actor): Event
    {
        $this->assertTransition($event, EventStatus::Draft);

        $event->forceFill(['status' => EventStatus::Draft])->save();

        $this->audit->log(AuditAction::EventUpdated, $event, ['status' => 'DRAFT'], actor: $actor);

        return $event->refresh();
    }

    /**
     * Annule l'evenement.
     *
     * La page publique reste en ligne et affiche l'annulation : quelqu'un qui
     * ouvre le lien recu doit lire que l'evenement est annule, pas tomber sur
     * une page introuvable.
     */
    public function cancel(Event $event, User $actor, string $reason): Event
    {
        $this->assertTransition($event, EventStatus::Cancelled);

        if (trim($reason) === '') {
            throw new RuntimeException('Une annulation doit toujours etre motivee.');
        }

        return DB::transaction(function () use ($event, $actor, $reason) {
            $event->forceFill([
                'status' => EventStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($reason),
            ])->save();

            $this->audit->log(
                AuditAction::EventCancelled,
                $event,
                ['reason' => trim($reason)],
                actor: $actor,
            );

            return $event->refresh();
        });
    }

    private function assertTransition(Event $event, EventStatus $target): void
    {
        if ($event->status->canTransitionTo($target)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Passage impossible de « %s » a « %s ».',
            $event->status->label(),
            $target->label()
        ));
    }
}
