@php
    use App\Support\Money;

    $tz = config('app.display_timezone');
@endphp

{{--
    Categories et candidats d'un vote, sur un seul ecran (section 86.5).

    Un seul ecran, et c'est un choix : une categorie sans candidat n'existe pas
    vraiment, et les separer obligerait a naviguer entre deux pages pour monter
    un concours — alors que le geste naturel est « je cree Miss Libreville, j'y
    mets mes cinq candidates, je passe a la suivante ».

    AUCUN CHAMP DE VOTES NULLE PART. L'organisateur regle qui concourt et sous
    quelles regles ; il ne touche jamais aux resultats (section 86.4).
--}}
<div class="space-y-5">

    <x-ui.page-header
        :title="$poll->name"
        description="Vos categories et leurs candidats. Chaque categorie a ses propres regles.">
        <x-slot:actions>
            <a href="{{ route('polls.edit', $poll) }}" wire:navigate class="btn btn-secondary">
                Retour au vote
            </a>

            <button type="button" wire:click="newCategory" class="btn btn-primary">
                <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                Nouvelle categorie
            </button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-wrap items-center gap-3">
        <x-status-badge :status="$poll->displayStatus()" />
        <p class="text-sm text-grey-500">
            Du {{ $poll->starts_at->timezone($tz)->translatedFormat('j F Y') }}
            au {{ $poll->ends_at->timezone($tz)->translatedFormat('j F Y') }}
        </p>
    </div>

    @if ($blockers !== [] && ! $isPublished)
        <div class="surface p-4 sm:p-5">
            <h2 class="flex items-center gap-2 font-display text-sm font-bold text-ink">
                <x-lucide-circle-alert class="size-4 shrink-0 text-warning" stroke-width="2" aria-hidden="true" />
                Avant de publier
            </h2>
            <ul class="mt-3 space-y-1.5 text-sm text-grey-500">
                @foreach ($blockers as $blocker)
                    <li class="flex items-start gap-2">
                        <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-grey-400"></span>
                        {{ $blocker }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ---------------------------------------------------------------
         Formulaire de categorie
    ---------------------------------------------------------------- --}}
    @if ($editingCategory !== null)
        <x-ui.panel :title="$editingCategory ? 'Modifier la categorie' : 'Nouvelle categorie'">
            <form wire:submit="saveCategory" class="space-y-4">
                <x-ui.field label="Nom" for="cat-name" :error="$errors->first('categoryName')" required>
                    <input id="cat-name" type="text" wire:model="categoryName" class="field"
                           placeholder="Miss Libreville" maxlength="150" required>
                </x-ui.field>

                <x-ui.field label="Description" for="cat-description"
                            :error="$errors->first('categoryDescription')">
                    <textarea id="cat-description" wire:model="categoryDescription" rows="2"
                              class="field" maxlength="2000"></textarea>
                </x-ui.field>

                @unless ($termsEditable)
                    {{-- Gratuite, prix et plafond ne bougent plus une fois le
                         vote ouvert : quelqu'un qui a paye 500 francs son vote
                         l'a fait sous ces regles. --}}
                    <div class="flex items-start gap-2.5 rounded-[var(--radius-md)] border border-grey-200 bg-surface-muted px-4 py-3 text-sm text-grey-500">
                        <x-lucide-lock class="mt-0.5 size-4 shrink-0 text-grey-400" stroke-width="1.75" aria-hidden="true" />
                        <span>
                            Le vote est ouvert : le prix, le plafond et les dates de cette categorie
                            ne changent plus.
                        </span>
                    </div>
                @endunless

                <div class="space-y-4" @class(['opacity-60' => ! $termsEditable])>
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model.live="categoryIsPaid" class="mt-0.5 size-4 shrink-0"
                               @disabled(! $termsEditable)>
                        <span>
                            <span class="block text-sm font-semibold text-ink">Vote payant</span>
                            <span class="block text-sm text-grey-500">
                                Le votant paie par Airtel Money ou Moov Money. Le paiement est aussi
                                ce qui garantit qu'un vote a bien ete voulu.
                            </span>
                        </span>
                    </label>

                    @if ($categoryIsPaid)
                        <x-ui.field label="Prix d'un vote" for="cat-price"
                                    :error="$errors->first('categoryUnitPrice')"
                                    hint="En francs CFA." required>
                            <input id="cat-price" type="number" wire:model="categoryUnitPrice" class="field"
                                   min="1" step="1" @disabled(! $termsEditable) required>
                        </x-ui.field>
                    @endif

                    <x-ui.field label="Votes par personne" for="cat-max"
                                :error="$errors->first('categoryMaxVotes')"
                                hint="Laissez vide pour ne poser aucune limite.">
                        <input id="cat-max" type="number" wire:model="categoryMaxVotes" class="field"
                               min="1" step="1" @disabled(! $termsEditable)>
                    </x-ui.field>

                    <x-ui.field label="Resultats publics" for="cat-visibility"
                                hint="Vide : cette categorie suit le reglage du vote.">
                        <select id="cat-visibility" wire:model="categoryResultsVisibility" class="field"
                                @disabled(! $termsEditable)>
                            <option value="">Comme le vote ({{ $poll->results_visibility->label() }})</option>
                            @foreach ($visibilities as $visibility)
                                <option value="{{ $visibility->value }}">{{ $visibility->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field label="Ouverture propre" for="cat-starts"
                                    :error="$errors->first('categoryStartsAt')"
                                    hint="Vide : suit les dates du vote.">
                            <input id="cat-starts" type="datetime-local" wire:model="categoryStartsAt"
                                   class="field" @disabled(! $termsEditable)>
                        </x-ui.field>

                        <x-ui.field label="Fermeture propre" for="cat-ends"
                                    :error="$errors->first('categoryEndsAt')">
                            <input id="cat-ends" type="datetime-local" wire:model="categoryEndsAt"
                                   class="field" @disabled(! $termsEditable)>
                        </x-ui.field>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2.5">
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                    <button type="button" wire:click="cancelCategory" class="btn btn-secondary">Annuler</button>
                </div>
            </form>
        </x-ui.panel>
    @endif

    {{-- ---------------------------------------------------------------
         Les categories et leurs candidats
    ---------------------------------------------------------------- --}}
    @forelse ($categories as $category)
        <x-ui.panel wire:key="category-{{ $category->id }}">
            <div class="flex flex-col gap-4">

                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-display text-base font-bold text-ink">{{ $category->name }}</h2>

                            @unless ($category->is_active)
                                <span class="inline-flex items-center rounded-full bg-grey-200/70 px-2.5 py-1 text-xs font-semibold text-grey-500">
                                    Fermee
                                </span>
                            @endunless

                            @if ($category->is_paid)
                                <span class="inline-flex items-center rounded-full bg-azure-100 px-2.5 py-1 text-xs font-semibold text-azure-700 tabular">
                                    {{ Money::formatShort($category->unit_price) }} le vote
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-grey-200/70 px-2.5 py-1 text-xs font-semibold text-grey-500">
                                    Gratuit
                                </span>
                            @endif
                        </div>

                        @if ($category->description)
                            <p class="mt-1 text-sm text-grey-500">{{ $category->description }}</p>
                        @endif

                        <p class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-grey-500">
                            <span>
                                {{ $category->max_votes_per_voter
                                    ? $category->max_votes_per_voter.' vote'.($category->max_votes_per_voter > 1 ? 's' : '').' par personne'
                                    : 'Votes illimites' }}
                            </span>

                            @if ($category->hasOwnWindow())
                                <span>
                                    Du {{ $category->starts_at->timezone($tz)->translatedFormat('j M') }}
                                    au {{ $category->ends_at->timezone($tz)->translatedFormat('j M') }}
                                </span>
                            @endif
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <button type="button" wire:click="editCategory({{ $category->id }})"
                                class="btn btn-secondary btn-sm">
                            Modifier
                        </button>

                        <button type="button" wire:click="toggleCategory({{ $category->id }})"
                                class="btn btn-secondary btn-sm">
                            {{ $category->is_active ? 'Fermer' : 'Ouvrir' }}
                        </button>

                        @unless ($isPublished)
                            <button type="button" wire:click="deleteCategory({{ $category->id }})"
                                    class="btn btn-danger btn-sm">
                                Supprimer
                            </button>
                        @endunless
                    </div>
                </div>

                {{-- Candidats de la categorie --}}
                <div class="border-t border-grey-200 pt-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-display text-sm font-bold text-ink">
                            Candidats
                            <span class="font-normal text-grey-500">({{ $category->candidates->count() }})</span>
                        </h3>

                        <button type="button" wire:click="newCandidate({{ $category->id }})"
                                class="btn btn-secondary btn-sm">
                            <x-lucide-user-round-plus class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                            Ajouter
                        </button>
                    </div>

                    @if ($category->candidates->isEmpty())
                        <p class="mt-3 text-sm text-grey-500">
                            Aucun candidat. Il en faut au moins deux pour que la categorie puisse etre publiee.
                        </p>
                    @else
                        <ul class="mt-3 space-y-2">
                            @foreach ($category->candidates as $candidate)
                                <li wire:key="candidate-{{ $candidate->id }}"
                                    class="flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-md)] border border-grey-200 px-3 py-2.5">
                                    <div class="flex min-w-0 items-center gap-3">
                                        @if ($candidate->photoUrl())
                                            <img src="{{ $candidate->photoUrl() }}" alt=""
                                                 class="size-10 shrink-0 rounded-full object-cover">
                                        @else
                                            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-grey-200/70 text-grey-400">
                                                <x-lucide-user class="size-5" stroke-width="1.75" aria-hidden="true" />
                                            </span>
                                        @endif

                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-ink">
                                                {{ $candidate->displayName() }}
                                            </span>
                                            @unless ($candidate->is_active)
                                                {{-- Retire du vote, mais ses voix restent comptees :
                                                     les faire disparaitre fausserait le total. --}}
                                                <span class="block text-xs text-grey-500">
                                                    Retire du vote. Ses voix restent comptees.
                                                </span>
                                            @endunless
                                        </span>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
                                        <button type="button" wire:click="editCandidate({{ $candidate->id }})"
                                                class="btn btn-secondary btn-sm">
                                            Modifier
                                        </button>

                                        <button type="button" wire:click="toggleCandidate({{ $candidate->id }})"
                                                class="btn btn-secondary btn-sm">
                                            {{ $candidate->is_active ? 'Retirer' : 'Reactiver' }}
                                        </button>

                                        @unless ($isPublished)
                                            <button type="button" wire:click="deleteCandidate({{ $candidate->id }})"
                                                    class="btn btn-danger btn-sm">
                                                Supprimer
                                            </button>
                                        @endunless
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- Formulaire de candidat, ouvert sous SA categorie --}}
                    @if ($candidateCategoryId === $category->id)
                        <form wire:submit="saveCandidate"
                              class="mt-4 space-y-4 rounded-[var(--radius-md)] border border-azure-300 p-4">
                            <div class="grid gap-4 sm:grid-cols-[2fr_1fr]">
                                <x-ui.field label="Nom" for="cand-name"
                                            :error="$errors->first('candidateName')" required>
                                    <input id="cand-name" type="text" wire:model="candidateName" class="field"
                                           maxlength="150" required>
                                </x-ui.field>

                                <x-ui.field label="Numero" for="cand-number"
                                            :error="$errors->first('candidateNumber')"
                                            hint="Facultatif. « 7 », « A1 »...">
                                    <input id="cand-number" type="text" wire:model="candidateNumber"
                                           class="field" maxlength="20">
                                </x-ui.field>
                            </div>

                            <x-ui.field label="Biographie" for="cand-bio"
                                        :error="$errors->first('candidateBiography')">
                                <textarea id="cand-bio" wire:model="candidateBiography" rows="3"
                                          class="field" maxlength="2000"></textarea>
                            </x-ui.field>

                            <x-ui.field label="Photo" for="cand-photo"
                                        :error="$errors->first('candidatePhoto')"
                                        hint="JPG, PNG ou WEBP. 4 Mo maximum.">
                                <input id="cand-photo" type="file" wire:model="candidatePhoto" class="field"
                                       accept="image/jpeg,image/png,image/webp">
                            </x-ui.field>

                            <div class="flex flex-wrap gap-2.5">
                                <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
                                <button type="button" wire:click="cancelCandidate" class="btn btn-secondary btn-sm">
                                    Annuler
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </x-ui.panel>
    @empty
        <div class="surface">
            <x-ui.empty-state
                icon="list-checks"
                title="Aucune categorie"
                description="Une categorie regroupe des candidats : « Miss Libreville », « Meilleur Artiste », « Prix du Public ». Chacune a ses propres regles.">
                <x-slot:action>
                    <button type="button" wire:click="newCategory" class="btn btn-primary">
                        <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                        Nouvelle categorie
                    </button>
                </x-slot:action>
            </x-ui.empty-state>
        </div>
    @endforelse
</div>
