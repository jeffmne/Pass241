<?php

namespace App\Livewire\Dashboard\Polls;

use App\Actions\Polls\SaveCandidate;
use App\Actions\Polls\SavePollCategory;
use App\Enums\PermissionName;
use App\Enums\ResultsVisibility;
use App\Models\Candidate;
use App\Models\Poll;
use App\Models\PollCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Categories et candidats d'un vote, sur un seul ecran (section 86.5).
 *
 * Un seul ecran, et c'est un choix : une categorie sans candidat n'existe pas
 * vraiment, et les separer obligerait a naviguer entre deux pages pour monter
 * un concours — alors que le geste naturel est « je cree Miss Libreville, j'y
 * mets mes cinq candidates, je passe a la suivante ».
 *
 * AUCUN CHAMP DE VOTES NULLE PART. L'organisateur regle qui concourt et sous
 * quelles regles ; il ne touche jamais aux resultats (section 86.4).
 */
#[Layout('components.layouts.app')]
class Categories extends Component
{
    use WithFileUploads;

    public Poll $poll;

    /*
     * -----------------------------------------------------------------------
     * Formulaire de categorie
     * -----------------------------------------------------------------------
     */

    /** Null = aucun formulaire ouvert. 0 = creation. Sinon, l'identifiant edite. */
    public ?int $editingCategory = null;

    public string $categoryName = '';

    public string $categoryDescription = '';

    public bool $categoryIsPaid = false;

    public ?int $categoryUnitPrice = null;

    public ?int $categoryMaxVotes = 1;

    public string $categoryStartsAt = '';

    public string $categoryEndsAt = '';

    /** Chaine vide = herite du scrutin. */
    public string $categoryResultsVisibility = '';

    /*
     * -----------------------------------------------------------------------
     * Formulaire de candidat
     * -----------------------------------------------------------------------
     */

    public ?int $candidateCategoryId = null;

    public ?int $editingCandidate = null;

    public string $candidateName = '';

    public string $candidateNumber = '';

    public string $candidateBiography = '';

    public $candidatePhoto = null;

    public function mount(Poll $poll): void
    {
        abort_unless(auth()->user()->can(PermissionName::VotesManage->value), 403);

        $this->poll = $poll;
    }

    /*
     * -----------------------------------------------------------------------
     * Categories
     * -----------------------------------------------------------------------
     */

    public function newCategory(): void
    {
        $this->resetCategoryForm();
        $this->editingCategory = 0;
    }

    public function editCategory(int $categoryId): void
    {
        $category = $this->findCategory($categoryId);

        $this->editingCategory = $category->getKey();
        $this->categoryName = $category->name;
        $this->categoryDescription = (string) $category->description;
        $this->categoryIsPaid = $category->is_paid;
        $this->categoryUnitPrice = $category->unit_price ?: null;
        $this->categoryMaxVotes = $category->max_votes_per_voter;
        $this->categoryStartsAt = $this->toLocalInput($category->starts_at);
        $this->categoryEndsAt = $this->toLocalInput($category->ends_at);
        $this->categoryResultsVisibility = $category->results_visibility?->value ?? '';
    }

