<?php

use App\Actions\Events\PublishEvent;
use App\Actions\Events\SaveEvent;
use App\Data\EventDraft;
use App\Enums\AuditAction;
use App\Enums\EventCategory;
use App\Enums\EventStatus;
use App\Enums\EventVisibility;
use App\Models\AuditLog;
use App\Models\Event;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Creation, modification et publication d'un evenement (sections 11 a 13).
 */

beforeEach(function () {
    Storage::fake('public');

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');
    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $this->draft = fn (array $overrides = []) => new EventDraft(...array_merge([
        'name' => 'Concert Afro 2026',
        'startsAt' => now()->addMonth(),
        'locationName' => 'Institut francais du Gabon',
        'description' => 'Une soiree de musique afro a Libreville.',
        'categories' => [EventCategory::Music->value],
        'poster' => UploadedFile::fake()->image('affiche.jpg'),
    ], $overrides));
});

it('cree un evenement en brouillon avec son lieu', function () {
    $event = app(SaveEvent::class)->create(($this->draft)(), $this->owner);

    expect($event->status)->toBe(EventStatus::Draft)
        ->and($event->organization_id)->toBe($this->organization->id)
        ->and($event->created_by)->toBe($this->owner->id)
        ->and($event->published_at)->toBeNull()
        ->and($event->location->name)->toBe('Institut francais du Gabon')
        ->and($event->location->city)->toBe('Libreville');
});

it('derive un lien lisible du nom de l evenement', function () {
    $event = app(SaveEvent::class)->create(($this->draft)(), $this->owner);

    expect($event->slug)->toBe('concert-afro-2026')
        ->and($event->publicUrl())->toEndWith('/e/concert-afro-2026');
});

it('distingue deux evenements portant le meme nom, meme dans des organisations differentes', function () {
    app(SaveEvent::class)->create(($this->draft)(), $this->owner);

    // Le slug est unique sur toute la plateforme : l'adresse publique ne porte
    // pas de segment d'organisation (section 6).
    [$otherOwner, $otherOrganization] = organizerWithOrganization('Autre organisation');
    actAsOrganization($otherOrganization);

    $second = app(SaveEvent::class)->create(($this->draft)(), $otherOwner);

    expect($second->slug)->toBe('concert-afro-2026-2');
});

it('stocke les dates en UTC a partir de l heure de Libreville', function () {
    // Le Gabon est a UTC+1 toute l'annee : 20h00 local vaut 19h00 UTC.
    $local = Carbon\Carbon::parse('2026-03-14 20:00', config('app.display_timezone'));

    $event = app(SaveEvent::class)->create(($this->draft)(['startsAt' => $local->utc()]), $this->owner);

    expect($event->starts_at->timezone('UTC')->format('Y-m-d H:i'))->toBe('2026-03-14 19:00')
        ->and($event->starts_at->timezone(config('app.display_timezone'))->format('H:i'))->toBe('20:00');
});

it('range l affiche sur le disque public', function () {
    $event = app(SaveEvent::class)->create(($this->draft)(), $this->owner);

    // Une affiche n'a rien de sensible : elle sert d'apercu de partage et doit
    // etre lisible par n'importe qui ouvrant le lien (section 14).
    expect($event->poster_path)->not->toBeNull();
    Storage::disk('public')->assertExists($event->poster_path);
});

it('utilise l affiche quand aucune banniere n est fournie', function () {
    $event = app(SaveEvent::class)->create(($this->draft)(), $this->owner);

    expect($event->coverUrl())->toBe($event->posterUrl());
});

it('refuse une date de fin anterieure au debut', function () {
    expect(fn () => app(SaveEvent::class)->create(($this->draft)([
        'startsAt' => now()->addMonth(),
        'endsAt' => now()->addMonth()->subHour(),
    ]), $this->owner))->toThrow(QueryException::class);
});

it('publie un evenement complet et lui donne sa page publique', function () {
    $event = app(SaveEvent::class)->create(($this->draft)(), $this->owner);

    $published = app(PublishEvent::class)->publish($event, $this->owner);

    expect($published->status)->toBe(EventStatus::Published)
        ->and($published->published_at)->not->toBeNull()
        ->and($published->allowsPurchase())->toBeTrue()
        ->and($published->status->hasPublicPage())->toBeTrue();
});

it('refuse de publier un evenement incomplet et dit ce qui manque', function () {
    $event = app(SaveEvent::class)->create(($this->draft)([
        'description' => null,
        'poster' => null,
    ]), $this->owner);

    expect($event->publicationBlockers())->toHaveCount(2)
        ->and($event->canBePublished())->toBeFalse();

    expect(fn () => app(PublishEvent::class)->publish($event, $this->owner))
        ->toThrow(RuntimeException::class);

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});

