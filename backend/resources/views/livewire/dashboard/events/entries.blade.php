@php
    use App\Enums\ScanResult;
    use App\Support\Money;

    $tz = config('app.display_timezone');
@endphp

{{--
    Les entrees d'un evenement (sections 27.2, 30).

    Pendant la soiree, c'est l'ecran qu'on garde ouvert. Le lendemain, c'est
    celui qui repond aux questions posees apres coup : cette personne est-elle
    entree, pourquoi ce billet a-t-il ete refuse.

    EN LECTURE SEULE, definitivement. Le journal des scans est en ajout seul,
    protege par declencheur (section 68) : aucune correction, aucune
    suppression, et il n'y en aura jamais — un organisateur qui pourrait
    effacer une entree pourrait nier une presence.

    Les compteurs du haut sont en verre : on y lit une proportion d'un coup
    d'oeil, entre deux controles. Le journal, lui, reste une surface pleine —
    c'est du texte qu'on relit ligne a ligne, et le flou lui retirerait le
    contraste dont cette lecture depend (section 70.4).
--}}
<div class="space-y-5">

    <x-ui.page-header
        title="Entrees"
        description="Chaque controle est enregistre, accepte comme refuse."
        :eyebrow="$event->name"
        :back="route('events.access-control', $event)"
        back-label="Controle d'acces" />

    {{-- « Entres » et « attendus » d'abord : ce sont les deux nombres qu'on
         regarde en levant les yeux pendant la soiree. --}}
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Chiffres des entrees">
        <x-ui.stat-card
            icon="door-open"
            label="Entres"
            tone="success"
            :value="Money::number($stats['admitted'])"
            :hint="'sur ' . Money::number($stats['expected']) . ' billet' . ($stats['expected'] > 1 ? 's' : '') . ' attendu' . ($stats['expected'] > 1 ? 's' : '')" />

        <x-ui.stat-card
            icon="percent"
            label="Presence"
            :value="Money::number($stats['attendanceRate'], 1) . ' %'"
            :hint="Money::number($stats['remaining']) . ' encore attendu' . ($stats['remaining'] > 1 ? 's' : '')" />

        <x-ui.stat-card
            icon="scan-line"
            label="Controles"
            :value="Money::number($stats['scanned'])"
            :hint="'dont ' . Money::number($stats['refused']) . ' refus'"
            :tone="$stats['refused'] > 0 ? 'danger' : 'primary'" />

        {{-- L'admission manuelle est legitime — un QR illisible arrive — mais
             sa verification est plus faible qu'un scan signe. La compter est la
             seule facon de s'en apercevoir. --}}
        <x-ui.stat-card
            icon="keyboard"
            label="Sans lecture du QR"
            :tone="$stats['manual'] > 0 ? 'warning' : 'neutral'"
            :value="Money::number($stats['manual'])"
            :hint="$stats['manual'] === 0
                ? 'Toutes les entrees ont ete scannees'
                : 'Accordees a la reference du billet'" />
    </section>

    @if ($stats['conflicts'] > 0)
        {{-- Un scan declasse par arbitrage : soit une horloge d'appareil
             deregle, soit une tentative de passer a deux avec le meme billet.
             Les deux meritent d'etre vues (section 31.1). --}}
        <p class="flex items-start gap-2 rounded-[var(--radius-md)] border border-warning/30 bg-warning/5 px-4 py-3 text-sm text-ink">
            <x-lucide-git-merge class="mt-0.5 size-4 shrink-0 text-warning" stroke-width="2" aria-hidden="true" />
            <span>
                {{ Money::number($stats['conflicts']) }} controle{{ $stats['conflicts'] > 1 ? 's ont ete arbitres' : ' a ete arbitre' }}
                apres un double scan : le passage le plus ancien l'emporte, quel que soit
                l'ordre d'arrivee sur le reseau.
            </span>
        </p>
    @endif

    <x-ui.panel
        title="Journal des controles"
        :description="Money::number($scans->total()) . ' ligne' . ($scans->total() > 1 ? 's' : '') . '. En ajout seul, jamais modifiable.'">

        <x-slot:actions>
            <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                @if ($entryPoints->isNotEmpty())
                    <div class="sm:w-44">
                        <label for="entries-point" class="sr-only">Filtrer par porte</label>
                        <select id="entries-point" wire:model.live="entryPoint" class="field">
                            <option value="">Toutes les portes</option>
                            @foreach ($entryPoints as $point)
                                <option value="{{ $point->id }}">{{ $point->name }}</option>
                            @endforeach
                            <option value="none">Sans porte declaree</option>
                        </select>
                    </div>
                @endif

                <div class="sm:w-48">
                    <label for="entries-result" class="sr-only">Filtrer par resultat</label>
                    <select id="entries-result" wire:model.live="result" class="field">
                        <option value="">Tous les resultats</option>
                        @foreach ($stats['byResult'] as $code => $total)
                            <option value="{{ $code }}">
                                {{ ScanResult::from($code)->label() }} ({{ Money::number($total) }})
                            </option>
                        @endforeach
                        @if ($stats['manual'] > 0)
                            <option value="manual">Sans lecture du QR ({{ Money::number($stats['manual']) }})</option>
                        @endif
                    </select>
                </div>

                <x-ui.search-input
                    id="entries-search"
                    class="sm:w-56"
                    label="Rechercher un controle"
                    placeholder="Nom ou reference"
                    wire:model.live.debounce.400ms="search" />
            </div>
        </x-slot:actions>

        <div wire:loading.delay wire:target="search, result, entryPoint">
            <x-ui.skeleton variant="list" :rows="5" />
        </div>

        <div wire:loading.delay.class="opacity-40" wire:target="search, result, entryPoint"
             class="space-y-2 transition-opacity">
            @forelse ($scans as $scan)
                @php $accepted = $scan->allowedEntry(); @endphp

                <div wire:key="scan-{{ $scan->id }}"
                     @class([
                        'rounded-[var(--radius-md)] border p-3.5 sm:p-4',
                        'border-grey-200 bg-surface' => $accepted,
                        // Un refus se repere en parcourant vite : c'est la ligne
                        // qu'on cherche le lendemain.
                        'border-danger/20 bg-danger/5' => ! $accepted,
                     ])>
                    <div class="flex items-start gap-3">
                        <span @class([
                            'flex size-8 shrink-0 items-center justify-center rounded-full text-white',
                            'bg-success' => $accepted,
                            'bg-danger' => ! $accepted,
                        ])>
                            <x-dynamic-component :component="$accepted ? 'lucide-check' : 'lucide-x'"
                                                 class="size-4" stroke-width="2.5" aria-hidden="true" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <p class="font-semibold text-ink">
                                    {{-- Un QR non reconnu n'a pas de billet : il n'y a
                                         personne a nommer, et inventer un libelle
                                         laisserait croire le contraire. --}}
                                    {{ $scan->ticket?->holder_name ?? 'Billet inconnu' }}
                                </p>
                                <p class="text-xs text-grey-400 tabular">
                                    {{ $scan->scanned_at?->timezone($tz)->translatedFormat('j F \a\ H\hi\m\i\n\s') }}
                                </p>
                            </div>

                            <p class="mt-0.5 text-sm text-grey-500">
                                {{ $scan->result->label() }}
                                @if ($scan->ticket?->reference)
                                    · <span class="tabular">{{ $scan->ticket->reference }}</span>
                                @endif
                                @if ($scan->ticket?->orderItem?->ticket_type_name)
                                    · {{ $scan->ticket->orderItem->ticket_type_name }}
                                @endif
                            </p>

                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-grey-400">
                                <span class="inline-flex items-center gap-1">
                                    <x-lucide-door-open class="size-3.5 shrink-0" stroke-width="2" aria-hidden="true" />
                                    {{ $scan->entryPoint?->name ?? 'Entree unique' }}
                                </span>

                                @if ($scan->agent)
                                    <span class="inline-flex items-center gap-1">
                                        <x-lucide-user class="size-3.5 shrink-0" stroke-width="2" aria-hidden="true" />
                                        {{ $scan->agent->name }}
                                    </span>
                                @endif

                                @if ($scan->manual)
                                    {{-- Marque et non cachee : c'est la seule trace
                                         permettant de savoir comment quelqu'un est
                                         entre quand le QR n'a pas ete lu. --}}
                                    <x-ui.badge tone="warning" bare>
                                        <x-lucide-keyboard class="size-3.5 shrink-0" stroke-width="2" aria-hidden="true" />
                                        Sans lecture du QR
                                    </x-ui.badge>
                                @endif

                                @if ($scan->conflict_with)
                                    <span class="inline-flex items-center gap-1">
                                        <x-lucide-git-merge class="size-3.5 shrink-0" stroke-width="2" aria-hidden="true" />
                                        Double scan arbitre
                                    </span>
                                @endif

                                @if ($scan->hasSuspiciousClock())
                                    {{-- L'arbitrage du double scan repose sur
                                         l'horloge de l'appareil (section 31.1) : un
                                         ecart anormal ne bloque rien, mais permet de
                                         le demontrer apres coup. --}}
                                    <span class="inline-flex items-center gap-1 text-warning">
                                        <x-lucide-clock-alert class="size-3.5 shrink-0" stroke-width="2" aria-hidden="true" />
                                        Horloge deregle
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                @if ($stats['scanned'] === 0)
                    <x-ui.empty-state
                        icon="scan-line"
                        title="Aucun controle pour l'instant"
                        description="Les entrees s'afficheront ici des que vos agents commenceront a scanner, y compris celles enregistrees hors connexion." />
                @else
                    <x-ui.empty-state
                        icon="search-x"
                        tone="filtered"
                        title="Aucun resultat"
                        description="Aucun controle ne correspond a ce filtre.">
                        <x-slot:action>
                            <button type="button" wire:click="resetFilters" class="btn btn-secondary">
                                Effacer les filtres
                            </button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @endif
            @endforelse
        </div>

        @if ($scans->hasPages())
            <div class="mt-5">{{ $scans->links() }}</div>
        @endif
    </x-ui.panel>
</div>
