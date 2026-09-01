@php
    use App\Support\Money;

    $tz = config('app.display_timezone');
    $closed = $poll->displayStatus() === App\Enums\PollStatus::Closed;
@endphp

{{--
    Resultats d'un scrutin (sections 86.4, 87).

    L'ecran distingue en permanence DEUX ETATS : un classement calcule, qui
    bouge a chaque bulletin, et un classement fige a la cloture, qui ne bouge
    plus. Un organisateur qui capture son ecran pour annoncer un gagnant doit
    savoir lequel des deux il regarde.

    Aucun emoji (regle 80.6).
--}}
<div class="space-y-6">

    <x-ui.page-header
        :title="$poll->name"
        :description="'Du '.$poll->starts_at->timezone($tz)->translatedFormat('j F Y')
            .' au '.$poll->ends_at->timezone($tz)->translatedFormat('j F Y')">
        <x-slot:actions>
            <a href="{{ route('polls.edit', $poll) }}" wire:navigate class="btn btn-secondary">
                <x-lucide-arrow-left class="size-4" stroke-width="1.75" aria-hidden="true" />
                Retour au vote
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- ----------------------------------------------------------------
         Ce que le scrutin a produit, en un coup d'oeil
    ----------------------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Voix exprimees" :value="Money::number($totals['votes'])" icon="check-check" />
        <x-ui.stat-card label="Votants distincts" :value="Money::number($totals['voters'])" icon="users" />

        @if ($canSeeRevenue)
            {{-- Le revenu est le SOUS-TOTAL des bulletins confirmes : les frais
                 de l'operateur ne reviennent pas a l'organisateur
                 (decision D-023). --}}
            <x-ui.stat-card label="Revenu des votes" :value="Money::formatShort($totals['revenue'])" icon="coins" />
        @else
            <x-ui.stat-card label="Bulletins" :value="Money::number($totals['ballots'])" icon="receipt" />
        @endif
    </div>

    {{-- ----------------------------------------------------------------
         L'etat du classement, dit sans detour
    ----------------------------------------------------------------- --}}
    @if ($frozenAt)
        <div role="status"
             class="flex items-start gap-2.5 rounded-[var(--radius-md)] border border-success/30 bg-success/5 px-4 py-3 text-sm text-ink">
            <x-lucide-lock class="mt-0.5 size-4 shrink-0 text-success" stroke-width="2" aria-hidden="true" />
            <span>
                Classement definitif, fige le
                {{ $frozenAt->timezone($tz)->translatedFormat('j F Y \a H\hi') }}.
                Il ne bougera plus, meme si un remboursement intervient.
            </span>
        </div>
    @else
        <div role="status"
             class="flex items-start gap-2.5 rounded-[var(--radius-md)] border border-warning/30 bg-warning/5 px-4 py-3 text-sm text-ink">
            <x-lucide-triangle-alert class="mt-0.5 size-4 shrink-0 text-warning" stroke-width="2" aria-hidden="true" />
            <span>
                <span class="font-semibold">Classement en cours, pas encore definitif.</span>
                Il change a chaque vote et sera fige a la cloture. N'annoncez pas
                de gagnant a partir de cet ecran.
            </span>
        </div>
    @endif

    {{-- ----------------------------------------------------------------
         Le rapport final
    ----------------------------------------------------------------- --}}
    @if ($canExport)
        <div class="glass-inset p-4 sm:p-5">
            <x-export-buttons
                label="Rapport final"
                hint="Classement par categorie, gagnants, voix et revenus."
                :csv="route('exports.poll-results', ['poll' => $poll, 'format' => 'csv'])"
                :xlsx="route('exports.poll-results', ['poll' => $poll, 'format' => 'xlsx'])" />
        </div>
    @endif

    {{-- ----------------------------------------------------------------
         Le classement, categorie par categorie
    ----------------------------------------------------------------- --}}
    @forelse ($categories as $category)
        @php
            $rows = $standings[$category->id] ?? collect();
            $frozen = $isFrozen[$category->id] ?? false;
        @endphp

        <div wire:key="results-{{ $category->id }}" class="surface p-5 sm:p-7">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="font-display text-lg font-bold text-ink">{{ $category->name }}</h2>

                <p class="flex items-center gap-2 text-sm text-grey-500">
                    @if ($frozen)
                        <x-lucide-lock class="size-3.5 shrink-0 text-success" stroke-width="2" aria-hidden="true" />
                        Definitif
                    @else
                        <x-lucide-loader-circle class="size-3.5 shrink-0 text-warning" stroke-width="2" aria-hidden="true" />
                        En cours
                    @endif
                </p>
            </div>

            @if ($rows->isEmpty())
                <p class="mt-4 text-sm text-grey-500">
                    Aucun vote n'a ete exprime dans cette categorie.
                </p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-grey-200 text-left">
                                <th class="pb-2 pr-3 font-medium text-grey-500">Rang</th>
                                <th class="pb-2 pr-3 font-medium text-grey-500">Candidat</th>
                                <th class="pb-2 pr-3 text-right font-medium text-grey-500">Voix</th>
                                <th class="pb-2 pr-3 text-right font-medium text-grey-500">Part</th>
                                @if ($canSeeRevenue)
                                    <th class="pb-2 text-right font-medium text-grey-500">Revenu</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr @class([
                                    'border-b border-grey-200/60 last:border-0',
                                    // Le gagnant se distingue, mais seulement
                                    // quand le classement est definitif : le
                                    // souligner pendant le vote inviterait a
                                    // l'annoncer trop tot.
                                    'bg-azure-50/60' => $frozen && $row['rank'] === 1,
                                ])>
                                    <td class="py-2.5 pr-3">
                                        <span class="font-display font-bold text-ink tabular">{{ $row['rank'] }}</span>
                                        @if ($frozen && $row['rank'] === 1)
                                            <span class="ml-1.5 rounded-full bg-azure-100 px-2 py-0.5 text-xs font-semibold text-azure-700">
                                                Gagnant
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 pr-3">
                                        @if ($row['number'])
                                            <span class="text-grey-500 tabular">{{ $row['number'] }} —</span>
                                        @endif
                                        <span class="font-medium text-ink">{{ $row['name'] }}</span>
                                    </td>
                                    <td class="py-2.5 pr-3 text-right font-semibold text-ink tabular">
                                        {{ Money::number($row['votes']) }}
                                    </td>
                                    <td class="py-2.5 pr-3 text-right text-grey-500 tabular">
                                        {{ Money::number($row['share'], 2) }} %
                                    </td>
                                    @if ($canSeeRevenue)
                                        <td class="py-2.5 text-right text-grey-500 tabular">
                                            {{ Money::formatShort($row['revenue']) }}
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @empty
        <x-ui.empty-state
            icon="list-checks"
            title="Ce vote n'a aucune categorie"
            description="Un classement suppose au moins une categorie et ses candidats." />
    @endforelse
</div>
