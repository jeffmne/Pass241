@php
    $tz = config('app.display_timezone');
    $filtering = $statusFilter !== '' || trim($search) !== '';
@endphp

<div>
    <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-ink sm:text-3xl">Evenements</h1>
            <p class="mt-1 text-sm text-grey-500">
                Creez, publiez et partagez le lien de vos evenements.
            </p>
        </div>

        @if ($canManage)
            <a href="{{ route('events.create') }}" wire:navigate class="btn btn-primary shrink-0">
                <x-lucide-plus class="size-4" stroke-width="2" />
                Nouvel evenement
            </a>
        @endif
    </header>

    @if (session('notice'))
        <div class="mb-5 flex items-center gap-2.5 rounded-xl border border-success/30 bg-success/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-check class="size-4 shrink-0 text-success" stroke-width="1.75" />
            {{ session('notice') }}
        </div>
    @endif

    @if ($totalCount > 0)
        {{-- Filtres : masques tant qu'il n'y a rien a filtrer, pour ne pas
             encombrer l'ecran d'un organisateur qui debute. --}}
        <div class="mb-5 flex flex-col gap-3 sm:flex-row">
            <div class="relative flex-1">
                <x-lucide-search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-grey-400" stroke-width="1.75" />
                <input type="search" wire:model.live.debounce.300ms="search"
                       class="field pl-10" placeholder="Rechercher un evenement"
                       aria-label="Rechercher un evenement">
            </div>

            <select wire:model.live="statusFilter" class="field sm:w-56" aria-label="Filtrer par statut">
                <option value="">Tous les statuts</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @forelse ($events as $event)
        @php $start = $event->starts_at->timezone($tz); @endphp

        {{-- Surface pleine et non du verre : c'est une liste dense de donnees
             de travail (section 70.4). --}}
        <article class="surface mb-3 p-4 transition-colors hover:border-azure-300 sm:p-5">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 gap-4">
                    <div class="hidden size-14 shrink-0 flex-col items-center justify-center rounded-xl bg-azure-100 text-azure-700 sm:flex">
                        <span class="font-display text-lg leading-none font-extrabold tabular">{{ $start->format('d') }}</span>
                        <span class="mt-0.5 text-[10px] font-semibold tracking-wide uppercase">{{ $start->translatedFormat('M') }}</span>
                    </div>

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

                <div class="flex shrink-0 items-center gap-1.5">
                    @if ($event->status->hasPublicPage())
                        <a href="{{ $event->publicUrl() }}" target="_blank" rel="noopener"
                           class="btn btn-ghost"
                           aria-label="Ouvrir la page publique de {{ $event->name }}">
                            <x-lucide-external-link class="size-4" stroke-width="1.75" />
                            <span class="sm:hidden lg:inline">Voir</span>
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
        <div class="surface p-8 text-center sm:p-12">
            <x-lucide-calendar-plus class="mx-auto size-8 text-azure-300" stroke-width="1.5" />

            @if ($filtering)
                <h2 class="mt-3 font-display text-lg font-bold text-ink">Aucun resultat</h2>
                <p class="mx-auto mt-1.5 max-w-sm text-sm text-grey-500">
                    Aucun evenement ne correspond a votre recherche.
                </p>
                <button type="button" wire:click="clearFilters" class="btn btn-ghost mt-5">
                    Effacer les filtres
                </button>
            @else
                <h2 class="mt-3 font-display text-lg font-bold text-ink">Votre premier evenement</h2>
                <p class="mx-auto mt-1.5 max-w-sm text-sm leading-relaxed text-grey-500">
                    Renseignez la date, le lieu et l'affiche. Vous recevrez un lien
                    unique a partager sur WhatsApp ou sur vos affiches.
                </p>
                @if ($canManage)
                    <a href="{{ route('events.create') }}" wire:navigate class="btn btn-primary mt-6">
                        <x-lucide-plus class="size-4" stroke-width="2" />
                        Creer un evenement
                    </a>
                @endif
            @endif
        </div>
    @endforelse

    @if ($events->hasPages())
        <div class="mt-6">{{ $events->links() }}</div>
    @endif
</div>
