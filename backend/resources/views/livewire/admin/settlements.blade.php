@php
    use App\Enums\PayoutState;
    use App\Support\Money;

    $tz = config('app.display_timezone');
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
    verre des tableaux denses, ou la lisibilite prime sur la matiere. Il bascule
    en cartes sous `md` par le CSS, a partir du meme balisage — cet ecran
    ecrivait auparavant chaque ligne deux fois, et les deux versions avaient
    deja diverge.
--}}
<div class="space-y-5">

    <x-ui.page-header
        title="Reversements"
        description="Toutes organisations confondues. Consultation seule."
        eyebrow="Administration Pass241">
        <x-slot:actions>
            <x-ui.search-input
                id="settlements-search"
                class="w-full sm:w-72"
                label="Rechercher un reversement"
                placeholder="Reference, organisation"
                wire:model.live.debounce.400ms="search" />
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Totaux par etat. Agreges en SQL : cette console doit rester utilisable
         avec des dizaines de milliers de lignes. --}}
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Totaux par etat">
        @foreach ([
            [PayoutState::Succeeded, 'Verses', 'circle-check', 'success'],
            [PayoutState::Scheduled, 'Programmes', 'calendar-clock', 'primary'],
            [PayoutState::Pending, 'En cours', 'loader-circle', 'warning'],
            [PayoutState::Failed, 'Echoues', 'circle-alert', 'danger'],
        ] as [$state, $label, $icon, $tone])
            @php ($stat = $card($state))
            <x-ui.stat-card
                :icon="$icon"
                :label="$label"
                :tone="$tone"
                data-reveal data-reveal-step="{{ $loop->index }}"
                :value="Money::format($stat['amount'])"
                :hint="$stat['count'] . ' reversement' . ($stat['count'] > 1 ? 's' : '')" />
        @endforeach
    </section>

    @if ($attentionCount > 0)
        {{-- Un virement echoue ou dont on ignore le sort demande une
             intervention humaine : c'est la seule chose que cette console doit
             faire remonter d'elle-meme. --}}
        <p role="alert"
           class="flex items-start gap-2.5 rounded-[var(--radius-lg)] border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-danger" stroke-width="2" aria-hidden="true" />
            <span>
                {{ $attentionCount }} reversement{{ $attentionCount > 1 ? 's' : '' }}
                {{ $attentionCount > 1 ? 'demandent' : 'demande' }} une verification :
                echec, ou etat inconnu du prestataire. Un etat inconnu ne doit
                jamais etre rejoue sans avoir ete verifie — le virement est
                peut-etre deja parti.
            </span>
        </p>
    @endif

    <div class="scroll-x no-scrollbar -mx-1 px-1">
        <div class="seg" role="tablist" aria-label="Filtrer les reversements">
            @foreach ([
                'all' => 'Tous',
                'attention' => 'A verifier',
                'pending' => 'En cours',
                'settled' => 'Verses',
            ] as $value => $label)
                <button type="button"
                        role="tab"
                        wire:click="$set('filter', '{{ $value }}')"
                        aria-selected="{{ $filter === $value ? 'true' : 'false' }}"
                        class="seg-item">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div wire:loading.delay wire:target="search, filter, gotoPage, previousPage, nextPage">
        <x-ui.skeleton variant="table" :rows="6" />
    </div>

    <x-ui.panel flush
                wire:loading.delay.class="opacity-40"
                wire:target="search, filter, gotoPage, previousPage, nextPage"
                class="transition-opacity">

        @if ($settlements->isEmpty())
            <x-ui.empty-state
                icon="search-x"
                tone="filtered"
                title="Aucun reversement"
                description="Aucun reversement ne correspond." />
        @else
            <x-ui.table caption="Reversements de la plateforme">
                <x-slot:head>
                    <th scope="col">Reference</th>
                    <th scope="col">Organisation</th>
                    <th scope="col">Evenement</th>
                    <th scope="col" class="col-amount">Brut</th>
                    <th scope="col" class="col-amount">Commission</th>
                    <th scope="col" class="col-amount">Net verse</th>
                    <th scope="col">Etat</th>
                    <th scope="col">Date</th>
                </x-slot:head>

                @foreach ($settlements as $settlement)
                    <tr wire:key="settlement-{{ $settlement->id }}">
                        <td class="cell-primary">
                            <span class="tabular">{{ $settlement->reference }}</span>
                            @if ($settlement->provider_reference)
                                <span class="mt-0.5 block text-xs font-normal text-grey-400 tabular">
                                    {{ $settlement->provider_reference }}
                                </span>
                            @endif
                        </td>

                        <td data-label="Organisation" class="text-grey-500">{{ $settlement->organization?->name }}</td>
                        <td data-label="Evenement" class="text-grey-500">{{ $settlement->settlableName() }}</td>

                        <td data-label="Brut" class="col-amount font-normal text-grey-500">
                            {{ $settlement->formattedGross() }}
                        </td>
                        <td data-label="Commission" class="col-amount font-normal text-grey-500">
                            {{ $settlement->formattedCommission() }}
                        </td>
                        <td data-label="Net verse" class="col-amount">{{ $settlement->formattedNet() }}</td>

                        <td data-label="Etat">
                            <x-settlement-state :status="$settlement->status" />
                        </td>
                        <td data-label="Date" class="text-grey-500 tabular">
                            {{ $settlement->expectedOn()?->timezone($tz)?->translatedFormat('j M Y') }}
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif

        <x-slot:footer>
            Les montants sont figes a la creation du reversement et proteges en base
            contre toute modification. Aucun reversement ne peut etre declenche,
            rejoue ou annule depuis cet ecran : il est instruit par
            <span class="tabular">pass241:settle-events</span> et verifie par
            <span class="tabular">pass241:reconcile-settlements</span>.
        </x-slot:footer>
    </x-ui.panel>

    @if ($settlements->hasPages())
        <div>{{ $settlements->links() }}</div>
    @endif
</div>
