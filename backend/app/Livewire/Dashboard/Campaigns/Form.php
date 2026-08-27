<?php

namespace App\Livewire\Dashboard\Campaigns;

use App\Actions\Campaigns\PublishCampaign;
use App\Actions\Campaigns\SaveCampaign;
use App\Data\CampaignDraft;
use App\Enums\PermissionName;
use App\Models\Campaign;
use App\Services\Fundraising\CampaignProgress;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Creation, modification et pilotage d'une collecte (sections 34, 35).
 *
 * Un seul composant pour la creation et la modification, comme pour les
 * evenements : memes champs, meme validation, memes conditions de publication.
 *
 * DEUX CHAMPS N'EXISTENT PAS ICI, ET C'EST LE POINT : le montant collecte et le
 * pourcentage. Ils sont calcules par `CampaignProgress` et affiches en lecture
 * seule (regle 80.1, sections 38 et 50). Un formulaire qui les porterait, meme
 * desactive, laisserait croire qu'ils se saisissent.
 */
#[Layout('components.layouts.app')]
class Form extends Component
{
    use WithFileUploads;

    public ?Campaign $campaign = null;

    public string $name = '';

    public string $description = '';

    /** Objectif en francs, saisi tel quel : entiers XAF (decision D-003). */
    public ?int $goalAmount = null;

    public string $beneficiaryLabel = '';

    /*
     * Les dates transitent en chaines locales `Y-m-d\TH:i` (format d'un champ
     * datetime-local) et sont converties au fuseau d'affichage avant d'etre
     * stockees en UTC (decision D-007).
     */
    public string $startsAt = '';

    public string $endsAt = '';

    public $poster = null;

    public $cover = null;

    /** Motif d'annulation, saisi dans la zone de decision. */
    public string $cancellationReason = '';

    public bool $confirmingCancellation = false;

