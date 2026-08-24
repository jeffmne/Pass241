@php
    use App\Support\Money;
@endphp

<div x-data="{ notice: '' }"
     @notify.window="notice = $event.detail.message; setTimeout(() => notice = '', 4000)">

    <div class="mb-6">
        <a href="{{ route('events.edit', $event) }}" wire:navigate
           class="inline-flex items-center gap-1.5 text-sm font-medium text-grey-500 transition-colors hover:text-azure-700">
            <x-lucide-arrow-left class="size-4" stroke-width="1.75" />
            {{ $event->name }}
        </a>

        <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-ink sm:text-3xl">Billets</h1>
                <p class="mt-1 text-sm text-grey-500">
                    Types de billets, prix et quantites mises en vente.
                </p>
            </div>

            @unless ($showForm)
                <button type="button" wire:click="create" class="btn btn-primary shrink-0">
                    <x-lucide-plus class="size-4" stroke-width="2" />
                    Nouveau billet
                </button>
            @endunless
        </div>
    </div>

    <div x-show="notice" x-cloak
         class="mb-5 flex items-center gap-2.5 rounded-xl border border-success/30 bg-success/5 px-4 py-3 text-sm text-ink">
        <x-lucide-circle-check class="size-4 shrink-0 text-success" stroke-width="1.75" />
        <span x-text="notice"></span>
    </div>

    @if ($ticketTypes->isNotEmpty())
        <div class="surface mb-5 grid gap-px overflow-hidden bg-grey-200 sm:grid-cols-3">
            <div class="bg-white p-4">
                <p class="text-xs font-semibold tracking-wide text-grey-500 uppercase">En vente</p>
                <p class="mt-1 font-display text-xl font-bold text-ink tabular">{{ Money::format($totalOnSale, false) }}</p>
            </div>
            <div class="bg-white p-4">
                <p class="text-xs font-semibold tracking-wide text-grey-500 uppercase">Vendus</p>
                <p class="mt-1 font-display text-xl font-bold text-ink tabular">{{ Money::format($totalSold, false) }}</p>
            </div>
            <div class="bg-white p-4">
                <p class="text-xs font-semibold tracking-wide text-grey-500 uppercase">Restants</p>
                <p class="mt-1 font-display text-xl font-bold text-azure-600 tabular">
                    {{ Money::format(max(0, $totalOnSale - $totalSold), false) }}
                </p>
            </div>
        </div>
    @endif

    @if ($showForm)
        {{-- Surface pleine : c'est un formulaire de saisie de montants, la
             lisibilite prime sur l'effet (section 70.4). --}}
        <form wire:submit="save" class="surface mb-5 p-4 sm:p-6">
            <h2 class="font-display text-lg font-bold text-ink">
                {{ $editingId ? 'Modifier le billet' : 'Nouveau billet' }}
            </h2>

            <div class="mt-5 space-y-4">
                <div class="grid gap-4 sm:grid-cols-[1.4fr_1fr]">
                    <div>
                        <label for="tt-name" class="mb-1.5 block text-sm font-medium text-ink">Nom</label>
                        <input id="tt-name" type="text" wire:model="name" class="field"
                               placeholder="Standard, VIP, Early Bird..."
                               aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" required>
                        @error('name') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="tt-price" class="mb-1.5 block text-sm font-medium text-ink">
                            Prix <span class="font-normal text-grey-400">(FCFA)</span>
                        </label>
                        <input id="tt-price" type="number" min="0" step="1" wire:model="price"
                               class="field tabular"
                               aria-invalid="{{ $errors->has('price') ? 'true' : 'false' }}" required>
                        @error('price') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-xs text-grey-400">Laissez 0 pour un billet gratuit.</p>
                    </div>
                </div>

                <div>
                    <label for="tt-description" class="mb-1.5 block text-sm font-medium text-ink">
                        Description <span class="font-normal text-grey-400">(facultatif)</span>
                    </label>
                    <textarea id="tt-description" wire:model="description" rows="2" class="field"
                              placeholder="Ce que ce billet donne droit."></textarea>
                    @error('description') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="tt-quantity" class="mb-1.5 block text-sm font-medium text-ink">Quantite</label>
                        <input id="tt-quantity" type="number" min="1" wire:model="quantity" class="field tabular"
                               aria-invalid="{{ $errors->has('quantity') ? 'true' : 'false' }}" required>
                        @error('quantity') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="tt-max" class="mb-1.5 block text-sm font-medium text-ink">
                            Maximum par commande
                        </label>
                        <input id="tt-max" type="number" min="1" wire:model="maxPerOrder" class="field tabular"
                               aria-invalid="{{ $errors->has('maxPerOrder') ? 'true' : 'false' }}" required>
                        @error('maxPerOrder') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="tt-start" class="mb-1.5 block text-sm font-medium text-ink">
                            Debut de vente <span class="font-normal text-grey-400">(facultatif)</span>
                        </label>
                        <input id="tt-start" type="datetime-local" wire:model="salesStartAt" class="field">
                        @error('salesStartAt') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="tt-end" class="mb-1.5 block text-sm font-medium text-ink">
                            Fin de vente <span class="font-normal text-grey-400">(facultatif)</span>
                        </label>
                        <input id="tt-end" type="datetime-local" wire:model="salesEndAt" class="field">
                        @error('salesEndAt') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex min-h-11 items-center gap-2.5 text-sm text-ink">
                        <input type="checkbox" wire:model="isActive" class="size-4 rounded border-grey-200">
                        En vente
                    </label>
                    <label class="flex min-h-11 items-center gap-2.5 text-sm text-ink">
                        <input type="checkbox" wire:model="isVisible" class="size-4 rounded border-grey-200">
                        Visible sur la page publique
                    </label>
                </div>
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">Enregistrer</span>
                    <span wire:loading wire:target="save">Enregistrement</span>
                </button>
                <button type="button" wire:click="cancelForm" class="btn btn-ghost">Annuler</button>
            </div>
        </form>
    @endif

    @forelse ($ticketTypes as $ticketType)
        @php
            $sold = $ticketType->soldCount();
            $remaining = $ticketType->remainingStock();
            $reason = $ticketType->unavailabilityReason();
        @endphp

        <article class="surface mb-3 p-4 sm:p-5">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-display text-base font-bold text-ink">{{ $ticketType->name }}</h2>

                        @if ($reason)
                            <span class="rounded-full bg-grey-200/70 px-2.5 py-1 text-xs font-semibold text-grey-500">
                                {{ $reason }}
                            </span>
                        @else
                            <span class="rounded-full bg-success/10 px-2.5 py-1 text-xs font-semibold text-success">
                                En vente
                            </span>
                        @endif

                        @unless ($ticketType->is_visible)
                            <span class="flex items-center gap-1 rounded-full bg-grey-200/70 px-2.5 py-1 text-xs font-semibold text-grey-500">
                                <x-lucide-eye-off class="size-3" stroke-width="2" />
                                Masque
                            </span>
                        @endunless
                    </div>

                    @if ($ticketType->description)
                        <p class="mt-1.5 text-sm text-grey-500">{{ $ticketType->description }}</p>
                    @endif

                    <p class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-grey-500">
                        <span class="font-display font-bold text-ink tabular">{{ $ticketType->formattedPrice() }}</span>
                        <span class="tabular">{{ $sold }} / {{ $ticketType->quantity }} vendus</span>
                        <span class="tabular">{{ $remaining }} restants</span>
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-1.5">
                    <button type="button" wire:click="edit({{ $ticketType->id }})" class="btn btn-ghost">
                        Modifier
                    </button>

                    @if ($sold === 0)
                        <button type="button" wire:click="delete({{ $ticketType->id }})"
                                wire:confirm="Supprimer definitivement ce type de billet ?"
                                class="btn btn-ghost text-danger hover:bg-danger/10"
                                aria-label="Supprimer {{ $ticketType->name }}">
                            <x-lucide-trash-2 class="size-4" stroke-width="1.75" />
                        </button>
                    @endif
                </div>
            </div>
        </article>
    @empty
        @unless ($showForm)
            <div class="surface p-8 text-center sm:p-12">
                <x-lucide-ticket class="mx-auto size-8 text-azure-300" stroke-width="1.5" />
                <h2 class="mt-3 font-display text-lg font-bold text-ink">Aucun billet pour le moment</h2>
                <p class="mx-auto mt-1.5 max-w-sm text-sm leading-relaxed text-grey-500">
                    Creez au moins un type de billet pour que vos participants puissent reserver.
                    Un billet gratuit se cree en laissant le prix a zero.
                </p>
                <button type="button" wire:click="create" class="btn btn-primary mt-6">
                    <x-lucide-plus class="size-4" stroke-width="2" />
                    Creer un billet
                </button>
            </div>
        @endunless
    @endforelse
</div>