    public function saveCategory(SavePollCategory $saveCategory): void
    {
        $this->categoryName = trim($this->categoryName);

        $this->validate([
            'categoryName' => ['required', 'string', 'min:2', 'max:150'],
            'categoryDescription' => ['nullable', 'string', 'max:2000'],
            // Le prix n'est exige que si la categorie est payante : la base
            // refuse aussi une categorie payante a zero franc.
            'categoryUnitPrice' => [
                $this->categoryIsPaid ? 'required' : 'nullable',
                'integer', 'min:1', 'max:10000000',
            ],
            'categoryMaxVotes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'categoryStartsAt' => ['nullable', 'date'],
            'categoryEndsAt' => ['nullable', 'date', 'after:categoryStartsAt'],
        ], [
            'categoryUnitPrice.required' => 'Indiquez le prix d\'un vote.',
            'categoryEndsAt.after' => 'La fermeture doit suivre l\'ouverture.',
        ], [
            'categoryName' => 'nom de la categorie',
            'categoryUnitPrice' => 'prix du vote',
            'categoryMaxVotes' => 'nombre de votes par personne',
        ]);

        /*
         * Une fenetre propre se regle entierement ou pas du tout.
         *
         * La base refuse une seule borne renseignee. Le message est verifie ici
         * pour qu'il soit lisible, plutot que de laisser remonter une exception
         * SQL brute (contrainte `poll_categories_window_check`).
         */
        if (($this->categoryStartsAt === '') !== ($this->categoryEndsAt === '')) {
            $this->addError('categoryStartsAt',
                'Renseignez les deux dates, ou aucune pour suivre celles du vote.');

            return;
        }

        $attributes = [
            'name' => $this->categoryName,
            'description' => $this->nullable($this->categoryDescription),
            'is_paid' => $this->categoryIsPaid,
            'unit_price' => $this->categoryIsPaid ? (int) $this->categoryUnitPrice : 0,
            'max_votes_per_voter' => $this->categoryMaxVotes ?: null,
            'starts_at' => $this->categoryStartsAt !== '' ? $this->fromLocalInput($this->categoryStartsAt) : null,
            'ends_at' => $this->categoryEndsAt !== '' ? $this->fromLocalInput($this->categoryEndsAt) : null,
            'results_visibility' => $this->categoryResultsVisibility,
        ];

        try {
            $this->editingCategory
                ? $saveCategory->update($this->findCategory($this->editingCategory), $attributes, auth()->user())
                : $saveCategory->create($this->poll, $attributes, auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage());

            return;
        }

        $this->resetCategoryForm();
        $this->poll->refresh();

        $this->dispatch('notify', message: 'Categorie enregistree.');
    }

    public function toggleCategory(int $categoryId, SavePollCategory $saveCategory): void
    {
        $category = $this->findCategory($categoryId);

        try {
            $saveCategory->update($category, ['is_active' => ! $category->is_active], auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage());

            return;
        }

        $this->dispatch('notify', message: $category->is_active
            ? 'Categorie fermee au vote.'
            : 'Categorie ouverte au vote.');
    }

    public function deleteCategory(int $categoryId, SavePollCategory $saveCategory): void
    {
        try {
            $saveCategory->delete($this->findCategory($categoryId), auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage());

            return;
        }

        $this->resetCategoryForm();
        $this->dispatch('notify', message: 'Categorie supprimee.');
    }

    public function cancelCategory(): void
    {
        $this->resetCategoryForm();
    }

    /*
     * -----------------------------------------------------------------------
     * Candidats
     * -----------------------------------------------------------------------
     */

    public function newCandidate(int $categoryId): void
    {
        $this->resetCandidateForm();
        $this->candidateCategoryId = $this->findCategory($categoryId)->getKey();
    }

    public function editCandidate(int $candidateId): void
    {
        $candidate = $this->findCandidate($candidateId);

        $this->resetCandidateForm();

        $this->editingCandidate = $candidate->getKey();
        $this->candidateCategoryId = $candidate->poll_category_id;
        $this->candidateName = $candidate->name;
        $this->candidateNumber = (string) $candidate->number;
        $this->candidateBiography = (string) $candidate->biography;
    }

    public function saveCandidate(SaveCandidate $saveCandidate): void
    {
        $this->candidateName = trim($this->candidateName);

        $this->validate([
            'candidateName' => ['required', 'string', 'min:2', 'max:150'],
            'candidateNumber' => ['nullable', 'string', 'max:20'],
            'candidateBiography' => ['nullable', 'string', 'max:2000'],
            'candidatePhoto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'candidatePhoto.max' => 'La photo ne doit pas depasser 4 Mo.',
        ], [
            'candidateName' => 'nom du candidat',
            'candidateNumber' => 'numero',
            'candidatePhoto' => 'photo',
        ]);

        $attributes = [
            'name' => $this->candidateName,
            'number' => $this->candidateNumber,
            'biography' => $this->nullable($this->candidateBiography),
        ];

        $photo = $this->candidatePhoto instanceof TemporaryUploadedFile ? $this->candidatePhoto : null;

        try {
            $this->editingCandidate
                ? $saveCandidate->update($this->findCandidate($this->editingCandidate), $attributes, auth()->user(), $photo)
                : $saveCandidate->create($this->findCategory($this->candidateCategoryId), $attributes, auth()->user(), $photo);
        } catch (QueryException $e) {
            /*
             * AVANT `RuntimeException`, et l'ordre n'est pas negociable :
             * `QueryException` descend de `PDOException`, qui descend elle-meme
             * de `RuntimeException`. Place apres, ce bloc ne serait jamais
             * atteint — le doublon de numero partait en simple notification au
             * lieu de se poser sur le champ fautif.
             *
             * Le seul echec SQL atteignable ici est ce doublon dans la
             * categorie : la contrainte d'unicite porte sur
             * `(poll_category_id, number)`.
             */
            $this->addError('candidateNumber', 'Ce numero est deja pris dans cette categorie.');

            return;
        } catch (RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage());

            return;
        }

