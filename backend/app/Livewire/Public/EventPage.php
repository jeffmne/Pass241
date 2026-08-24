<?php

namespace App\Livewire\Public;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Mini-site public d'un evenement (sections 6, 7, 14).
 *
 * Rendu cote serveur, sans authentification. C'est la page que l'organisateur
 * partage lui-meme : elle doit s'ouvrir vite sur un telephone, produire un bel
 * apercu sur WhatsApp, et rester lisible meme quand la billetterie est fermee.
 */
#[Layout('components.layouts.public')]
class EventPage extends Component
{
    public Event $event;

    public function mount(string $slug): void
    {
        // `publicBySlug` leve la portee par organisation — un visiteur n'est
        // membre d'aucune — et exclut les evenements sans page publique
        // (brouillons, archives), qui doivent renvoyer 404 et non une page vide.
        $this->event = Event::publicBySlug($slug) ?? abort(404);
    }

    public function render()
    {
        $event = $this->event;
        $location = $event->location;

        /*
         * Section 14 : le lien doit produire un apercu correct sur WhatsApp,
         * Facebook, Instagram et par email. La description sociale reprend la
         * date et le lieu, car c'est ce que cherche quelqu'un a qui on vient de
         * transferer un lien.
         */
        $when = $event->starts_at
            ->timezone(config('app.display_timezone'))
            ->translatedFormat('j F Y \a\ H\hi');

        $social = trim(sprintf(
            '%s — %s. %s',
            $when,
            $location?->shortAddress() ?? '',
            Str::of((string) $event->description)->stripTags()->squish()->limit(120)
        ));

        $indexable = $event->visibility->isIndexable();

        return view('livewire.public.event-page')->layoutData([
            'title' => $event->name.' — Pass241',
            'description' => $social,
            'image' => $event->coverUrl(),
            'canonical' => $event->publicUrl(),
            'indexable' => $indexable,
            'event' => $event,
            'structuredData' => $indexable ? $this->structuredData($social) : null,
            // Le heros n'est sombre que s'il porte une image : sans banniere,
            // la page ouvre sur la nappe azurine claire et l'en-tete doit
            // rester en bleu, faute de quoi il deviendrait invisible.
            'heroDark' => $event->coverUrl() !== null,
        ]);
    }

    /**
     * Donnees structurees schema.org, deja encodees.
     *
     * Construites ici et non dans la vue : les cles du vocabulaire commencent
     * par `@` (`@context`, `@type`), que Blade prendrait pour des directives.
     *
     * Absentes d'un evenement non referencable : elles n'y auraient aucun
     * destinataire, et decriraient publiquement un evenement prive.
     */
    private function structuredData(string $description): string
    {
        $event = $this->event;
        $location = $event->location;

        $schema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->name,
            'description' => $description,
            'url' => $event->publicUrl(),
            'startDate' => $event->starts_at?->toIso8601String(),
            'endDate' => $event->ends_at?->toIso8601String(),
            'eventStatus' => $event->status === EventStatus::Cancelled
                ? 'https://schema.org/EventCancelled'
                : 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'image' => array_values(array_filter([$event->coverUrl()])),
            'location' => $location ? array_filter([
                '@type' => 'Place',
                'name' => $location->name,
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'streetAddress' => $location->address,
                    'addressLocality' => $location->city,
                    'addressCountry' => $location->country,
                ]),
            ]) : null,
            'organizer' => array_filter([
                '@type' => 'Organization',
                'name' => $event->organization?->name,
            ]),
        ], fn ($value) => $value !== null && $value !== []);

        return json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
