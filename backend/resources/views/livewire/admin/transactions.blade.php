@php
    use App\Enums\OrderStatus;
    use App\Support\Money;
@endphp

{{--
    Transactions de la plateforme (section 58).

    Consultation seule, sans exception. Le numero de l'acheteur n'est jamais
    affiche, meme partiellement : cet ecran sert a reperer un ecart de montant
    ou un statut anormal, pas a retrouver quelqu'un (section 52).
--}}
<div>
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-extrabold text-ink sm:text-3xl">Transactions</h1>
            <p class="mt-1 text-sm text-grey-500">
                {{ number_format($orders->total(), 0, ',', "\u{202F}") }} commande{{ $orders->total() > 1 ? 's' : '' }}.
            </p>
        </div>

        <div class="relative w-full sm:w-72">
            <x-lucide-search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-grey-400"
                             stroke-width="1.75" />
            <input type="search" wire:model.live.debounce.400ms="search" class="field pl-10"
                   placeholder="Reference, organisation" aria-label="Rechercher une transaction">
        </div>
    </header>

    <div class="mb-4 flex flex-wrap gap-1 rounded-xl border border-white/60 bg-white/60 p-1 backdrop-blur">
        @foreach ([
            'all' => 'Toutes',
            'paid' => 'Payees',
            'refunded' => 'Remboursees',
            'failed' => 'Echouees',
        ] as $value => $label)
            <button type="button" wire:click="$set('filter', '{{ $value }}')" @class([
                'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                'bg-azure-600 text-white' => $filter === $value,
                'text-grey-500 hover:text-ink' => $filter !== $value,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    {{-- Tableau sur grand ecran. Surface pleine et non du verre : la
         section 70.4 ecarte le verre des tableaux denses, ou la lisibilite
         prime sur la matiere. --}}
    <div class="surface hidden overflow-x-auto p-0 lg:block">
        <table class="w-full min-w-[56rem] text-sm">
            <thead>
                <tr class="border-b border-grey-200 text-left text-xs tracking-wide text-grey-500 uppercase">
                    <th class="px-4 py-3 font-semibold">Reference</th>
                    <th class="px-4 py-3 font-semibold">Organisation</th>
                    <th class="px-4 py-3 font-semibold">Evenement</th>
                    <th class="px-4 py-3 text-right font-semibold">Billets</th>
                    <th class="px-4 py-3 text-right font-semibold">Frais</th>
                    <th class="px-4 py-3 text-right font-semibold">Total debite</th>
                    <th class="px-4 py-3 font-semibold">Statut</th>
                    <th class="px-4 py-3 font-semibold">Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr class="border-b border-grey-200/60 last:border-0">
                        <td class="px-4 py-3 font-medium text-ink tabular">{{ $order->reference }}</td>
                        <td class="px-4 py-3 text-grey-500">{{ $order->organization?->name }}</td>
                        <td class="px-4 py-3 text-grey-500">{{ $order->event?->name }}</td>
                        <td class="px-4 py-3 text-right text-grey-500 tabular">
                            {{ $order->formattedSubtotal() }}
                        </td>
                        <td class="px-4 py-3 text-right text-grey-500 tabular">
                            {{ $order->psp_fee_amount > 0 ? $order->formattedPspFee() : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right font-semibold text-ink tabular">
                            {{ $order->formattedAmount() }}
                        </td>
                        <td class="px-4 py-3">
                            <x-order-state :status="$order->status" />
                        </td>
                        <td class="px-4 py-3 text-grey-500 tabular">
                            {{ ($order->paid_at ?? $order->created_at)
                                ->timezone(config('app.display_timezone'))
                                ->translatedFormat('j M Y') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-grey-500">
                            Aucune transaction ne correspond.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Cartes sur telephone : un tableau de huit colonnes y devient
         illisible. --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($orders as $index => $order)
            <div class="glass-stat glass-specular p-4"
                 data-reveal data-reveal-step="{{ $index % 5 }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-display font-bold text-ink tabular">{{ $order->reference }}</p>
                        <p class="mt-0.5 truncate text-sm text-grey-500">{{ $order->organization?->name }}</p>
                        <p class="truncate text-sm text-grey-400">{{ $order->event?->name }}</p>
                    </div>
                    <x-order-state :status="$order->status" />
                </div>

                <div class="mt-3 flex items-baseline justify-between border-t border-white/60 pt-3">
                    <span class="text-sm text-grey-500">Total debite</span>
                    <span class="font-display font-bold text-ink tabular">{{ $order->formattedAmount() }}</span>
                </div>
            </div>
        @empty
            <div class="surface p-10 text-center text-sm text-grey-500">
                Aucune transaction ne correspond.
            </div>
        @endforelse
    </div>

    @if ($orders->hasPages())
        <div class="mt-6">{{ $orders->links() }}</div>
    @endif

    <p class="mt-6 text-xs leading-relaxed text-grey-400">
        « Billets » est le prix des billets, « Frais » ceux du prestataire de
        paiement, avances par l'acheteur. La commission Pass241 n'apparait pas
        ici : elle se preleve sur le prix des billets, pas sur le total debite.
        Le numero de l'acheteur n'est jamais affiche.
    </p>
</div>
