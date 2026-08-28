@php
    use App\Support\Money;

    $tz = config('app.display_timezone');
    $needsAttention = $attention['settlements'] > 0 || $attention['refunds'] > 0;
@endphp

{{--
    Vue generale de la plateforme (section 58).

    Ecran de surveillance, pas tableau de bord commercial : ce qui ne va pas
    passe AVANT les volumes. Un administrateur ouvre cette page pour verifier
    que rien ne cloche, pas pour contempler une courbe.
--}}
<div class="space-y-5">

    <x-ui.page-header
        title="Vue generale"
        description="Toutes organisations confondues."
        eyebrow="Administration Pass241" />

    {{-- Ce qui demande une intervention, en premier. Jamais replie : un
         reversement en echec doit apparaitre a l'instant ou il echoue. --}}
    @if ($needsAttention)
        <div role="alert"
             class="flex flex-col gap-3 rounded-[var(--radius-lg)] border border-danger/30 bg-danger/5 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex gap-3">
                <x-lucide-circle-alert class="mt-0.5 size-5 shrink-0 text-danger" stroke-width="2" aria-hidden="true" />
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
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Flux financiers de la plateforme">
        <x-ui.stat-card
            icon="coins"
            label="Commission Pass241"
            data-reveal data-reveal-step="0"
            :value="Money::format($totals['commission'])"
            hint="Revenu de la plateforme" />

        <x-ui.stat-card
            icon="arrow-left-right"
            label="Volume encaisse"
            tone="neutral"
            data-reveal data-reveal-step="1"
            :value="Money::format($totals['grossVolume'])"
            hint="Transite, n'appartient pas a Pass241" />

        <x-ui.stat-card
            icon="wallet"
            label="Reverse"
            tone="success"
            data-reveal data-reveal-step="2"
            :value="Money::format($totals['settledNet'])"
            hint="Virements aboutis" />

        <x-ui.stat-card
            icon="undo-2"
            label="Rembourse"
            tone="warning"
            data-reveal data-reveal-step="3"
            :value="Money::format($totals['refunded'])"
            hint="Rendu aux acheteurs" />
    </section>

    {{-- Volumes. Surfaces pleines et non du verre : deux niveaux de verre
         cote a cote se disputeraient la meme hierarchie, alors que ces
         chiffres-ci passent apres l'argent. --}}
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Volumes de la plateforme">
        @foreach ([
            ['building-2', 'Organisations', $totals['organizations'], $totals['activeOrganizations'].' actives'],
            ['users', 'Utilisateurs', $totals['users'], null],
            ['calendar-days', 'Evenements', $totals['events'], $totals['publishedEvents'].' publies'],
            ['ticket', 'Billets vendus', $totals['ticketsSold'], $totals['ordersPaid'].' commandes'],
        ] as [$icon, $label, $value, $note])
            <div class="surface p-4" data-reveal data-reveal-step="{{ $loop->index }}">
                <div class="flex items-center gap-2 text-grey-500">
                    <x-dynamic-component :component="'lucide-'.$icon" class="size-4 shrink-0"
                                         stroke-width="2" aria-hidden="true" />
                    <span class="text-xs font-bold tracking-wider uppercase">{{ $label }}</span>
                </div>
                <p class="mt-2 font-display text-xl font-extrabold text-ink tabular">
                    {{ Money::number($value) }}
                </p>
                @if ($note)
                    <p class="mt-0.5 text-sm text-grey-500 tabular">{{ $note }}</p>
                @endif
            </div>
        @endforeach
    </section>

    {{-- Derniere activite. Dix lignes se lisent d'un coup d'oeil ; cinquante
         demandent un effort et ne seraient plus regardees. --}}
    <x-ui.panel title="Derniere activite">
        <x-slot:actions>
            <a href="{{ route('admin.audit') }}" class="text-sm font-semibold text-azure-700 hover:underline">
                Voir le journal
            </a>
        </x-slot:actions>

        <ul class="divide-y divide-grey-200">
            @forelse ($recent as $log)
                <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2.5 text-sm">
                    <span class="min-w-0">
                        <span class="font-semibold text-ink">{{ $log->action->label() }}</span>
                        @if ($log->organization)
                            <span class="text-grey-500">— {{ $log->organization->name }}</span>
                        @endif
                    </span>
                    <span class="text-xs text-grey-400 tabular">
                        {{ $log->created_at->timezone($tz)->translatedFormat('j M \a\ H\hi') }}
                    </span>
                </li>
            @empty
                <li>
                    <x-ui.empty-state
                        icon="scroll-text"
                        title="Aucune activite"
                        description="Le journal se remplit des la premiere action enregistree sur la plateforme." />
                </li>
            @endforelse
        </ul>
    </x-ui.panel>
</div>
