@php
    $tz = config('app.display_timezone');
    $filtering = $statusFilter !== '' || trim($search) !== '';
@endphp

<div class="space-y-5">

    <x-ui.page-header
        title="Evenements"
        description="Creez, publiez et partagez le lien de vos evenements.">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('events.create') }}" wire:navigate class="btn btn-primary">
                    <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                    Nouvel evenement
                </a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if (session('notice'))
        {{-- `role="status"` : le message est annonce a un lecteur d'ecran sans
             lui voler le curseur. Un `role="alert"` interromprait la lecture en
             cours pour une simple confirmation. --}}
        <div role="status"
             class="flex items-center gap-2.5 rounded-[var(--radius-md)] border border-success/30 bg-success/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-check class="size-4 shrink-0 text-success" stroke-width="2" aria-hidden="true" />
            {{ session('notice') }}
        </div>
    @endif

    @if ($totalCount > 0)
        {{-- Barre de filtres : masquee tant qu'il n'y a rien a filtrer. Un
             organisateur qui debute n'a pas besoin d'un champ de recherche
             au-dessus de son unique evenement. --}}
        <div class="flex flex-col gap-2.5 sm:flex-row">
            <x-ui.search-input
                id="events-search"
                class="flex-1"
                label="Rechercher un evenement"
                placeholder="Rechercher un evenement"
                wire:model.live.debounce.300ms="search" />

            <div class="sm:w-56">
                <label for="events-status" class="sr-only">Filtrer par statut</label>
                <select id="events-status" wire:model.live="statusFilter" class="field">
                    <option value="">Tous les statuts</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    @endif

    {{-- Squelette pendant le filtrage.

         Il occupe la place des cartes attendues, donc rien ne saute quand la
         liste revient. `wire:loading.delay` evite de le montrer pour une
         reponse instantanee — un squelette qui clignote fait paraitre
         l'interface plus lente qu'elle ne l'est. --}}
    <div wire:loading.delay wire:target="search, statusFilter, gotoPage, previousPage, nextPage">
        <x-ui.skeleton variant="list" :rows="4" class="surface p-4 sm:p-5" />
    </div>

    <div wire:loading.delay.class="opacity-40"
         wire:target="search, statusFilter, gotoPage, previousPage, nextPage"
         class="space-y-3 transition-opacity">

        @forelse ($events as $event)
            @php $start = $event->starts_at->timezone($tz); @endphp

            {{-- Surface pleine et non du verre : c'est une liste dense de
                 donnees de travail (section 70.4). --}}
            <article wire:key="event-{{ $event->id }}"
                     class="surface p-4 transition-colors hover:border-azure-300 sm:p-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 gap-4">
                        {{-- La pastille de date remplace une affiche absente et
                             donne le repere le plus utile de la ligne. Masquee
                             sur telephone : la date complete est deja lisible
                             juste en dessous, la repeter volerait la largeur au
                             titre. --}}
                        <span class="hidden size-14 shrink-0 flex-col items-center justify-center rounded-[var(--radius-sm)] bg-azure-100 text-azure-700 sm:flex">
                            <span class="font-display text-lg leading-none font-extrabold tabular">{{ $start->format('d') }}</span>
                            <span class="mt-0.5 text-[10px] font-bold tracking-wide uppercase">{{ $start->translatedFormat('M') }}</span>
                        </span>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate font-display text-base font-bold text-ink">{{ $event->name }}</h2>
                                <x-status-badge :status="$event->status" />
                            </div>

                            <p class="mt-1 truncate text-sm text-grey-500 tabular">
                                {{ $start->translatedFormat('j F Y \a\ H\hi') }}
                                @if ($event->location)
                                    — {{ $event->location->shortAddress() }}
                                @endif
                            </p>

                            @if ($event->isPublished())
                                <p class="mt-1.5 truncate text-xs text-azure-700">{{ $event->publicUrl() }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        @if ($event->status->hasPublicPage())
                            <a href="{{ $event->publicUrl() }}" target="_blank" rel="noopener"
                               class="btn btn-secondary"
                               aria-label="Ouvrir la page publique de {{ $event->name }}">
                                <x-lucide-external-link class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                                <span>Voir</span>
                            </a>
                        @endif

                        @if ($canManage)
                            <a href="{{ route('events.edit', $event) }}" wire:navigate class="btn btn-primary">
                                {{ $event->status->isEditable() ? 'Modifier' : 'Gerer' }}
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
                        description="Aucun evenement ne correspond a votre recherche.">
                        <x-slot:action>
                            <button type="button" wire:click="clearFilters" class="btn btn-secondary">
                                Effacer les filtres
                            </button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="calendar-plus"
                        title="Votre premier evenement"
                        description="Renseignez la date, le lieu et l'affiche. Vous recevrez un lien unique a partager sur WhatsApp ou sur vos affiches.">
                        @if ($canManage)
                            <x-slot:action>
                                <a href="{{ route('events.create') }}" wire:navigate class="btn btn-primary">
                                    <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                                    Creer un evenement
                                </a>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                @endif
            </div>
        @endforelse
    </div>

    @if ($events->hasPages())
        <div>{{ $events->links() }}</div>
    @endif
</div>
