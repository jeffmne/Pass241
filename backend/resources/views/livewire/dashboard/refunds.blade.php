{{--
    Decisions de remboursement (section 24.1).

    Le client a demande, l'organisateur tranche. Une decision ne se reprend
    pas : aucune action n'est proposee sur une demande deja tranchee.
--}}
<div class="space-y-5">

    <x-ui.page-header
        title="Remboursements"
        :description="$pendingCount > 0
            ? $pendingCount . ' demande' . ($pendingCount > 1 ? 's' : '') . ' en attente de votre decision.'
            : 'Aucune demande en attente.'">

        <x-slot:actions>
            {{-- La sous-navigation des finances d'abord : elle dit ou l'on est
                 dans la section, avant de proposer de filtrer ce qu'on y
                 regarde. --}}
            <x-finance-nav />

            {{-- Trois onglets plutot qu'un menu deroulant : l'organisateur voit
                 d'un coup ce qui existe, et change de vue en un geste au lieu
                 de trois. Sur telephone le groupe defile plutot que de passer
                 a la ligne — trois pastilles empilees prendraient la moitie de
                 l'ecran. --}}
            <div class="scroll-x no-scrollbar -mx-1 px-1">
                <div class="seg" role="tablist" aria-label="Filtrer les demandes">
                    @foreach (['pending' => 'En attente', 'decided' => 'Tranchees', 'all' => 'Toutes'] as $value => $label)
                        <button type="button"
                                role="tab"
                                wire:click="$set('filter', '{{ $value }}')"
                                aria-selected="{{ $filter === $value ? 'true' : 'false' }}"
                                class="seg-item">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @error('decision')
        <p role="alert"
           class="flex items-start gap-2.5 rounded-[var(--radius-md)] border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-danger" stroke-width="2" aria-hidden="true" />
            <span>{{ $message }}</span>
        </p>
    @enderror

    @unless ($canDecide)
        <p class="flex items-start gap-2.5 rounded-[var(--radius-md)] bg-surface-muted px-4 py-3 text-sm text-grey-500">
            <x-lucide-eye class="mt-0.5 size-4 shrink-0" stroke-width="2" aria-hidden="true" />
            <span>Consultation seule : votre role ne permet pas de trancher les remboursements.</span>
        </p>
    @endunless

    <div class="space-y-3">
        @forelse ($requests as $request)
            <div wire:key="refund-{{ $request->id }}" class="surface p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-display font-bold text-ink tabular">{{ $request->reference }}</p>
                            <x-ui.badge :tone="match (true) {
                                $request->isPending() => 'warning',
                                $request->isApproved() => 'success',
                                default => 'neutral',
                            }">{{ $request->status->label() }}</x-ui.badge>
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
                                <span class="font-semibold">Motif du client :</span> {{ $request->reason }}
                            </p>
                        @endif

                        @if ($request->decision_reason)
                            <p class="mt-2 text-sm text-grey-500">
                                <span class="font-semibold">Votre reponse :</span> {{ $request->decision_reason }}
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
                    <div class="mt-4 border-t border-grey-200 pt-4">
                        <x-ui.field
                            label="Motif"
                            for="reason-{{ $request->id }}"
                            hint="Obligatoire pour refuser."
                            :error="$errors->first('reason.' . $request->id)">
                            <input id="reason-{{ $request->id }}" type="text" class="field"
                                   wire:model="reasons.{{ $request->id }}" maxlength="500"
                                   @error('reason.'.$request->id)
                                       aria-invalid="true" aria-describedby="reason-{{ $request->id }}-error"
                                   @enderror>
                        </x-ui.field>

                        {{-- Un remboursement est irreversible : la confirmation
                             n'est pas une politesse, c'est le dernier point ou
                             l'operation peut encore etre arretee (section 24). --}}
                        <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                            <button type="button" wire:click="approve({{ $request->id }})"
                                    wire:confirm="Accepter et rembourser {{ $request->formattedAmount() }} ? Cette decision est definitive."
                                    wire:loading.attr="disabled"
                                    class="btn btn-primary flex-1">
                                Accepter et rembourser
                            </button>
                            <button type="button" wire:click="reject({{ $request->id }})"
                                    wire:confirm="Refuser cette demande ? Cette decision est definitive."
                                    wire:loading.attr="disabled"
                                    class="btn btn-danger flex-1">
                                Refuser
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="surface">
                <x-ui.empty-state
                    icon="receipt"
                    :tone="$filter === 'pending' ? 'neutral' : 'filtered'"
                    title="Aucune demande"
                    description="Les demandes de remboursement de vos participants apparaissent ici. Elles se ferment automatiquement 48 heures avant l'evenement." />
            </div>
        @endforelse
    </div>

    @if ($requests->hasPages())
        <div>{{ $requests->links() }}</div>
    @endif
</div>