        $this->resetCandidateForm();
        $this->dispatch('notify', message: 'Candidat enregistre.');
    }

    public function toggleCandidate(int $candidateId, SaveCandidate $saveCandidate): void
    {
        $candidate = $this->findCandidate($candidateId);

        $candidate->is_active
            ? $saveCandidate->disable($candidate, auth()->user())
            : $saveCandidate->enable($candidate, auth()->user());

        $this->dispatch('notify', message: $candidate->is_active
            // Le candidat reste visible et ses votes restent comptes : le dire
            // evite que l'organisateur croie avoir efface une voix.
            ? 'Candidat retire du vote. Ses voix deja exprimees restent comptees.'
            : 'Candidat reactive.');
    }

    public function deleteCandidate(int $candidateId, SaveCandidate $saveCandidate): void
    {
        try {
            $saveCandidate->delete($this->findCandidate($candidateId), auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage());

            return;
        }

        $this->resetCandidateForm();
        $this->dispatch('notify', message: 'Candidat supprime.');
    }

    public function cancelCandidate(): void
    {
        $this->resetCandidateForm();
    }

    /*
     * -----------------------------------------------------------------------
     * Rendu
     * -----------------------------------------------------------------------
     */

    public function render()
    {
        return view('livewire.dashboard.polls.categories', [
            'categories' => $this->poll->categories()->with('candidates')->get(),
            'visibilities' => ResultsVisibility::cases(),
            // Termes economiques figes une fois le vote ouvert : quelqu'un qui a
            // paye son vote l'a fait sous les regles annoncees.
            'termsEditable' => ! $this->poll->displayStatus()->acceptsBallots()
                && ! $this->poll->status->isClosed(),
            'isPublished' => $this->poll->isPublished(),
            'blockers' => $this->poll->publicationBlockers(),
        ]);
    }

    /*
     * -----------------------------------------------------------------------
     * Acces aux lignes — toujours par le scrutin
     * -----------------------------------------------------------------------
     */

    /**
     * Retrouve une categorie DE CE SCRUTIN.
     *
     * La recherche part de `$this->poll`, deja filtre par organisation : un
     * identifiant force dans une requete Livewire ne peut donc pas atteindre la
     * categorie d'une autre organisation (regle 80.4). Chercher par
     * `PollCategory::find()` ouvrirait exactement ce trou — la table ne porte
     * pas de portee, elle descend du scrutin.
     */
    private function findCategory(int $categoryId): PollCategory
    {
        return $this->poll->categories()->findOrFail($categoryId);
    }

    private function findCandidate(int $candidateId): Candidate
    {
        return Candidate::query()
            ->whereIn('poll_category_id', $this->poll->categories()->select('id'))
            ->findOrFail($candidateId);
    }

    private function resetCategoryForm(): void
    {
        $this->reset(
            'editingCategory', 'categoryName', 'categoryDescription', 'categoryIsPaid',
            'categoryUnitPrice', 'categoryMaxVotes', 'categoryStartsAt', 'categoryEndsAt',
            'categoryResultsVisibility',
        );

        $this->resetValidation();
    }

    private function resetCandidateForm(): void
    {
        $this->reset(
            'candidateCategoryId', 'editingCandidate', 'candidateName',
            'candidateNumber', 'candidateBiography', 'candidatePhoto',
        );

        $this->resetValidation();
    }

    private function nullable(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }

    private function fromLocalInput(string $value): Carbon
    {
        return Carbon::parse($value, config('app.display_timezone'))->utc();
    }

    private function toLocalInput(?Carbon $date): string
    {
        return $date?->timezone(config('app.display_timezone'))->format('Y-m-d\TH:i') ?? '';
    }
}
