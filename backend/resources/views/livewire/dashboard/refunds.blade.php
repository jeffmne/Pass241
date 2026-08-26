@php
    use App\Enums\RefundStatus;
@endphp

{{--
    Decisions de remboursement (section 24.1).

    Le client a demande, l'organisateur tranche. Une decision ne se reprend
    pas : aucune action n'est proposee sur une demande deja tranchee.
--}}
<div>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl font-extrabold text-ink">Remboursements</h1>
            <p class="mt-1 text-sm text-grey-500">
                @if ($pendingCount > 0)
                    {{ $pendingCount }} demande{{ $pendingCount > 1 ? 's' : '' }} en attente de votre decision.
                @else
                    Aucune demande en attente.
                @endif
            </p>
        </div>

        <div class="flex rounded-xl border border-grey-200 bg-white p-1">
            @foreach (['pending' => 'En attente', 'decided' => 'Tranchees', 'all' => 'Toutes'] as $value => $label)
                <button type="button" wire:click="$set('filter', '{{ $value }}')" @class([
                    'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                    'bg-azure-600 text-white' => $filter === $value,
                    'text-grey-500 hover:text-ink' => $filter !== $value,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @error('decision')
        <p class="mt-4 flex items-start gap-2.5 rounded-xl border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-danger" stroke-width="1.75" />
            <span>{{ $message }}</span>
        </p>
    @enderror

    @unless ($canDecide)
        <p class="mt-4 flex items-start gap-2.5 rounded-xl bg-grey-200/50 px-4 py-3 text-sm text-grey-500">
            <x-lucide-eye class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
            <span>Consultation seule : votre role ne permet pas de trancher les remboursements.</span>
        </p>
    @endunless

    @forelse ($requests as $request)
        <div class="surface mt-4 p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-display font-bold text-ink tabular">{{ $request->reference }}</p>
                        <span @class([
                            'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                            'bg-warning/10 text-warning' => $request->isPending(),
                            'bg-success/10 text-success' => $request->isApproved(),
                            'bg-grey-200/80 text-grey-500' => ! $request->isPending() && ! $request->isApproved(),
                        ])>{{ $request->status->label() }}</span>
                    </div>

                    <p class="mt-1 text-sm text-grey-500">
                        Commande <span class="tabular">{{ $request->order?->reference }}</span>
                        @if ($request->isPartial())
                            — billet <span class="tabular">{{ $request->ticket?->reference }}</span>
                        @else
                            — commande entiere
                        @endif
                    </p>

                    @if ($request->reason)
                        <p class="mt-2 text-sm text-ink">
                            <span class="font-medium">Motif du client :</span> {{ $request->reason }}
                        </p>
                    @endif

                    @if ($request->decision_reason)
                        <p class="mt-2 text-sm text-grey-500">
                            <span class="font-medium">Votre reponse :</span> {{ $request->decision_reason }}
                        </p>
                    @endif
                </div>

                <div class="shrink-0 text-right">
                    <p class="font-display text-lg font-extrabold text-ink tabular">
                        {{ $request->formattedAmount() }}
                    </p>
                    @if ($request->psp_fee_amount > 0)
                        {{-- Les frais restent chez l'operateur : le dire ici
                             evite que l'organisateur croie devoir les rendre. --}}
                        <p class="mt-0.5 text-xs text-grey-400">
                            hors {{ $request->formattedPspFee() }} de frais
                        </p>
                    @endif
                </div>
            </div>

            @if ($request->isPending() && $canDecide)
                <div class="mt-4 border-t border-grey-200/70 pt-4">
                    <label for="reason-{{ $request->id }}" class="mb-1.5 block text-sm font-medium text-ink">
                        Motif, obligatoire pour refuser
                    </label>
                    <input id="reason-{{ $request->id }}" type="text" class="field"
                           wire:model="reasons.{{ $request->id }}" maxlength="500">

                    @error('reason.'.$request->id)
                        <p class="mt-1.5 text-sm text-danger">{{ $message }}</p>
                    @enderror

                    <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                        <button type="button" wire:click="approve({{ $request->id }})"
                                wire:loading.attr="disabled"
                                class="btn btn-primary flex-1">
                            Accepter et rembourser
                        </button>
                        <button type="button" wire:click="reject({{ $request->id }})"
                                wire:loading.attr="disabled"
                                class="btn btn-ghost flex-1">
                            Refuser
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @empty
        <div class="surface mt-6 p-6 text-center sm:p-10">
            <x-lucide-receipt class="mx-auto size-8 text-azure-300" stroke-width="1.5" />
            <h2 class="mt-3 font-display text-lg font-bold text-ink">Aucune demande</h2>
            <p class="mx-auto mt-1.5 max-w-md text-sm text-grey-500">
                Les demandes de remboursement de vos participants apparaissent ici.
                Elles se ferment automatiquement 48 heures avant l'evenement.
            </p>
        </div>
    @endforelse

    @if ($requests->hasPages())
        <div class="mt-6">{{ $requests->links() }}</div>
    @endif
</div>
