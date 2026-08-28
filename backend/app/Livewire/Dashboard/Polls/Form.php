<?php

namespace App\Livewire\Dashboard\Polls;

use App\Actions\Polls\PublishPoll;
use App\Actions\Polls\SavePoll;
use App\Data\PollDraft;
use App\Enums\PermissionName;
use App\Enums\ResultsVisibility;
use App\Models\Poll;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Creation, modification et pilotage d'un vote (section 86.4).
 *
 * Un seul composant pour la creation et la modification, comme pour les
 * collectes : memes champs, meme validation, memes conditions de publication.
 *
 * DEUX CHAMPS N'EXISTENT PAS ICI, ET C'EST LE POINT : le nombre de votes et le
 * classement. Ils sont agreges depuis les bulletins confirmes et affiches en
 * lecture seule (section 86.5). Un formulaire qui les porterait, meme desactive,
 * laisserait croire qu'ils se saisissent.
 */
#[Layout('components.layouts.app')]
class Form extends Component
{
    use WithFileUploads;

    public ?Poll $poll = null;

    public string $name = '';

    public string $description = '';

    /*
     * Les dates transitent en chaines locales `Y-m-d\TH:i` (format d'un champ
     * datetime-local) et sont converties au fuseau d'affichage avant d'etre
     * stockees en UTC (decision D-007).
     */
    public string $startsAt = '';

    public string $endsAt = '';

    public string $resultsVisibility = ResultsVisibility::AfterClose->value;

    public $poster = null;

    public $banner = null;

    public string $cancellationReason = '';

    public bool $confirmingCancellation = false;

    public function mount(?Poll $poll = null): void
    {
        abort_unless(auth()->user()->can(PermissionName::VotesManage->value), 403);

        if (! $poll?->exists) {
            // Une fenetre par defaut plutot que deux champs vides : la plupart
            // des concours durent une a deux semaines, et l'organisateur
            // corrige plus vite qu'il ne saisit.
            $this->startsAt = $this->toLocalInput(now()->addDay()->startOfHour());
            $this->endsAt = $this->toLocalInput(now()->addWeeks(2)->startOfHour());

            return;
        }

        $this->poll = $poll;

        $this->name = $poll->name;
        $this->description = (string) $poll->description;
        $this->startsAt = $this->toLocalInput($poll->starts_at);
        $this->endsAt = $this->toLocalInput($poll->ends_at);
        $this->resultsVisibility = $poll->results_visibility->value;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Les deux bornes sont obligatoires : un concours sans date de fin
            // n'a pas de gagnant, et la base refuse aussi le cas.
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after:startsAt'],
            'resultsVisibility' => ['required', 'in:'.implode(',', ResultsVisibility::values())],
            // 4 Mo : une image de partage, pas un fichier d'impression.
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => 'nom du vote',
            'description' => 'description',
            'startsAt' => 'date d\'ouverture',
            'endsAt' => 'date de fermeture',
            'resultsVisibility' => 'visibilite des resultats',
            'poster' => 'image',
            'banner' => 'banniere',
        ];
    }

    protected function messages(): array
    {
        return [
            'endsAt.after' => 'La fermeture doit suivre l\'ouverture.',
            'poster.max' => 'L\'image ne doit pas depasser 4 Mo.',
            'banner.max' => 'La banniere ne doit pas depasser 4 Mo.',
        ];
    }

    public function save(SavePoll $savePoll): void
    {
        $poll = $this->persist($savePoll);

        session()->flash('notice', $this->poll ? 'Vote enregistre.' : 'Vote cree.');

        $this->redirectRoute('polls.categories', $poll, navigate: true);
    }

    /**
     * Enregistre puis publie dans la foulee.
     *
     * Un seul geste pour l'organisateur, deux operations distinctes cote
     * serveur : si la publication est refusee faute d'une categorie ou d'un
     * candidat, la saisie est deja sauvegardee et rien n'est perdu.
     */
    public function saveAndPublish(SavePoll $savePoll, PublishPoll $publishPoll): void
    {
        $poll = $this->persist($savePoll);

        try {
            $publishPoll->publish($poll, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('publication', $e->getMessage());
            $this->poll = $poll->refresh();

            return;
        }

        session()->flash('notice', 'Vote en ligne. Votre lien est pret a partager.');

        $this->redirectRoute('polls.edit', $poll, navigate: true);
    }

    public function openNow(PublishPoll $publishPoll): void
    {
        $this->applyDecision(
            fn () => $publishPoll->open($this->poll, auth()->user()),
            'Vote ouvert. Le public peut voter des maintenant.',
        );
    }

    public function close(PublishPoll $publishPoll): void
    {
        $this->applyDecision(
            fn () => $publishPoll->close($this->poll, auth()->user()),
            'Vote cloture. Les resultats sont figes.',
        );
    }

    public function cancel(PublishPoll $publishPoll): void
    {
        $this->validate(
            ['cancellationReason' => ['required', 'string', 'min:5', 'max:500']],
            ['cancellationReason.required' => 'Une annulation doit toujours etre motivee.'],
            ['cancellationReason' => 'motif'],
        );

        $reason = trim($this->cancellationReason);

        $this->applyDecision(
            fn () => $publishPoll->cancel($this->poll, auth()->user(), $reason),
            'Vote annule. Le motif est visible sur la page publique.',
        );

        $this->confirmingCancellation = false;
    }

    /**
     * Applique un changement d'etat.
     *
     * L'action decide, le composant se contente de rapporter : un refus revient
     * en message a l'ecran, jamais en exception non traitee. Les regles de
     * transition vivent dans `PollStatus`, pas ici.
     *
     * Nom volontairement distinct de `transition` : Livewire en expose deja une,
     * publique, et la masquer par une methode privee empeche le composant de se
     * charger.
     */
    private function applyDecision(callable $operation, string $notice): void
    {
        abort_unless(auth()->user()->can(PermissionName::VotesManage->value), 403);

        if (! $this->poll) {
            return;
        }

        try {
            $this->poll = $operation();
        } catch (RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage());

            return;
        }

        $this->dispatch('notify', message: $notice);
    }

    private function persist(SavePoll $savePoll): Poll
    {
        $this->name = trim($this->name);

        $this->validate();

        $draft = new PollDraft(
            name: $this->name,
            startsAt: $this->fromLocalInput($this->startsAt),
            endsAt: $this->fromLocalInput($this->endsAt),
            description: $this->nullable($this->description),
            resultsVisibility: ResultsVisibility::from($this->resultsVisibility),
            poster: $this->poster instanceof TemporaryUploadedFile ? $this->poster : null,
            banner: $this->banner instanceof TemporaryUploadedFile ? $this->banner : null,
        );

        $actor = auth()->user();

        return $this->poll
            ? $savePoll->update($this->poll, $draft, $actor)
            : $savePoll->create($draft, $actor);
    }

    public function render()
    {
        return view('livewire.dashboard.polls.form', [
            'visibilities' => ResultsVisibility::cases(),
            // Une fois le vote ouvert, la fenetre et la visibilite ne se
            // modifient plus : ce sont les regles du jeu, et ceux qui ont vote
            // l'ont fait sous celles annoncees.
            'termsEditable' => $this->poll === null || $this->poll->status->isEditable(),
            'blockers' => $this->poll?->publicationBlockers() ?? [],
        ]);
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
}
