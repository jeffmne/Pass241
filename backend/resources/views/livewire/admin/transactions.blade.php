@php
    use App\Support\Money;

    $tz = config('app.display_timezone');
@endphp

{{--
    Transactions de la plateforme (section 58).

    Consultation seule, sans exception. Le numero de l'acheteur n'est jamais
    affiche, meme partiellement : cet ecran sert a reperer un ecart de montant
    ou un statut anormal, pas a retrouver quelqu'un (section 52).

    UNE SEULE BOUCLE pour les deux compositions. Cet ecran ecrivait auparavant
    chaque ligne deux fois — un tableau pour le bureau, une liste de cartes pour
    le telephone — et les deux avaient deja commence a diverger : la carte
    n'affichait ni les frais ni la date. Le tableau bascule maintenant en cartes
    par le CSS (`data-table-stack`), a partir du meme balisage.
--}}
<div class="space-y-5">

    <x-ui.page-header
        title="Transactions"
        :description="Money::number($orders->total()) . ' commande' . ($orders->total() > 1 ? 's' : '') . '.'"
        eyebrow="Administration Pass241">
        <x-slot:actions>
            <x-ui.search-input
                id="transactions-search"
                class="w-full sm:w-72"
                label="Rechercher une transaction"
                placeholder="Reference, organisation"
                wire:model.live.debounce.400ms="search" />
        </x-slot:actions>
    </x-ui.page-header>

    <div class="scroll-x no-scrollbar -mx-1 px-1">
        <div class="seg" role="tablist" aria-label="Filtrer les transactions">
            @foreach ([
                'all' => 'Toutes',
                'paid' => 'Payees',
                'refunded' => 'Remboursees',
                'failed' => 'Echouees',
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

    {{-- Surface pleine et non du verre : la section 70.4 ecarte le verre des
         tableaux denses, ou la lisibilite prime sur la matiere. --}}
    <x-ui.panel flush
                wire:loading.delay.class="opacity-40"
                wire:target="search, filter, gotoPage, previousPage, nextPage"
                class="transition-opacity">

        @if ($orders->isEmpty())
            <x-ui.empty-state
                icon="search-x"
                tone="filtered"
                title="Aucune transaction"
                description="Aucune transaction ne correspond." />
        @else
            <x-ui.table caption="Transactions de la plateforme">
                <x-slot:head>
                    <th scope="col">Reference</th>
                    <th scope="col">Organisation</th>
                    <th scope="col">Evenement</th>
                    <th scope="col" class="col-amount">Billets</th>
                    <th scope="col" class="col-amount">Frais</th>
                    <th scope="col" class="col-amount">Total debite</th>
                    <th scope="col">Statut</th>
                    <th scope="col">Date</th>
                </x-slot:head>

                @foreach ($orders as $order)
                    <tr wire:key="order-{{ $order->id }}">
                        <td class="cell-primary tabular">{{ $order->reference }}</td>

                        <td data-label="Organisation" class="text-grey-500">{{ $order->organization?->name }}</td>
                        <td data-label="Evenement" class="text-grey-500">{{ $order->event?->name }}</td>

                        <td data-label="Billets" class="col-amount font-normal text-grey-500">
                            {{ $order->formattedSubtotal() }}
                        </td>
                        <td data-label="Frais" class="col-amount font-normal text-grey-500">
                            {{ $order->psp_fee_amount > 0 ? $order->formattedPspFee() : '—' }}
                        </td>
                        <td data-label="Total debite" class="col-amount">{{ $order->formattedAmount() }}</td>

                        <td data-label="Statut">
                            <x-order-state :status="$order->status" />
                        </td>
                        <td data-label="Date" class="text-grey-500 tabular">
                            {{ ($order->paid_at ?? $order->created_at)->timezone($tz)->translatedFormat('j M Y') }}
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif

        <x-slot:footer>
            « Billets » est le prix des billets, « Frais » ceux du prestataire de
            paiement, avances par l'acheteur. La commission Pass241 n'apparait pas
            ici : elle se preleve sur le prix des billets, pas sur le total debite.
            Le numero de l'acheteur n'est jamais affiche.
        </x-slot:footer>
    </x-ui.panel>

    @if ($orders->hasPages())
        <div>{{ $orders->links() }}</div>
    @endif
</div>
