@php
    use App\Enums\OrderStatus;

    $tz = config('app.display_timezone');
@endphp

<div class="mx-auto w-full max-w-2xl px-5 pt-28 pb-16 sm:px-6 sm:pt-32">

    <h1 class="font-display text-3xl leading-tight font-extrabold text-ink sm:text-4xl">
        Mes billets
    </h1>
    <p class="mt-2 leading-relaxed text-grey-500">
        Les billets achetes depuis cet appareil. Aucun compte necessaire.
    </p>

    @forelse ($orders as $order)
        @php $event = $order->event; @endphp

        <article class="glass-inset mt-5 overflow-hidden">
            <div class="border-b border-grey-200/70 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="font-display text-lg font-bold text-ink">{{ $event->name }}</h2>
                        <p class="mt-1 text-sm text-grey-500 tabular">
                            {{ $event->starts_at->timezone($tz)->translatedFormat('j F Y \a\ H\hi') }}
                            @if ($event->location)
                                — {{ $event->location->shortAddress() }}
                            @endif
                        </p>
                    </div>

                    <span @class([
                        'shrink-0 rounded-full px-3 py-1 text-xs font-semibold',
                        'bg-success/10 text-success' => $order->status->tone() === 'success',
                        'bg-warning/10 text-warning' => $order->status->tone() === 'warning',
                        'bg-danger/10 text-danger' => $order->status->tone() === 'danger',
                    ])>
                        {{ $order->status->label() }}
                    </span>
                </div>

                <p class="mt-2 text-xs text-grey-400 tabular">
                    Commande {{ $order->reference }}
                </p>
            </div>

            @if ($order->tickets->isNotEmpty())
                <ul class="divide-y divide-grey-200/70">
                    @foreach ($order->tickets as $ticket)
                        <li class="flex items-center justify-between gap-4 p-4 sm:p-5">
                            <span class="min-w-0">
                                <span class="block font-semibold text-ink">
                                    {{ $ticket->orderItem?->ticket_type_name }}
                                </span>
                                <span class="mt-0.5 block text-sm text-grey-500 tabular">
                                    {{ $ticket->reference }}
                                </span>
                            </span>

                            <span class="flex shrink-0 items-center gap-2">
                                <x-status-badge :status="$ticket->status" />
                                <a href="{{ $ticket->publicUrl() }}" wire:navigate class="btn btn-primary">
                                    Voir
                                </a>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @elseif ($order->isPending())
                <div class="p-5">
                    <a href="{{ $order->statusUrl() }}" class="btn btn-primary btn-pill">
                        Reprendre le paiement
                    </a>
                </div>
            @else
                <p class="p-5 text-sm text-grey-500">
                    Aucun billet n'a ete emis pour cette commande.
                </p>
            @endif
        </article>
    @empty
        <div class="glass-inset mt-6 p-8 text-center sm:p-10">
            <x-lucide-ticket class="mx-auto size-8 text-azure-300" stroke-width="1.5" />
            <h2 class="mt-3 font-display text-lg font-bold text-ink">Aucun billet sur cet appareil</h2>
            <p class="mx-auto mt-1.5 max-w-sm text-sm leading-relaxed text-grey-500">
                Vos billets apparaissent ici apres un achat. Si vous avez achete depuis un
                autre telephone, retrouvez-les avec la reference de votre commande.
            </p>
        </div>
    @endforelse

    {{-- Rattrapage : l'acheteur a change d'appareil ou efface ses donnees de
         navigation. La reference de commande lui a ete communiquee a l'achat
         et figure sur son recu. --}}
    <div class="glass-inset mt-5 p-5 sm:p-6">
        <h2 class="font-display text-base font-bold text-ink">Retrouver une commande</h2>
        <p class="mt-1.5 text-sm text-grey-500">
            Saisissez la reference recue lors de votre achat, du type PS241-XXXX-XXX.
        </p>

        <form wire:submit="findOrder" class="mt-4 flex flex-col gap-2.5 sm:flex-row">
            <input type="text" wire:model="reference" class="field flex-1 tabular uppercase"
                   placeholder="PS241-XXXX-XXX"
                   autocapitalize="characters" autocomplete="off"
                   aria-label="Reference de commande"
                   aria-invalid="{{ $errors->has('reference') ? 'true' : 'false' }}">

            <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled">
                Retrouver
            </button>
        </form>

        @error('reference')
            <p class="mt-2.5 flex items-start gap-1.5 text-sm text-danger">
                <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
                <span>{{ $message }}</span>
            </p>
        @enderror
    </div>
</div>
