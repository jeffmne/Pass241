@php
    $tz = config('app.display_timezone');
    $filtering = $statusFilter !== '' || trim($search) !== '';
@endphp

{{--
    Liste des votes de l'organisation (sections 86.4, 87).

    Surfaces pleines : liste dense de donnees de travail, pas de verre
    (regle 70.4). Aucun total de votes n'apparait ici — il n'existe en colonne
    nulle part, et l'ecran des resultats (jalon 9.5) est l'endroit ou on vient
    le chercher.
--}}
<div class="space-y-5">

    <x-ui.page-header
        title="Votes"
        description="Creez un vote, ajoutez vos categories et vos candidats, puis partagez son lien.">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('polls.create') }}" wire:navigate class="btn btn-primary">
                    <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                    Nouveau vote
                </a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if (session('notice'))
        <div role="status"
             class="flex items-center gap-2.5 rounded-[var(--radius-md)] border border-success/30 bg-success/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-check class="size-4 shrink-0 text-success" stroke-width="2" aria-hidden="true" />
            {{ session('notice') }}
        </div>
    @endif

    @if ($totalCount > 0)
        {{-- Filtres : masques tant qu'il n'y a rien a filtrer, pour ne pas
             encombrer l'ecran d'un organisateur qui debute. --}}
        <div class="flex flex-col gap-2.5 sm:flex-row">
            <x-ui.search-input
                id="polls-search"
                class="flex-1"
                label="Rechercher un vote"
                placeholder="Rechercher un vote"
                wire:model.live.debounce.300ms="search" />

            <div class="sm:w-56">
                <label for="polls-status" class="sr-only">Filtrer par statut</label>
                <select id="polls-status" wire:model.live="statusFilter" class="field">
                    <option value="">Tous les statuts</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    @endif

    <div wire:loading.delay wire:target="search, statusFilter, gotoPage, previousPage, nextPage">
        <x-ui.skeleton variant="list" :rows="3" class="surface p-4 sm:p-5" />
    </div>

    <div wire:loading.delay.class="opacity-40"
         wire:target="search, statusFilter, gotoPage, previousPage, nextPage"
         class="space-y-3 transition-opacity">

        @forelse ($polls as $poll)
            <article wire:key="poll-{{ $poll->id }}"
                     class="surface p-4 transition-colors hover:border-azure-300 sm:p-5"
                     data-reveal data-reveal-step="{{ $loop->index % 5 }}">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="truncate font-display text-base font-bold text-ink">{{ $poll->name }}</h2>
                            <x-status-badge :status="$poll->displayStatus()" />
                        </div>

                        <p class="mt-1 truncate text-sm text-grey-500">
                            Du {{ $poll->starts_at->timezone($tz)->translatedFormat('j F Y') }}
                            au {{ $poll->ends_at->timezone($tz)->translatedFormat('j F Y') }}
                        </p>

                        <p class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-grey-500">
                            <span class="inline-flex items-center gap-1.5">
                                <x-lucide-list-checks class="size-4 shrink-0 text-grey-400" stroke-width="1.75" aria-hidden="true" />
                                {{ $poll->categories_count }}
                                {{ $poll->categories_count > 1 ? 'categories' : 'categorie' }}
                            </span>

                            <span class="inline-flex items-center gap-1.5">
                                <x-lucide-eye class="size-4 shrink-0 text-grey-400" stroke-width="1.75" aria-hidden="true" />
                                {{ $poll->results_visibility->label() }}
                            </span>
                        </p>

                        {{-- Le lien public, sur la ligne meme du scrutin : c'est
                             ce que l'organisateur vient chercher pour le coller
                             dans un message. Meme place que sur la liste des
                             evenements et celle des collectes. --}}
                        @if ($poll->isPublished())
                            <p class="mt-2 truncate text-xs text-azure-700">{{ $poll->publicUrl() }}</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        @if ($poll->status->hasPublicPage())
                            <a href="{{ $poll->publicUrl() }}" target="_blank" rel="noopener"
                               class="btn btn-icon"
                               aria-label="Ouvrir la page publique de {{ $poll->name }}">
                                <x-lucide-external-link class="size-4" stroke-width="2" aria-hidden="true" />
                            </a>
                        @endif

                        {{-- Les resultats des que le vote a commence : avant
                             l'ouverture il n'y a rien a lire, et l'entree
                             n'aurait qu'a decevoir. --}}
                        @if ($poll->hasStarted())
                            <a href="{{ route('polls.results', $poll) }}" wire:navigate class="btn btn-secondary">
                                Resultats
                            </a>
                        @endif

                        @if ($canManage)
                            <a href="{{ route('polls.categories', $poll) }}" wire:navigate class="btn btn-secondary">
                                Categories
                            </a>

                            <a href="{{ route('polls.edit', $poll) }}" wire:navigate class="btn btn-primary">
                                {{ $poll->status->isEditable() ? 'Modifier' : 'Gerer' }}
                            </a>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="surface">
                @if ($filtering)
                    <x-ui.empty-state
                        icon="search-x"
                        tone="filtered"
                        title="Aucun resultat"
                        description="Aucun vote ne correspond a votre recherche.">
                        <x-slot:action>
                            <button type="button" wire:click="clearFilters" class="btn btn-secondary">
                                Effacer les filtres
                            </button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="vote"
                        title="Aucun vote pour l'instant"
                        description="Un concours, une election, un prix du public : creez votre premier vote, ajoutez vos candidats et partagez le lien.">
                        @if ($canManage)
                            <x-slot:action>
                                <a href="{{ route('polls.create') }}" wire:navigate class="btn btn-primary">
                                    <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                                    Nouveau vote
                                </a>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                @endif
            </div>
        @endforelse
    </div>

    @if ($polls->hasPages())
        <div>{{ $polls->links() }}</div>
    @endif
</div>