it('refuse de publier un evenement dont la date est passee', function () {
    $event = app(SaveEvent::class)->create(($this->draft)(), $this->owner);
    $event->forceFill(['starts_at' => now()->subDay()])->save();

    expect($event->fresh()->publicationBlockers())
        ->toContain('La date de debut est deja passee.');
});

it('fige le lien public une fois l evenement publie', function () {
    $event = app(SaveEvent::class)->create(($this->draft)(), $this->owner);
    app(PublishEvent::class)->publish($event, $this->owner);

    // Section 13 : une fois le lien parti sur WhatsApp ou imprime sur une
    // affiche, le changer casse tout ce qui a deja circule.
    $event->refresh()->slug = 'un-autre-lien';

    expect(fn () => $event->save())->toThrow(RuntimeException::class);
});

it('suit le nom tant que l evenement reste en brouillon', function () {
    $event = app(SaveEvent::class)->create(($this->draft)(), $this->owner);

    $updated = app(SaveEvent::class)->update(
        $event,
        ($this->draft)(['name' => 'Concert Afro Deluxe']),
        $this->owner,
    );

    expect($updated->slug)->toBe('concert-afro-deluxe');
});

it('empeche la modification libre d un evenement en vente', function () {
    $event = app(SaveEvent::class)->create(($this->draft)(), $this->owner);
    app(PublishEvent::class)->publish($event, $this->owner);

    // Changer la date ou le lieu engage des participants qui ont deja paye :
    // cela releve d'une communication, pas d'une edition discrete.
    expect(fn () => app(SaveEvent::class)->update($event->fresh(), ($this->draft)(), $this->owner))
        ->toThrow(RuntimeException::class);
});

it('conserve le lien lors d un retour au brouillon puis d une republication', function () {
    $event = app(SaveEvent::class)->create(($this->draft)(), $this->owner);
    $publisher = app(PublishEvent::class);

    $publisher->publish($event, $this->owner);
    $slug = $event->fresh()->slug;
    $publishedAt = $event->fresh()->published_at;

    $publisher->unpublish($event->fresh(), $this->owner);
    expect($event->fresh()->status)->toBe(EventStatus::Draft);

    $publisher->publish($event->fresh(), $this->owner);

    expect($event->fresh()->slug)->toBe($slug)
        ->and($event->fresh()->published_at->timestamp)->toBe($publishedAt->timestamp);
});

it('annule un evenement avec un motif obligatoire', function () {
    $event = app(SaveEvent::class)->create(($this->draft)(), $this->owner);
    app(PublishEvent::class)->publish($event, $this->owner);

    expect(fn () => app(PublishEvent::class)->cancel($event->fresh(), $this->owner, '   '))
        ->toThrow(RuntimeException::class);

    $cancelled = app(PublishEvent::class)->cancel($event->fresh(), $this->owner, 'Report a une date ulterieure.');

    expect($cancelled->status)->toBe(EventStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Report a une date ulterieure.')
        ->and($cancelled->allowsPurchase())->toBeFalse()
        // La page reste en ligne pour annoncer l'annulation a qui rouvre le lien.
        ->and($cancelled->status->hasPublicPage())->toBeTrue();
});

it('refuse une transition de statut impossible', function () {
    $event = Event::factory()->for($this->organization)->cancelled()->create();

    expect(fn () => app(PublishEvent::class)->publish($event, $this->owner))
        ->toThrow(RuntimeException::class);
});

it('journalise la creation, la modification, la publication et l annulation', function () {
    $event = app(SaveEvent::class)->create(($this->draft)(), $this->owner);
    app(SaveEvent::class)->update($event, ($this->draft)(['name' => 'Concert Afro 2027']), $this->owner);
    app(PublishEvent::class)->publish($event->fresh(), $this->owner);
    app(PublishEvent::class)->cancel($event->fresh(), $this->owner, 'Salle indisponible.');

    foreach ([
        AuditAction::EventCreated,
        AuditAction::EventUpdated,
        AuditAction::EventPublished,
        AuditAction::EventCancelled,
    ] as $action) {
        expect(AuditLog::action($action)->where('organization_id', $this->organization->id)->exists())
            ->toBeTrue("Action {$action->value} absente du journal");
    }
});

it('n autorise que trois categories', function () {
    $event = app(SaveEvent::class)->create(($this->draft)([
        'categories' => [EventCategory::Music->value, EventCategory::Culture->value],
    ]), $this->owner);

    expect($event->categoryLine())->toBe('Musique • Culture');
});

it('marque un evenement prive comme non referencable', function () {
    $event = app(SaveEvent::class)->create(($this->draft)([
        'visibility' => EventVisibility::Unlisted,
    ]), $this->owner);

    expect($event->visibility->isIndexable())->toBeFalse();
});
