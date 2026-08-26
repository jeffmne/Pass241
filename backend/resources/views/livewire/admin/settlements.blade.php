@php
    use App\Enums\PayoutState;
    use App\Support\Money;

    $card = fn (PayoutState $state) => $totals[$state->value] ?? ['count' => 0, 'amount' => 0];
@endphp

{{--
    Console des reversements (section 58).

    CONSULTATION SEULE, et structurellement : aucun bouton ne declenche, ne
    rejoue ni n'annule un reversement. Le reversement est instruit par une
    commande planifiee et son montant est gele en base (decision D-026). Un
    bouton, meme desactive, laisserait croire qu'un humain peut liberer des
    fonds a la demande.

    Le tableau reste une surface pleine, sans verre : la regle 70.4 ecarte le
    verre des tableaux denses, ou la lisibilite prime sur la matiere.
--}}
<div>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-extrabold text-ink">Reversements</h1>
            <p class="mt-1 text-sm text-grey-500">
                Toutes organisations confondues. Consultation seule.
            </p>
        </div>

        <div class="relative w-full sm:w-72">
            <x-lucide-search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-grey-400"
                             stroke-width="1.75" />
            <input type="search" wire:model.live.debounce.400ms="search"
                   class="field pl-9"
                   placeholder="Reference, organisation"
                   aria-label="Rechercher un reversement">
        </div>
    </div>

    {{-- Totaux par etat. Agreges en SQL : cette console doit rester utilisable
         avec des dizaines de milliers de lignes. --}}
    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            [PayoutState::Succeeded, 'Verses', 'circle-check', 'text-success'],
            [PayoutState::Scheduled, 'Programmes', 'calendar-clock', 'text-azure-600'],
            [PayoutState::Pending, 'En cours', 'loader-circle', 'text-warning'],
            [PayoutState::Failed, 'Echoues', 'circle-alert', 'text-danger'],
        ] as [$state, $label, $icon, $colour])
            @php ($stat = $card($state))
            <div class="glass-stat glass-specular p-4"
                 data-reveal data-reveal-step="{{ $loop->index }}">
                <div class="flex items-center gap-2 text-grey-500">
                    <x-dynamic-component :component="'lucide-'.$icon" class="size-4" stroke-width="1.5" />
                    <span class="text-xs font-semibold tracking-wide uppercase">{{ $label }}</span>
                </div>
                <p class="mt-2 font-display text-xl font-extrabold {{ $colour }} tabular">
                    {{ Money::format($stat['amount']) }}
                </p>
                <p class="mt-0.5 text-sm text-grey-500 tabular">
                    {{ $stat['count'] }} reversement{{ $stat['count'] > 1 ? 's' : '' }}
                </p>
            </div>
        @endforeach
    </div>

    @if ($attentionCount > 0)
        {{-- Un virement echoue ou dont on ignore le sort demande une
             intervention humaine : c'est la seule chose que cette console doit
             faire remonter d'elle-meme. --}}
        <p class="mt-4 flex items-start gap-2.5 rounded-2xl border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-danger" stroke-width="1.75" />
            <span>
                {{ $attentionCount }} reversement{{ $attentionCount > 1 ? 's' : '' }}
                {{ $attentionCount > 1 ? 'demandent' : 'demande' }} une verification :
                echec, ou etat inconnu du prestataire. Un etat inconnu ne doit
                jamais etre rejoue sans avoir ete verifie — le virement est
                peut-etre deja parti.
            </span>
        </p>
    @endif

    <div class="mt-5 flex flex-wrap gap-1 rounded-xl border border-white/60 bg-white/60 p-1 backdrop-blur">
        @foreach ([
            'all' => 'Tous',
            'attention' => 'A verifier',
            'pending' => 'En cours',
            'settled' => 'Verses',
        ] as $value => $label)
            <button type="button" wire:click="$set('filter', '{{ $value }}')" @class([
                'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                'bg-azure-600 text-white' => $filter === $value,
                'text-grey-500 hover:text-ink' => $filter !== $value,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    {{-- Tableau sur grand ecran --}}
    <div class="surface mt-4 hidden overflow-x-auto p-0 lg:block">
        <table class="w-full min-w-[54rem] text-sm">
            <thead>
                <tr class="border-b border-grey-200 text-left text-xs tracking-wide text-grey-500 uppercase">
                    <th class="px-4 py-3 font-semibold">Reference</th>
                    <th class="px-4 py-3 font-semibold">Organisation</th>
                    <th class="px-4 py-3 font-semibold">Evenement</th>
                    <th class="px-4 py-3 text-right font-semibold">Brut</th>
                    <th class="px-4 py-3 text-right font-semibold">Commission</th>
                    <th class="px-4 py-3 text-right font-semibold">Net verse</th>
                    <th class="px-4 py-3 font-semibold">Etat</th>
                    <th class="px-4 py-3 font-semibold">Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($settlements as $settlement)
                    <tr class="border-b border-grey-200/60 last:border-0">
                        <td class="px-4 py-3">
                            <span class="font-medium text-ink tabular">{{ $settlement->reference }}</span>
                            @if ($settlement->provider_reference)
                                <span class="mt-0.5 block text-xs text-grey-400 tabular">
                                    {{ $settlement->provider_reference }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-grey-500">{{ $settlement->organization?->name }}</td>
                        <td class="px-4 py-3 text-grey-500">{{ $settlement->event?->name }}</td>
                        <td class="px-4 py-3 text-right text-grey-500 tabular">{{ $settlement->formattedGross() }}</td>
                        <td class="px-4 py-3 text-right text-grey-500 tabular">{{ $settlement->formattedCommission() }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-ink tabular">{{ $settlement->formattedNet() }}</td>
                        <td class="px-4 py-3">
                            <x-settlement-state :status="$settlement->status" />
                        </td>
                        <td class="px-4 py-3 text-grey-500 tabular">
                            {{ $settlement->expectedOn()
                                ?->timezone(config('app.display_timezone'))
                                ?->translatedFormat('j M Y') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-grey-500">
                            Aucun reversement ne correspond.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Cartes sur telephone : un tableau de huit colonnes y devient
         illisible, et la console doit rester consultable en mobilite. --}}
    <div class="mt-4 space-y-3 lg:hidden">
        @forelse ($settlements as $settlement)
            <div class="glass-stat glass-specular p-4"
                 data-reveal data-reveal-step="{{ $loop->index % 5 }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-display font-bold text-ink tabular">{{ $settlement->reference }}</p>
                        <p class="mt-0.5 truncate text-sm text-grey-500">
                            {{ $settlement->organization?->name }}
                        </p>
                        <p class="truncate text-sm text-grey-400">{{ $settlement->event?->name }}</p>
                    </div>
                    <x-settlement-state :status="$settlement->status" />
                </div>

                <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 border-t border-white/60 pt-3 text-sm">
                    <div class="flex gap-1.5">
                        <dt class="text-grey-500">Brut</dt>
                        <dd class="text-ink tabular">{{ $settlement->formattedGross() }}</dd>
                    </div>
                    <div class="flex gap-1.5">
                        <dt class="text-grey-500">Commission</dt>
                        <dd class="text-ink tabular">{{ $settlement->formattedCommission() }}</dd>
                    </div>
                    <div class="flex gap-1.5">
                        <dt class="text-grey-500">Net</dt>
                        <dd class="font-semibold text-ink tabular">{{ $settlement->formattedNet() }}</dd>
                    </div>
                </dl>
            </div>
        @empty
            <div class="surface p-6 text-center text-sm text-grey-500">
                Aucun reversement ne correspond.
            </div>
        @endforelse
    </div>

    @if ($settlements->hasPages())
        <div class="mt-6">{{ $settlements->links() }}</div>
    @endif

    <p class="mt-6 text-xs leading-relaxed text-grey-400">
        Les montants sont figes a la creation du reversement et proteges en base
        contre toute modification. Aucun reversement ne peut etre declenche,
        rejoue ou annule depuis cet ecran : il est instruit par
        <span class="tabular">pass241:settle-events</span> et verifie par
        <span class="tabular">pass241:reconcile-settlements</span>.
    </p>
</div>
