@php
    use App\Support\Money;

    $needsAttention = $attention['settlements'] > 0 || $attention['refunds'] > 0;
@endphp

{{--
    Vue generale de la plateforme (section 58).

    Ecran de surveillance, pas tableau de bord commercial : ce qui ne va pas
    passe AVANT les volumes. Un administrateur ouvre cette page pour verifier
    que rien ne cloche, pas pour contempler une courbe.
--}}
<div>
    <header class="mb-6">
        <h1 class="font-display text-2xl font-extrabold text-ink sm:text-3xl">Vue generale</h1>
        <p class="mt-1 text-sm text-grey-500">
            Toutes organisations confondues.
        </p>
    </header>

    {{-- Ce qui demande une intervention, en premier. Non cache : un
         reversement en echec doit apparaitre a l'instant ou il echoue. --}}
    @if ($needsAttention)
        <div class="mb-6 flex flex-col gap-3 rounded-2xl border border-danger/30 bg-danger/5 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex gap-3">
                <x-lucide-circle-alert class="mt-0.5 size-5 shrink-0 text-danger" stroke-width="1.5" />
                <div class="text-sm">
                    <p class="font-semibold text-ink">A traiter</p>
                    <p class="mt-1 text-grey-500">
                        @if ($attention['settlements'] > 0)
                            {{ $attention['settlements'] }} reversement{{ $attention['settlements'] > 1 ? 's' : '' }}
                            en echec ou en etat inconnu.
                        @endif
                        @if ($attention['refunds'] > 0)
                            {{ $attention['refunds'] }} demande{{ $attention['refunds'] > 1 ? 's' : '' }}
                            de remboursement en attente de decision d'un organisateur.
                        @endif
                    </p>
                </div>
            </div>

            @if ($attention['settlements'] > 0)
                <a href="{{ route('admin.settlements') }}" class="btn btn-primary shrink-0 sm:w-auto">
                    Voir les reversements
                </a>
            @endif
        </div>
    @endif

    {{-- Argent. La commission est le SEUL revenu de Pass241 : le volume brut
         transite sans lui appartenir (section 21.2), et confondre les deux
         donnerait un chiffre d'affaires imaginaire. --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="glass-stat glass-specular p-4" data-reveal data-reveal-step="0">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-coins class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Commission Pass241</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-azure-700 tabular">
                {{ Money::format($totals['commission']) }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500">Revenu de la plateforme</p>
        </div>

        <div class="glass-stat glass-specular p-4" data-reveal data-reveal-step="1">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-arrow-left-right class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Volume encaisse</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                {{ Money::format($totals['grossVolume']) }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500">Transite, n'appartient pas a Pass241</p>
        </div>

        <div class="glass-stat glass-specular p-4" data-reveal data-reveal-step="2">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-wallet class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Reverse</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-success tabular">
                {{ Money::format($totals['settledNet']) }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500">Virements aboutis</p>
        </div>

        <div class="glass-stat glass-specular p-4" data-reveal data-reveal-step="3">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-undo-2 class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Rembourse</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                {{ Money::format($totals['refunded']) }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500">Rendu aux acheteurs</p>
        </div>
    </div>

    {{-- Volumes --}}
    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['building-2', 'Organisations', $totals['organizations'], $totals['activeOrganizations'].' actives'],
            ['users', 'Utilisateurs', $totals['users'], null],
            ['calendar-days', 'Evenements', $totals['events'], $totals['publishedEvents'].' publies'],
            ['ticket', 'Billets vendus', $totals['ticketsSold'], $totals['ordersPaid'].' commandes'],
        ] as [$icon, $label, $value, $note])
            <div class="surface p-4" data-reveal data-reveal-step="{{ $loop->index }}">
                <div class="flex items-center gap-2 text-grey-500">
                    <x-dynamic-component :component="'lucide-'.$icon" class="size-4" stroke-width="1.5" />
                    <span class="text-xs font-semibold tracking-wide uppercase">{{ $label }}</span>
                </div>
                <p class="mt-2 font-display text-xl font-extrabold text-ink tabular">
                    {{ number_format($value, 0, ',', "\u{202F}") }}
                </p>
                @if ($note)
                    <p class="mt-0.5 text-sm text-grey-500 tabular">{{ $note }}</p>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Derniere activite. Dix lignes se lisent d'un coup d'oeil ; cinquante
         demandent un effort et ne seraient plus regardees. --}}
    <div class="surface mt-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-lg font-bold text-ink">Derniere activite</h2>
            <a href="{{ route('admin.audit') }}" class="text-sm font-medium text-azure-600 hover:text-azure-700">
                Voir le journal
            </a>
        </div>

        <ul class="mt-4 divide-y divide-grey-200/70">
            @forelse ($recent as $log)
                <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2.5 text-sm">
                    <span class="min-w-0">
                        <span class="font-medium text-ink">{{ $log->action->label() }}</span>
                        @if ($log->organization)
                            <span class="text-grey-500">— {{ $log->organization->name }}</span>
                        @endif
                    </span>
                    <span class="text-xs text-grey-400 tabular">
                        {{ $log->created_at->timezone(config('app.display_timezone'))->translatedFormat('j M \a\ H\hi') }}
                    </span>
                </li>
            @empty
                <li class="py-6 text-center text-sm text-grey-500">Aucune activite enregistree.</li>
            @endforelse
        </ul>
    </div>
</div>
