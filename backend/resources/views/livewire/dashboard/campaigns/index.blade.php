@php
    use App\Enums\PermissionName;
    use App\Support\Money;

    $tz = config('app.display_timezone');
    $filtering = $statusFilter !== '' || trim($search) !== '';
    $money = fn (int $amount) => Money::formatShort($amount);
@endphp

{{--
    Liste des collectes de l'organisation (sections 34, 56).

    Surfaces pleines : liste dense de donnees de travail, pas de verre
    (regle 70.4). Les chiffres affiches viennent tous du serveur — aucun n'est
    stocke ni saisissable (regle 80.1).
--}}
<div class="space-y-5">

    <x-ui.page-header
        title="Collectes"
        description="Creez une collecte, publiez-la et partagez son lien.">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('campaigns.create') }}" wire:navigate class="btn btn-primary">
                    <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                    Nouvelle collecte
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
                id="campaigns-search"
                class="flex-1"
                label="Rechercher une collecte"
                placeholder="Rechercher une collecte"
                wire:model.live.debounce.300ms="search" />

            <div class="sm:w-56">
                <label for="campaigns-status" class="sr-only">Filtrer par statut</label>
                <select id="campaigns-status" wire:model.live="statusFilter" class="field">
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

        @forelse ($campaigns as $campaign)
            @php
                $bar = $progress[$campaign->id];

                /*
                 * La teinte de la barre suit l'avancee, exactement comme sur la
                 * billetterie : c'est le meme geste de lecture, il doit donner
                 * la meme information. Le pourcentage chiffre reste a cote —
                 * la couleur seule ne dit rien a un daltonien.
                 */
                $tone = match (true) {
                    $bar['displayPercentage'] >= 100 => 'success',
                    $bar['displayPercentage'] >= 50 => 'primary',
                    default => 'warning',
                };
            @endphp

            {{-- Revelation en cascade, comme les listes de la console
                 d'administration : l'ordre de lecture porte une information, les
                 collectes arrivent donc dans cet ordre. --}}
            <article wire:key="campaign-{{ $campaign->id }}"
                     class="surface p-4 transition-colors hover:border-azure-300 sm:p-5"
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
                            <div class="progress-track">
                                <div @class([
                                        'progress-fill',
                                        'progress-fill-success' => $tone === 'success',
                                        'progress-fill-warning' => $tone === 'warning',
                                     ])
                                     style="--progress: {{ $bar['displayPercentage'] }}%"></div>
                            </div>
                            <p class="mt-1.5 text-sm text-grey-500 tabular">
                                <span class="font-display font-bold text-ink">{{ $money($bar['collected']) }}</span>
                                sur {{ $money($bar['goal']) }}
                                · {{ Money::number($bar['displayPercentage']) }} %
                            </p>
                        </div>

                        @if ($campaign->isPublished())
                            <p class="mt-2 truncate text-xs text-azure-700">{{ $campaign->publicUrl() }}</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        @if ($campaign->status->hasPublicPage())
                            <a href="{{ $campaign->publicUrl() }}" target="_blank" rel="noopener"
                               class="btn btn-secondary"
                               aria-label="Ouvrir la page publique de {{ $campaign->name }}">
                                <x-lucide-external-link class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                                <span>Voir</span>
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
            <div class="surface">
                @if ($filtering)
                    <x-ui.empty-state
                        icon="search-x"
                        tone="filtered"
                        title="Aucun resultat"
                        description="Aucune collecte ne correspond a votre recherche.">
                        <x-slot:action>
                            <button type="button" wire:click="clearFilters" class="btn btn-secondary">
                                Effacer les filtres
                            </button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="hand-coins"
                        title="Votre premiere collecte"
                        description="Indiquez un objectif, qui recevra les fonds, et une image. Vous recevrez un lien unique a partager.">
                        @if ($canManage)
                            <x-slot:action>
                                <a href="{{ route('campaigns.create') }}" wire:navigate class="btn btn-primary">
                                    <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                                    Creer une collecte
                                </a>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                @endif
            </div>
        @endforelse
    </div>

    @if ($campaigns->hasPages())
        <div>{{ $campaigns->links() }}</div>
    @endif

    {{-- Export de toutes les collectes (sections 57, 59). Les chiffres viennent
         du meme calcul que la page publique : le fichier ne peut pas la
         contredire. --}}
    @if ($totalCount > 0)
        @can(PermissionName::ExportsGenerate->value)
            <x-ui.panel>
                <x-export-buttons
                    label="Exporter les collectes"
                    hint="Objectif, collecte, progression et nombre de dons."
                    :csv="route('exports.campaigns', 'csv')"
                    :xlsx="route('exports.campaigns', 'xlsx')" />
            </x-ui.panel>
        @endcan
    @endif
</div>