    public function mount(?Campaign $campaign = null): void
    {
        abort_unless(auth()->user()->can(PermissionName::CampaignsManage->value), 403);

        if (! $campaign?->exists) {
            return;
        }

        $this->campaign = $campaign;

        $this->name = $campaign->name;
        $this->description = (string) $campaign->description;
        $this->goalAmount = $campaign->goal_amount;
        $this->beneficiaryLabel = (string) $campaign->beneficiary_label;
        $this->startsAt = $this->toLocalInput($campaign->starts_at);
        $this->endsAt = $this->toLocalInput($campaign->ends_at);
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Un objectif nul rendrait le pourcentage indefini et la page
            // publique mensongere : la base le refuse aussi (section 65).
            'goalAmount' => ['required', 'integer', 'min:1000', 'max:1000000000'],
            'beneficiaryLabel' => ['required', 'string', 'min:2', 'max:150'],
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date', 'after_or_equal:startsAt'],
            // 4 Mo : une image de partage, pas un fichier d'impression.
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => 'nom de la collecte',
            'description' => 'description',
            'goalAmount' => 'objectif',
            'beneficiaryLabel' => 'beneficiaire',
            'startsAt' => 'date de debut',
            'endsAt' => 'date de fin',
            'poster' => 'image',
            'cover' => 'banniere',
        ];
    }

    protected function messages(): array
    {
        return [
            'endsAt.after_or_equal' => 'La fin ne peut pas preceder le debut.',
            'goalAmount.min' => 'Indiquez un objectif d\'au moins 1 000 F.',
            'poster.max' => 'L\'image ne doit pas depasser 4 Mo.',
            'cover.max' => 'La banniere ne doit pas depasser 4 Mo.',
        ];
    }

    public function save(SaveCampaign $saveCampaign): void
    {
        $campaign = $this->persist($saveCampaign);

        session()->flash('notice', $this->campaign ? 'Collecte enregistree.' : 'Collecte creee.');

        $this->redirectRoute('campaigns.edit', $campaign, navigate: true);
    }

    /**
     * Enregistre puis publie dans la foulee.
     *
     * Un seul geste pour l'organisateur, deux operations distinctes cote
     * serveur : si la publication est refusee faute d'une information, la
     * saisie est deja sauvegardee et rien n'est perdu.
     */
    public function saveAndPublish(SaveCampaign $saveCampaign, PublishCampaign $publishCampaign): void
    {
        $campaign = $this->persist($saveCampaign);

        try {
            $publishCampaign->publish($campaign, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('publication', $e->getMessage());
            $this->campaign = $campaign->refresh();

            return;
        }

        session()->flash('notice', 'Collecte en ligne. Votre lien est pret a partager.');

        $this->redirectRoute('campaigns.edit', $campaign, navigate: true);
    }

    public function pause(PublishCampaign $publishCampaign): void
    {
        $this->applyDecision(fn () => $publishCampaign->pause($this->campaign, auth()->user()),
            'Collecte suspendue. La page reste en ligne.');
    }

    public function resume(PublishCampaign $publishCampaign): void
    {
        $this->applyDecision(fn () => $publishCampaign->resume($this->campaign, auth()->user()),
            'Collecte rouverte.');
    }

    public function complete(PublishCampaign $publishCampaign): void
    {
        $this->applyDecision(fn () => $publishCampaign->complete($this->campaign, auth()->user()),
            'Collecte cloturee. Les dons recus restent consultables.');
    }

    public function cancel(PublishCampaign $publishCampaign): void
    {
        $this->validate(
            ['cancellationReason' => ['required', 'string', 'min:5', 'max:500']],
            ['cancellationReason.required' => 'Une annulation doit toujours etre motivee.'],
            ['cancellationReason' => 'motif'],
        );

        $reason = trim($this->cancellationReason);

        $this->applyDecision(
            fn () => $publishCampaign->cancel($this->campaign, auth()->user(), $reason),
            'Collecte annulee. Le motif est visible sur la page publique.',
        );

        $this->confirmingCancellation = false;
    }

    /**
     * Applique un changement d'etat.
     *
     * L'action decide, le composant se contente de rapporter : un refus revient
     * en message a l'ecran, jamais en exception non traitee. Les regles de
     * transition vivent dans `CampaignStatus`, pas ici.
     *
     * Nom volontairement distinct de `transition` : Livewire en expose deja une,
     * publique, et la masquer par une methode privee empeche le composant de se
     * charger.
     */
    private function applyDecision(callable $operation, string $notice): void
    {
        abort_unless(auth()->user()->can(PermissionName::CampaignsManage->value), 403);

        if (! $this->campaign) {
            return;
        }

        try {
            $this->campaign = $operation();
        } catch (RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage());

            return;
        }

        $this->dispatch('notify', message: $notice);
    }

    private function persist(SaveCampaign $saveCampaign): Campaign
    {
        $this->name = trim($this->name);
        $this->beneficiaryLabel = trim($this->beneficiaryLabel);

        $this->validate();

        $draft = new CampaignDraft(
            name: $this->name,
            goalAmount: (int) $this->goalAmount,
            beneficiaryLabel: $this->beneficiaryLabel,
            description: $this->nullable($this->description),
            startsAt: $this->startsAt !== '' ? $this->fromLocalInput($this->startsAt) : null,
            endsAt: $this->endsAt !== '' ? $this->fromLocalInput($this->endsAt) : null,
            poster: $this->poster instanceof TemporaryUploadedFile ? $this->poster : null,
            cover: $this->cover instanceof TemporaryUploadedFile ? $this->cover : null,
        );

        $actor = auth()->user();

        return $this->campaign
            ? $saveCampaign->update($this->campaign, $draft, $actor)
            : $saveCampaign->create($draft, $actor);
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

    public function render(CampaignProgress $progress)
    {
        return view('livewire.dashboard.campaigns.form', [
            'isEditing' => $this->campaign !== null,
            'blockers' => $this->campaign?->publicationBlockers() ?? [],
            // Verrouillage des champs engageants apres publication : l'objectif
            // et le beneficiaire engagent ceux qui ont donne (voir SaveCampaign).
            'locked' => $this->campaign !== null && ! $this->campaign->status->isEditable(),
            'closed' => $this->campaign?->status->isClosed() ?? false,
            'progress' => $this->campaign ? $progress->forCampaign($this->campaign) : null,
        ]);
    }
}
