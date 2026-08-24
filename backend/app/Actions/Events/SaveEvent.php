<?php

namespace App\Actions\Events;

use App\Data\EventDraft;
use App\Enums\AuditAction;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\SlugGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Creation et modification d'un evenement (section 11).
 *
 * Un seul point d'entree pour les deux : creer un evenement et le modifier
 * partagent la meme validation metier, la meme gestion d'images et le meme
 * traitement du lieu. Deux actions separees auraient diverge des la premiere
 * evolution.
 *
 * L'organisation n'est jamais passee en parametre : elle vient du contexte
 * etabli par EnsureOrganizationContext, et le trait BelongsToOrganization
 * refuse toute ecriture visant une autre organisation (section 80.4).
 */
class SaveEvent
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SlugGenerator $slugs,
    ) {}

    public function create(EventDraft $draft, User $actor): Event
    {
        return DB::transaction(function () use ($draft, $actor) {
            $event = new Event($draft->eventAttributes());

            $event->slug = $this->slugs->unique(Event::class, $draft->name);
            $event->status = EventStatus::Draft;
            $event->created_by = $actor->getKey();

            $this->attachImages($event, $draft->poster, $draft->cover);
            $event->save();

            $event->location()->create($draft->locationAttributes());

            $this->audit->log(AuditAction::EventCreated, $event, ['name' => $event->name], actor: $actor);

            return $event->refresh();
        });
    }

    public function update(Event $event, EventDraft $draft, User $actor): Event
    {
        return DB::transaction(function () use ($event, $draft, $actor) {
            $this->assertEditable($event);

            $event->fill($draft->eventAttributes());

            /*
             * Section 13 : le slug suit le nom tant que l'evenement n'est pas
             * publie. Une fois publie, il est fige — le lien a pu partir sur
             * WhatsApp ou sur une affiche imprimee, que personne ne pourra
             * corriger.
             */
            if (! $event->isPublished() && $event->isDirty('name')) {
                $event->slug = $this->slugs->unique(
                    Event::class,
                    $draft->name,
                    ignoreId: $event->getKey(),
                );
            }

            $this->attachImages($event, $draft->poster, $draft->cover);

            $changed = array_keys($event->getDirty());
            $event->save();

            $event->location()->updateOrCreate([], $draft->locationAttributes());

            $this->audit->log(AuditAction::EventUpdated, $event, ['changed' => $changed], actor: $actor);

            return $event->refresh();
        });
    }

    /**
     * Un evenement en vente n'est plus librement modifiable : changer sa date
     * ou son lieu engage des participants qui ont deja paye. Cela releve d'une
     * communication et d'un remboursement, pas d'une edition discrete
     * (section 12).
     */
    private function assertEditable(Event $event): void
    {
        if ($event->status->isEditable()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Un evenement au statut « %s » ne peut plus etre modifie librement.',
            $event->status->label()
        ));
    }

    private function attachImages(Event $event, ?UploadedFile $poster, ?UploadedFile $cover): void
    {
        if ($poster) {
            $this->replace($event, 'poster_path', $poster);
        }

        if ($cover) {
            $this->replace($event, 'cover_path', $cover);
        }
    }

    /**
     * Les affiches vivent sur le disque public : elles doivent etre lisibles
     * par n'importe qui ouvrant le lien de l'evenement, et servent d'apercu de
     * partage (section 14). Rien de sensible ne s'y trouve.
     */
    private function replace(Event $event, string $column, UploadedFile $file): void
    {
        $previous = $event->getAttribute($column);

        $event->setAttribute(
            $column,
            $file->store('events/'.($event->getKey() ?? 'nouveaux'), 'public'),
        );

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }
    }
}
