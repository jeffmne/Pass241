<?php

namespace App\Livewire\Dashboard\Events;

use App\Actions\Events\PublishEvent;
use App\Actions\Events\SaveEvent;
use App\Data\EventDraft;
use App\Enums\EventCategory;
use App\Enums\EventVisibility;
use App\Enums\PermissionName;
use App\Models\Event;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Creation et modification d'un evenement (sections 11 a 13).
 *
 * Un seul composant pour les deux : les champs, la validation et les regles de
 * publication sont identiques, et les separer aurait duplique tout cela pour
 * la seule difference d'un `$event` deja existant.
 */
#[Layout('components.layouts.app')]
class Form extends Component
{
    use WithFileUploads;

    public ?Event $event = null;

    public string $name = '';

    public string $description = '';

    public string $programme = '';

    public string $practicalInfo = '';

    /** @var list<string> */
    public array $categories = [];

    /*
     * Les dates transitent en chaines locales `Y-m-d\TH:i` (format d'un champ
     * datetime-local) et sont converties au fuseau d'affichage avant d'etre
     * stockees en UTC (decision D-007). Traiter ici des Carbon exposerait le
     * decalage a l'utilisateur.
     */
    public string $startsAt = '';

    public string $endsAt = '';

    public ?int $maxCapacity = null;

    public string $visibility = 'LISTED';

    public string $locationName = '';

    public string $locationAddress = '';

    public string $locationCity = 'Libreville';

    public $poster = null;

    public $cover = null;

    public function mount(?Event $event = null): void
    {
        abort_unless(auth()->user()->can(PermissionName::EventsManage->value), 403);

        if (! $event?->exists) {
            return;
        }

        $this->event = $event;

        $this->name = $event->name;
        $this->description = (string) $event->description;
        $this->programme = (string) $event->programme;
        $this->practicalInfo = (string) $event->practical_info;
        $this->categories = $event->categories ?? [];
        $this->startsAt = $this->toLocalInput($event->starts_at);
        $this->endsAt = $this->toLocalInput($event->ends_at);
        $this->maxCapacity = $event->max_capacity;
        $this->visibility = $event->visibility->value;

        $this->locationName = (string) $event->location?->name;
        $this->locationAddress = (string) $event->location?->address;
        $this->locationCity = $event->location?->city ?? 'Libreville';
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'programme' => ['nullable', 'string', 'max:5000'],
            'practicalInfo' => ['nullable', 'string', 'max:2000'],
            'categories' => ['array', 'max:3'],
            'categories.*' => ['in:'.implode(',', EventCategory::values())],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['nullable', 'date', 'after_or_equal:startsAt'],
            'maxCapacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'visibility' => ['required', 'in:'.implode(',', array_column(EventVisibility::cases(), 'value'))],
            'locationName' => ['required', 'string', 'min:2', 'max:150'],
            'locationAddress' => ['nullable', 'string', 'max:200'],
            'locationCity' => ['required', 'string', 'max:100'],
            // 4 Mo : une affiche est une image de partage, pas un fichier
            // d'impression. Au-dela, le televersement devient penible sur une
            // connexion mobile et la page publique s'en trouve alourdie.
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => 'nom de l\'evenement',
            'description' => 'description',
            'startsAt' => 'date de debut',
            'endsAt' => 'date de fin',
            'maxCapacity' => 'capacite maximale',
            'locationName' => 'lieu',
            'locationCity' => 'ville',
            'poster' => 'affiche',
            'cover' => 'banniere',
        ];
    }

    protected function messages(): array
    {
        return [
            'endsAt.after_or_equal' => 'La fin ne peut pas preceder le debut.',
            'categories.max' => 'Choisissez trois categories au maximum.',
            'poster.max' => 'L\'affiche ne doit pas depasser 4 Mo.',
            'cover.max' => 'La banniere ne doit pas depasser 4 Mo.',
        ];
    }

    public function save(SaveEvent $saveEvent): void
    {
        $event = $this->persist($saveEvent);

        session()->flash('notice', $this->event ? 'Evenement enregistre.' : 'Evenement cree.');

        $this->redirectRoute('events.edit', $event, navigate: true);
    }

    /**
     * Enregistre puis publie dans la foulee.
     *
     * Un seul geste pour l'organisateur, mais deux operations distinctes cote
     * serveur : si la publication est refusee faute d'une information, le
     * travail de saisie est deja sauvegarde et rien n'est perdu.
     */
    public function saveAndPublish(SaveEvent $saveEvent, PublishEvent $publishEvent): void
    {
        $event = $this->persist($saveEvent);

        try {
            $publishEvent->publish($event, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('publication', $e->getMessage());
            $this->event = $event->refresh();

            return;
        }

        session()->flash('notice', 'Evenement publie. Votre lien est pret a partager.');

        $this->redirectRoute('events.edit', $event, navigate: true);
    }

    private function persist(SaveEvent $saveEvent): Event
    {
        $this->name = trim($this->name);
        $this->locationName = trim($this->locationName);

        $this->validate();

        $draft = new EventDraft(
            name: $this->name,
            startsAt: $this->fromLocalInput($this->startsAt),
            locationName: $this->locationName,
            description: $this->nullable($this->description),
            programme: $this->nullable($this->programme),
            practicalInfo: $this->nullable($this->practicalInfo),
            categories: array_values($this->categories),
            endsAt: $this->endsAt !== '' ? $this->fromLocalInput($this->endsAt) : null,
            maxCapacity: $this->maxCapacity,
            visibility: EventVisibility::from($this->visibility),
            locationAddress: $this->nullable($this->locationAddress),
            locationCity: $this->nullable($this->locationCity),
            poster: $this->poster instanceof TemporaryUploadedFile ? $this->poster : null,
            cover: $this->cover instanceof TemporaryUploadedFile ? $this->cover : null,
        );

        $actor = auth()->user();

        return $this->event
            ? $saveEvent->update($this->event, $draft, $actor)
            : $saveEvent->create($draft, $actor);
    }

    private function nullable(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }

    /** Chaine du champ datetime-local vers un instant absolu (UTC en base). */
    private function fromLocalInput(string $value): Carbon
    {
        return Carbon::parse($value, config('app.display_timezone'))->utc();
    }

    /** Instant absolu vers la chaine attendue par un champ datetime-local. */
    private function toLocalInput(?Carbon $date): string
    {
        return $date?->timezone(config('app.display_timezone'))->format('Y-m-d\TH:i') ?? '';
    }

    public function render()
    {
        return view('livewire.dashboard.events.form', [
            'categoryCases' => EventCategory::cases(),
            'visibilityCases' => EventVisibility::cases(),
            'isEditing' => $this->event !== null,
            'blockers' => $this->event?->publicationBlockers() ?? [],
        ]);
    }
}
