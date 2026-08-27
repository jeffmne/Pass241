@php
    $tz = config('app.display_timezone');
    $nbsp = "\u{202F}";
    $filtering = $statusFilter !== '' || trim($search) !== '';
    $money = fn (int $amount) => number_format($amount, 0, ',', $nbsp).' F';
@endphp

{{--
    Liste des collectes de l'organisation (sections 34, 56).

    Surfaces pleines : liste dense de donnees de travail, pas de verre
    (regle 70.4). Les chiffres affiches viennent tous du serveur — aucun n'est
    stocke ni saisissable (regle 80.1).
--}}
<div>
    <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-ink sm:text-3xl">Collectes</h1>
            <p class="mt-1 text-sm text-grey-500">
                Creez une collecte, publiez-la et partagez son lien.
            </p>
        </div>

        @if ($canManage)
            <a href="{{ route('campaigns.create') }}" wire:navigate class="btn btn-primary shrink-0">
                <x-lucide-plus class="size-4" stroke-width="2" />
                Nouvelle collecte
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
                       class="field pl-10" placeholder="Rechercher une collecte"
                       aria-label="Rechercher une collecte">
            </div>

            <select wire:model.live="statusFilter" class="field sm:w-56" aria-label="Filtrer par statut">
                <option value="">Tous les statuts</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @forelse ($campaigns as $campaign)
        @php $bar = $progress[$campaign->id]; @endphp

        {{-- Revelation en cascade, comme les listes de la console
             d'administration : l'ordre de lecture porte une information, les
             collectes arrivent donc dans cet ordre. --}}
        <article class="surface mb-3 p-4 transition-colors hover:border-azure-300 sm:p-5"
                 data-reveal data-reveal-step="{{ $loop->index % 5 }}">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="truncate font-display text-base font-bold text-ink">{{ $campaign->name }}</h2>
                        <x-status-badge :status="$campaign->displayStatus()" />
                    </div>

                    <p class="mt-1 truncate text-sm text-grey-500">
                        Au profit de {{ $campaign->beneficiary_label }}
                        @if ($campaign->ends_at)
                            — jusqu'au {{ $campaign->ends_at->timezone($tz)->translatedFormat('j F Y') }}
                        @endif
                    </p>

                    {{-- Progression : le chiffre que l'organisateur vient
                         chercher. Calcule, jamais stocke (regle 80.1). --}}
                    <div class="mt-3 max-w-md">
                        <div class="h-2 w-full overflow-hidden rounded-full bg-azure-100">
                            <div class="progress-fill h-full rounded-full bg-gradient-to-r from-azure-500 to-azure-600"
                                 style="--progress: {{ $bar['displayPercentage'] }}%"></div>
                        </div>
                        <p class="mt-1.5 text-sm text-grey-500 tabular">
                            <span class="font-semibold text-ink">{{ $money($bar['collected']) }}</span>
                            sur {{ $money($bar['goal']) }}
                            · {{ number_format($bar['displayPercentage'], 0, ',', $nbsp) }} %
                        </p>
                    </div>

                    @if ($campaign->isPublished())
                        <p class="mt-2 truncate text-xs text-azure-700">{{ $campaign->publicUrl() }}</p>
                    @endif
                </div>

                <div class="flex shrink-0 items-center gap-1.5">
                    @if ($campaign->status->hasPublicPage())
                        <a href="{{ $campaign->publicUrl() }}" target="_blank" rel="noopener"
                           class="btn btn-ghost"
                           aria-label="Ouvrir la page publique de {{ $campaign->name }}">
                            <x-lucide-external-link class="size-4" stroke-width="1.75" />
                            <span class="sm:hidden lg:inline">Voir</span>
                        </a>
                    @endif

                    @if ($canManage)
                        <a href="{{ route('campaigns.edit', $campaign) }}" wire:navigate class="btn btn-primary">
                            {{ $campaign->status->isEditable() ? 'Modifier' : 'Gerer' }}
                        </a>
                    @endif
                </div>
            </div>
        </article>
    @empty
        <div class="surface p-8 text-center sm:p-12">
            <x-lucide-hand-coins class="mx-auto size-8 text-azure-300" stroke-width="1.5" />

            @if ($filtering)
                <h2 class="mt-3 font-display text-lg font-bold text-ink">Aucun resultat</h2>
                <p class="mx-auto mt-1.5 max-w-sm text-sm text-grey-500">
                    Aucune collecte ne correspond a votre recherche.
                </p>
                <button type="button" wire:click="clearFilters" class="btn btn-ghost mt-5">
                    Effacer les filtres
                </button>
            @else
                <h2 class="mt-3 font-display text-lg font-bold text-ink">Votre premiere collecte</h2>
                <p class="mx-auto mt-1.5 max-w-sm text-sm leading-relaxed text-grey-500">
                    Indiquez un objectif, qui recevra les fonds, et une image.
                    Vous recevrez un lien unique a partager.
                </p>
                @if ($canManage)
                    <a href="{{ route('campaigns.create') }}" wire:navigate class="btn btn-primary mt-6">
                        <x-lucide-plus class="size-4" stroke-width="2" />
                        Creer une collecte
                    </a>
                @endif
            @endif
        </div>
    @endforelse

    @if ($campaigns->hasPages())
        <div class="mt-6">{{ $campaigns->links() }}</div>
    @endif
</div>
