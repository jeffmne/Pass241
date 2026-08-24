@php
    use App\Support\Money;
@endphp

<div>
    @if ($placedOrder)
        {{--
            Commande creee, paiement en attente.

            Le module de paiement arrive au jalon suivant : plutot qu'un faux
            bouton « Payer », l'ecran dit exactement ou en est la commande et
            donne la reference. Un bouton qui ne fait rien ferait perdre plus
            de confiance qu'une attente annoncee.
        --}}
        <div class="glass-inset p-6 text-center sm:p-8">
            <x-lucide-clock class="mx-auto size-8 text-warning" stroke-width="1.5" />

            <h2 class="mt-3 font-display text-xl font-extrabold text-ink">Commande enregistree</h2>

            <p class="mx-auto mt-2 max-w-md leading-relaxed text-grey-500">
                Votre commande est reservee. Le paiement par
                {{ $placedOrder->payment_provider->label() }} sera active tres prochainement.
            </p>

            <dl class="mx-auto mt-6 max-w-xs space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-grey-500">Reference</dt>
                    <dd class="font-display font-bold text-ink tabular">{{ $placedOrder->reference }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-grey-500">Billets</dt>
                    <dd class="font-semibold text-ink tabular">{{ $placedOrder->totalTickets() }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-t border-grey-200/70 pt-2">
                    <dt class="text-grey-500">Total</dt>
                    <dd class="font-display text-base font-bold text-ink tabular">
                        {{ $placedOrder->formattedAmount() }}
                    </dd>
                </div>
            </dl>

            <p class="mx-auto mt-5 max-w-sm text-xs leading-relaxed text-grey-400">
                Notez cette reference : elle permet de retrouver votre commande.
            </p>

            <button type="button" wire:click="startOver" class="btn btn-ghost mt-5">
                Commander a nouveau
            </button>
        </div>

    @elseif ($ticketTypes->isEmpty())
        <div class="glass-inset p-6 sm:p-8">
            <h2 class="font-display text-xl font-extrabold text-ink sm:text-2xl">Billets</h2>
            <p class="mt-3 leading-relaxed text-grey-500">
                La billetterie de cet evenement ouvre prochainement.
            </p>
        </div>

    @else
        <div class="glass-inset p-6 sm:p-8">
            <h2 class="font-display text-xl font-extrabold text-ink sm:text-2xl">Billets</h2>

            {{-- ------------------------------------------------------------
                 Choix des billets
            ------------------------------------------------------------- --}}
            <div class="mt-5 space-y-3">
                @foreach ($ticketTypes as $ticketType)
                    @php
                        $reason = $ticketType->unavailabilityReason();
                        $quantity = (int) ($quantities[$ticketType->id] ?? 0);
                        $max = $ticketType->maxPurchasableQuantity();
                    @endphp

                    <div @class([
                        'flex flex-col gap-4 rounded-2xl border p-4 sm:flex-row sm:items-center sm:justify-between',
                        'border-azure-500 bg-azure-50' => $quantity > 0,
                        'border-grey-200 bg-white' => $quantity === 0 && ! $reason,
                        'border-grey-200 bg-white/60' => $reason,
                    ])>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-display text-base font-bold text-ink">{{ $ticketType->name }}</p>
                                @if ($reason)
                                    <span class="rounded-full bg-grey-200/70 px-2.5 py-0.5 text-xs font-semibold text-grey-500">
                                        {{ $reason }}
                                    </span>
                                @endif
                            </div>

                            @if ($ticketType->description)
                                <p class="mt-1 text-sm leading-relaxed text-grey-500">{{ $ticketType->description }}</p>
                            @endif

                            <p class="mt-1.5 font-display font-bold text-azure-700 tabular">
                                {{ $ticketType->formattedPrice() }}
                            </p>
                        </div>

                        @unless ($reason)
                            {{-- Cibles tactiles de 44px : ce sont les boutons
                                 les plus utilises de la page, sur telephone. --}}
                            <div class="flex shrink-0 items-center gap-1">
                                <button type="button"
                                        wire:click="decrement({{ $ticketType->id }})"
                                        @disabled($quantity === 0)
                                        class="flex size-11 items-center justify-center rounded-xl border border-grey-200 bg-white text-ink transition-colors hover:border-azure-300 disabled:opacity-40"
                                        aria-label="Retirer un billet {{ $ticketType->name }}">
                                    <x-lucide-minus class="size-4" stroke-width="2.25" />
                                </button>

                                <span class="w-10 text-center font-display text-lg font-bold text-ink tabular"
                                      aria-live="polite"
                                      aria-label="{{ $quantity }} billet(s) {{ $ticketType->name }}">
                                    {{ $quantity }}
                                </span>

                                <button type="button"
                                        wire:click="increment({{ $ticketType->id }})"
                                        @disabled($quantity >= $max)
                                        class="flex size-11 items-center justify-center rounded-xl border border-grey-200 bg-white text-ink transition-colors hover:border-azure-300 disabled:opacity-40"
                                        aria-label="Ajouter un billet {{ $ticketType->name }}">
                                    <x-lucide-plus class="size-4" stroke-width="2.25" />
                                </button>
                            </div>
                        @endunless
                    </div>
                @endforeach
            </div>

            @error('quantities')
                <p class="mt-4 flex items-start gap-2 rounded-xl border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-ink">
                    <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-danger" stroke-width="1.75" />
                    <span>{{ $message }}</span>
                </p>
            @enderror

            {{-- ------------------------------------------------------------
                 Coordonnees et paiement — n'apparaissent qu'une fois un
                 billet choisi. Afficher un formulaire de paiement a quelqu'un
                 qui n'a rien selectionne allonge la page pour rien.
            ------------------------------------------------------------- --}}
            @if ($ticketCount > 0)
                <form wire:submit="placeOrder" class="mt-6 border-t border-grey-200/70 pt-6">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="firstName" class="mb-1.5 block text-sm font-medium text-ink">Prenom</label>
                            <input id="firstName" type="text" autocomplete="given-name" wire:model="firstName"
                                   class="field" aria-invalid="{{ $errors->has('firstName') ? 'true' : 'false' }}" required>
                            @error('firstName') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="lastName" class="mb-1.5 block text-sm font-medium text-ink">Nom</label>
                            <input id="lastName" type="text" autocomplete="family-name" wire:model="lastName"
                                   class="field" aria-invalid="{{ $errors->has('lastName') ? 'true' : 'false' }}" required>
                            @error('lastName') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label for="email" class="mb-1.5 block text-sm font-medium text-ink">
                                Adresse email <span class="font-normal text-grey-400">(facultatif)</span>
                            </label>
                            <input id="email" type="email" inputmode="email" autocapitalize="none"
                                   autocomplete="email" wire:model="email" class="field"
                                   aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}">
                            @error('email') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                            <p class="mt-1.5 text-xs text-grey-400">
                                Votre billet s'affiche immediatement apres le paiement. L'email n'est
                                qu'une copie de secours.
                            </p>
                        </div>
                    </div>

                    {{-- Section 16.1 : l'operateur est un choix explicite de
                         l'acheteur, jamais une deduction faite depuis son
                         numero. --}}
                    <fieldset class="mt-5" x-data="{ chosen: $wire.provider }">
                        <legend class="mb-1.5 block text-sm font-medium text-ink">Moyen de paiement</legend>

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($providers as $paymentProvider)
                                <label
                                    class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 text-sm transition-colors"
                                    :class="chosen === '{{ $paymentProvider->value }}'
                                        ? 'border-azure-500 bg-azure-50 font-semibold text-azure-700'
                                        : 'border-grey-200 bg-white text-grey-500 hover:border-azure-300'">
                                    <input type="radio" name="provider" value="{{ $paymentProvider->value }}"
                                           wire:model="provider"
                                           @change="chosen = '{{ $paymentProvider->value }}'"
                                           class="size-4">
                                    {{ $paymentProvider->label() }}
                                </label>
                            @endforeach
                        </div>

                        @error('provider') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </fieldset>

                    <div class="mt-4">
                        <label for="payerPhone" class="mb-1.5 block text-sm font-medium text-ink">
                            Numero de telephone
                        </label>
                        <input id="payerPhone" type="tel" inputmode="tel" autocomplete="tel"
                               wire:model="payerPhone" class="field" placeholder="074 00 00 00"
                               aria-invalid="{{ $errors->has('payerPhone') ? 'true' : 'false' }}" required>
                        @error('payerPhone')
                            <p class="mt-1.5 flex items-start gap-1.5 text-sm text-danger">
                                <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                        <p class="mt-1.5 text-xs text-grey-400">
                            C'est sur ce numero que vous validerez le paiement.
                        </p>
                    </div>

                    {{-- Total : surface pleine, chiffres tabulaires. Un montant
                         est la donnee la plus relue de l'ecran. --}}
                    <div class="mt-6 flex items-baseline justify-between gap-4 rounded-xl bg-white px-4 py-3.5">
                        <span class="text-sm font-medium text-grey-500">
                            {{ $ticketCount }} billet{{ $ticketCount > 1 ? 's' : '' }}
                        </span>
                        <span class="font-display text-2xl font-extrabold text-ink tabular">
                            {{ Money::format($total) }}
                        </span>
                    </div>

                    <button type="submit" class="btn btn-primary btn-pill btn-lg mt-4 w-full"
                            wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="placeOrder">Continuer vers le paiement</span>
                        <span wire:loading wire:target="placeOrder" class="flex items-center gap-2">
                            <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" />
                            Enregistrement
                        </span>
                    </button>

                    <p class="mt-3 flex items-center justify-center gap-2 text-xs text-grey-400">
                        <x-lucide-shield-check class="size-3.5 shrink-0" stroke-width="1.75" />
                        Aucune inscription necessaire.
                    </p>
                </form>
            @endif
        </div>
    @endif
</div>
