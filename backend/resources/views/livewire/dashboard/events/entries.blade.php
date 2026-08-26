@php
    use App\Enums\ScanResult;

    $tz = config('app.display_timezone');
    $nbsp = "\u{202F}";
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

    Surfaces pleines et non du verre : c'est un tableau dense, et la regle 70.4
    y interdit le verre. La lisibilite prime sur l'expression.
--}}
<div>
    <header class="mb-6">
        <a href="{{ route('events.access-control', $event) }}" wire:navigate
           class="inline-flex items-center gap-1.5 text-sm font-medium text-grey-500 transition-colors hover:text-azure-700">
            <x-lucide-arrow-left class="size-4" stroke-width="1.75" />
            Controle d'acces
        </a>

        <h1 class="mt-2 font-display text-2xl font-extrabold text-ink sm:text-3xl">Entrees</h1>
        <p class="mt-1 text-sm text-grey-500">
            {{ $event->name }} — chaque controle est enregistre, accepte comme refuse.
        </p>
    </header>

    {{-- ----------------------------------------------------------------
         Compteurs.

         « Entres » et « attendus » d'abord : ce sont les deux nombres qu'on
         regarde en levant les yeux pendant la soiree.
    ----------------------------------------------------------------- --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-door-open class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Entres</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                {{ number_format($stats['admitted'], 0, ',', $nbsp) }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500 tabular">
                sur {{ number_format($stats['expected'], 0, ',', $nbsp) }} billet{{ $stats['expected'] > 1 ? 's' : '' }} attendu{{ $stats['expected'] > 1 ? 's' : '' }}
            </p>
        </div>

        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-percent class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Presence</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                {{ number_format($stats['attendanceRate'], 1, ',', ' ') }} %
            </p>
            <p class="mt-0.5 text-sm text-grey-500 tabular">
                {{ number_format($stats['remaining'], 0, ',', $nbsp) }} encore attendu{{ $stats['remaining'] > 1 ? 's' : '' }}
            </p>
        </div>

        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-scan-line class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Controles</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                {{ number_format($stats['scanned'], 0, ',', $nbsp) }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500 tabular">
                dont {{ number_format($stats['refused'], 0, ',', $nbsp) }} refus
            </p>
        </div>

        {{-- L'admission manuelle est legitime — un QR illisible arrive — mais
             sa verification est plus faible qu'un scan signe. La compter est la
             seule facon de s'en apercevoir. --}}
        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-keyboard class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Sans lecture du QR</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                {{ number_format($stats['manual'], 0, ',', $nbsp) }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500">
                @if ($stats['manual'] === 0)
                    Toutes les entrees ont ete scannees
                @else
                    Accordees a la reference du billet
                @endif
            </p>
        </div>
    </div>

    @if ($stats['conflicts'] > 0)
        {{-- Un scan declasse par arbitrage : soit une horloge d'appareil
             deregle, soit une tentative de passer a deux avec le meme billet.
             Les deux meritent d'etre vues (section 31.1). --}}
        <p class="mt-3 flex items-start gap-2 rounded-2xl border border-warning/30 bg-warning/5 px-4 py-3 text-sm text-ink">
            <x-lucide-git-merge class="mt-0.5 size-4 shrink-0 text-warning" stroke-width="1.75" />
            <span>
                {{ number_format($stats['conflicts'], 0, ',', $nbsp) }} controle{{ $stats['conflicts'] > 1 ? 's ont ete arbitres' : ' a ete arbitre' }}
                apres un double scan : le passage le plus ancien l'emporte, quel que soit
                l'ordre d'arrivee sur le reseau.
            </span>
        </p>
    @endif

    {{-- ----------------------------------------------------------------
         Journal
    ----------------------------------------------------------------- --}}
    <section class="surface mt-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Journal des controles</h2>
                <p class="mt-1 text-sm text-grey-500">
                    {{ number_format($scans->total(), 0, ',', $nbsp) }} ligne{{ $scans->total() > 1 ? 's' : '' }}.
                    En ajout seul, jamais modifiable.
                </p>
            </div>

            <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                @if ($entryPoints->isNotEmpty())
                    <select wire:model.live="entryPoint" class="field sm:w-44" aria-label="Filtrer par porte">
                        <option value="">Toutes les portes</option>
                        @foreach ($entryPoints as $point)
                            <option value="{{ $point->id }}">{{ $point->name }}</option>
                        @endforeach
                        <option value="none">Sans porte declaree</option>
                    </select>
                @endif

                <select wire:model.live="result" class="field sm:w-48" aria-label="Filtrer par resultat">
                    <option value="">Tous les resultats</option>
                    @foreach ($stats['byResult'] as $code => $total)
                        <option value="{{ $code }}">
                            {{ ScanResult::from($code)->label() }} ({{ number_format($total, 0, ',', $nbsp) }})
                        </option>
                    @endforeach
                    @if ($stats['manual'] > 0)
                        <option value="manual">Sans lecture du QR ({{ number_format($stats['manual'], 0, ',', $nbsp) }})</option>
                    @endif
                </select>

                <div class="relative sm:w-56">
                    <x-lucide-search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-grey-400"
                                     stroke-width="1.75" />
                    <input type="search" wire:model.live.debounce.400ms="search" class="field pl-10"
                           placeholder="Nom ou reference" aria-label="Rechercher un controle">
                </div>
            </div>
        </div>

        <div class="mt-5 space-y-2">
            @forelse ($scans as $scan)
                @php
                    $accepted = $scan->allowedEntry();
                @endphp

                <div @class([
                        'rounded-2xl border p-3.5 sm:p-4',
                        'border-grey-200 bg-white' => $accepted,
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
                                                 class="size-4" stroke-width="2.5" />
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
                                    <x-lucide-door-open class="size-3.5" stroke-width="1.75" />
                                    {{ $scan->entryPoint?->name ?? 'Entree unique' }}
                                </span>

                                @if ($scan->agent)
                                    <span class="inline-flex items-center gap-1">
                                        <x-lucide-user class="size-3.5" stroke-width="1.75" />
                                        {{ $scan->agent->name }}
                                    </span>
                                @endif

                                @if ($scan->manual)
                                    {{-- Marque et non cachee : c'est la seule trace
                                         permettant de savoir comment quelqu'un est
                                         entre quand le QR n'a pas ete lu. --}}
                                    <span class="inline-flex items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 font-medium text-warning">
                                        <x-lucide-keyboard class="size-3.5" stroke-width="1.75" />
                                        Sans lecture du QR
                                    </span>
                                @endif

                                @if ($scan->conflict_with)
                                    <span class="inline-flex items-center gap-1">
                                        <x-lucide-git-merge class="size-3.5" stroke-width="1.75" />
                                        Double scan arbitre
                                    </span>
                                @endif

                                @if ($scan->hasSuspiciousClock())
                                    {{-- L'arbitrage du double scan repose sur
                                         l'horloge de l'appareil (section 31.1) : un
                                         ecart anormal ne bloque rien, mais permet de
                                         le demontrer apres coup. --}}
                                    <span class="inline-flex items-center gap-1 text-warning">
                                        <x-lucide-clock-alert class="size-3.5" stroke-width="1.75" />
                                        Horloge deregle
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-10 text-center">
                    @if ($stats['scanned'] === 0)
                        <x-lucide-scan-line class="mx-auto size-8 text-grey-300" stroke-width="1.5" />
                        <p class="mt-3 font-semibold text-ink">Aucun controle pour l'instant</p>
                        <p class="mx-auto mt-1 max-w-sm text-sm text-grey-500">
                            Les entrees s'afficheront ici des que vos agents commenceront
                            a scanner, y compris celles enregistrees hors connexion.
                        </p>
                    @else
                        <p class="text-sm text-grey-500">Aucun controle ne correspond a ce filtre.</p>
                        <button type="button" wire:click="resetFilters" class="btn btn-ghost btn-pill mt-3">
                            Effacer les filtres
                        </button>
                    @endif
                </div>
            @endforelse
        </div>

        @if ($scans->hasPages())
            <div class="mt-6">{{ $scans->links() }}</div>
        @endif
    </section>
</div>
