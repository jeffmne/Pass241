@php
    use App\Support\Money;
@endphp

<div class="space-y-5"
     x-data="{ notice: '' }"
     @notify.window="notice = $event.detail.message; setTimeout(() => notice = '', 4000)">

    <x-ui.page-header
        title="Billets"
        description="Types de billets, prix et quantites mises en vente."
        :eyebrow="$event->name"
        :back="route('events.edit', $event)"
        :back-label="$event->name">
        @unless ($showForm)
            <x-slot:actions>
                <button type="button" wire:click="create" class="btn btn-primary">
                    <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                    Nouveau billet
                </button>
            </x-slot:actions>
        @endunless
    </x-ui.page-header>

    {{-- `role="status"` : annonce sans interrompre la lecture en cours. --}}
    <div x-show="notice" x-cloak role="status"
         class="flex items-center gap-2.5 rounded-[var(--radius-md)] border border-success/30 bg-success/5 px-4 py-3 text-sm text-ink">
        <x-lucide-circle-check class="size-4 shrink-0 text-success" stroke-width="2" aria-hidden="true" />
        <span x-text="notice"></span>
    </div>

    @if ($ticketTypes->isNotEmpty())
        {{-- Trois chiffres en verre : c'est de la synthese, on y regarde une
             proportion, pas une donnee qu'on releve ligne a ligne. --}}
        <section class="grid gap-3 sm:grid-cols-3" aria-label="Stock des billets">
            <x-ui.stat-card icon="layers" label="En vente" :value="Money::format($totalOnSale, false)" />
            <x-ui.stat-card icon="ticket-check" label="Vendus" tone="success" :value="Money::format($totalSold, false)" />
            <x-ui.stat-card icon="package" label="Restants" tone="warning"
                            :value="Money::format(max(0, $totalOnSale - $totalSold), false)" />
        </section>
    @endif

    @if ($showForm)
        {{-- Surface pleine : c'est un formulaire de saisie de montants, la
             lisibilite prime sur l'effet (section 70.4). --}}
        <x-ui.panel :title="$editingId ? 'Modifier le billet' : 'Nouveau billet'">
            <form wire:submit="save" class="space-y-1">
                <div class="grid gap-x-4 sm:grid-cols-[1.4fr_1fr]">
                    <x-ui.field label="Nom" for="tt-name" required :error="$errors->first('name')">
                        <input id="tt-name" type="text" wire:model="name" class="field"
                               placeholder="Standard, VIP, Early Bird..."
                               @error('name') aria-invalid="true" aria-describedby="tt-name-error" @enderror
                               required>
                    </x-ui.field>

                    <x-ui.field label="Prix" for="tt-price" required
                                hint="Laissez 0 pour un billet gratuit."
                                :error="$errors->first('price')">
                        <div class="relative">
                            <input id="tt-price" type="number" min="0" step="1" wire:model="price"
                                   class="field tabular pr-16"
                                   @error('price') aria-invalid="true" aria-describedby="tt-price-error" @enderror
                                   required>
                            {{-- L'unite DANS le champ plutot que dans le
                                 libelle : au moment de taper un montant, c'est
                                 la que le regard se trouve. --}}
                            <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm font-semibold text-grey-400">
                                FCFA
                            </span>
                        </div>
                    </x-ui.field>
                </div>

                <x-ui.field label="Description" for="tt-description"
                            hint="Facultatif. Ce que ce billet donne droit."
                            :error="$errors->first('description')">
                    <textarea id="tt-description" wire:model="description" rows="2" class="field"
                              placeholder="Ce que ce billet donne droit."
                              @error('description') aria-invalid="true" aria-describedby="tt-description-error" @enderror></textarea>
                </x-ui.field>

                <div class="grid gap-x-4 sm:grid-cols-2">
                    <x-ui.field label="Quantite" for="tt-quantity" required :error="$errors->first('quantity')">
                        <input id="tt-quantity" type="number" min="1" wire:model="quantity" class="field tabular"
                               @error('quantity') aria-invalid="true" aria-describedby="tt-quantity-error" @enderror
                               required>
                    </x-ui.field>

                    <x-ui.field label="Maximum par commande" for="tt-max" required :error="$errors->first('maxPerOrder')">
                        <input id="tt-max" type="number" min="1" wire:model="maxPerOrder" class="field tabular"
                               @error('maxPerOrder') aria-invalid="true" aria-describedby="tt-max-error" @enderror
                               required>
                    </x-ui.field>
                </div>

                <div class="grid gap-x-4 sm:grid-cols-2">
                    <x-ui.field label="Debut de vente" for="tt-start" hint="Facultatif."
                                :error="$errors->first('salesStartAt')">
                        <input id="tt-start" type="datetime-local" wire:model="salesStartAt" class="field"
                               @error('salesStartAt') aria-invalid="true" aria-describedby="tt-start-error" @enderror>
                    </x-ui.field>

                    <x-ui.field label="Fin de vente" for="tt-end" hint="Facultatif."
                                :error="$errors->first('salesEndAt')">
                        <input id="tt-end" type="datetime-local" wire:model="salesEndAt" class="field"
                               @error('salesEndAt') aria-invalid="true" aria-describedby="tt-end-error" @enderror>
                    </x-ui.field>
                </div>

                {{-- Les deux interrupteurs sont dans un cadre a part : ce ne
                     sont pas des valeurs a saisir mais des decisions de mise en
                     vente, et les melanger aux champs ferait passer a cote. --}}
                <fieldset class="glass-inset mt-2 grid gap-1 p-3 sm:grid-cols-2">
                    <legend class="sr-only">Mise en vente</legend>

                    <label class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-[var(--radius-xs)] px-1 text-sm text-ink">
                        <input type="checkbox" wire:model="isActive"
                               class="size-4 rounded border-grey-200 text-azure-600">
                        En vente
                    </label>
                    <label class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-[var(--radius-xs)] px-1 text-sm text-ink">
                        <input type="checkbox" wire:model="isVisible"
                               class="size-4 rounded border-grey-200 text-azure-600">
                        Visible sur la page publique
                    </label>
                </fieldset>

                {{-- Ordre inverse sur telephone : l'action principale au-dessus,
                     donc la plus proche du pouce. `flex-row-reverse` a partir de
                     `sm` remet l'ordre visuel conventionnel. --}}
                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row-reverse sm:justify-start">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Enregistrer</span>
                        <span wire:loading wire:target="save">Enregistrement…</span>
                    </button>
                    <button type="button" wire:click="cancelForm" class="btn btn-secondary">Annuler</button>
                </div>
            </form>
        </x-ui.panel>
    @endif

    <div class="space-y-3">
        @forelse ($ticketTypes as $ticketType)
            @php
                $sold = $ticketType->soldCount();
                /*
                 * Ce qu'un acheteur peut encore prendre MAINTENANT : les billets
                 * immobilises par une commande en cours de paiement en sont deja
                 * retires (section 17.1). L'organisateur voit donc le meme chiffre
                 * que le public, ce qui evite la question « pourquoi la page
                 * annonce-t-elle moins de places que mon tableau de bord ? ».
                 */
                $remaining = $ticketType->availableStock();
                $reason = $ticketType->unavailabilityReason();
                $share = $ticketType->quantity > 0 ? min(100, $sold / $ticketType->quantity * 100) : 0;
            @endphp

            <article wire:key="tt-{{ $ticketType->id }}" class="surface p-4 sm:p-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-display text-base font-bold text-ink">{{ $ticketType->name }}</h2>

                            @if ($reason)
                                <x-ui.badge tone="neutral">{{ $reason }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="success">En vente</x-ui.badge>
                            @endif

                            @unless ($ticketType->is_visible)
                                <x-ui.badge tone="neutral" bare>
                                    <x-lucide-eye-off class="size-3 shrink-0" stroke-width="2.25" aria-hidden="true" />
                                    Masque
                                </x-ui.badge>
                            @endunless
                        </div>

                        @if ($ticketType->description)
                            <p class="mt-1.5 text-sm text-grey-500">{{ $ticketType->description }}</p>
                        @endif

                        <p class="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-grey-500">
                            <span class="font-display font-bold text-ink tabular">{{ $ticketType->formattedPrice() }}</span>
                            <span class="tabular">{{ $sold }} / {{ $ticketType->quantity }} vendus</span>
                            <span class="tabular">{{ $remaining }} restants</span>
                        </p>

                        {{-- La barre dit d'un coup d'oeil ce que les trois
                             chiffres au-dessus demandent de comparer. --}}
                        <div class="progress-track mt-2.5 max-w-md">
                            <div class="progress-fill" style="--progress: {{ number_format($share, 2, '.', '') }}%"></div>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <button type="button" wire:click="edit({{ $ticketType->id }})" class="btn btn-secondary">
                            Modifier
                        </button>

                        @if ($sold === 0)
                            {{-- Supprimable uniquement tant que rien n'a ete
                                 vendu : au-dela, le type porte des billets deja
                                 en circulation. --}}
                            <button type="button" wire:click="delete({{ $ticketType->id }})"
                                    wire:confirm="Supprimer definitivement ce type de billet ?"
                                    class="btn btn-icon text-danger hover:bg-danger/10"
                                    aria-label="Supprimer {{ $ticketType->name }}">
                                <x-lucide-trash-2 class="size-4" stroke-width="2" aria-hidden="true" />
                            </button>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            @unless ($showForm)
                <div class="surface">
                    <x-ui.empty-state
                        icon="ticket"
                        title="Aucun billet pour le moment"
                        description="Creez au moins un type de billet pour que vos participants puissent reserver. Un billet gratuit se cree en laissant le prix a zero.">
                        <x-slot:action>
                            <button type="button" wire:click="create" class="btn btn-primary">
                                <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                                Creer un billet
                            </button>
                        </x-slot:action>
                    </x-ui.empty-state>
                </div>
            @endunless
        @endforelse
    </div>
</div>
