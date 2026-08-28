@php
    $money = fn (int $amount) => \App\Support\Money::formatShort($amount);
@endphp

{{--
    Formulaire de don (sections 39, 40, 50).

    Le donateur doit pouvoir contribuer vite : un montant, un numero, c'est
    tout. Le nom et l'adresse restent facultatifs.

    AUCUN CHAMP DE MESSAGE (section 42), et ce n'est pas un oubli d'interface :
    aucune colonne n'existe pour en accueillir un.

    Aucun emoji (regle 80.6).
--}}
<div>
    {{-- Montants proposes. Un donateur qui hesite donne moins, ou renonce. --}}
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach ($presets as $preset)
            <button type="button" wire:click="choose({{ $preset }})"
                    @class([
                        'min-h-12 rounded-xl border px-3 py-2.5 text-sm font-semibold transition-colors tabular',
                        'border-azure-500 bg-azure-50 text-azure-700' => $amount === $preset,
                        'border-grey-200 bg-white text-ink hover:border-azure-300' => $amount !== $preset,
                    ])>
                {{ $money($preset) }}
            </button>
        @endforeach
    </div>

    <div class="mt-3">
        <label for="amount" class="mb-1.5 block text-sm font-medium text-ink">
            Ou un autre montant <span class="font-normal text-grey-400">(F CFA)</span>
        </label>
        <input id="amount" type="number" inputmode="numeric" min="500" step="500"
               wire:model.live.debounce.400ms="amount" class="field tabular"
               placeholder="2 500"
               aria-invalid="{{ $errors->has('amount') ? 'true' : 'false' }}">
        @error('amount')
            <p class="mt-1.5 flex items-start gap-1.5 text-sm text-danger">
                <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
                <span>{{ $message }}</span>
            </p>
        @enderror
    </div>

    @if ($amount > 0)
        {{-- Eclatement du montant (decision D-023).

             Les frais sont annonces AVANT le paiement, et separement : le
             donateur doit savoir que la cause recevra bien le montant qu'il a
             choisi, et que le supplement est le prix du transport de l'argent.
             Ces chiffres viennent du serveur ; ils sont recalcules a la
             creation du don et ne font pas foi ici (regle 80.5). --}}
        <dl class="mt-5 space-y-1.5 rounded-2xl bg-canvas px-4 py-3 text-sm">
            <div class="flex items-center justify-between">
                <dt class="text-grey-500">Votre don</dt>
                <dd class="font-semibold text-ink tabular">{{ $money((int) $amount) }}</dd>
            </div>
            <div class="flex items-center justify-between">
                <dt class="text-grey-500">Frais de paiement</dt>
                <dd class="text-grey-500 tabular">{{ $money($pspFee) }}</dd>
            </div>
            <div class="flex items-center justify-between border-t border-grey-200 pt-1.5">
                <dt class="font-semibold text-ink">Total a payer</dt>
                <dd class="font-display font-extrabold text-ink tabular">{{ $money($total) }}</dd>
            </div>
        </dl>
    @endif

    <form wire:submit="donate" class="mt-5">
        {{-- Section 40 : le donateur choisit son anonymat. Il ne porte que sur
             l'identite — le montant reste public dans tous les cas, et l'ecran
             le dit plutot que de le laisser decouvrir. --}}
        <label class="flex min-h-11 items-start gap-2.5 text-sm text-ink">
            <input type="checkbox" wire:model.live="isAnonymous" class="mt-0.5 size-4 rounded border-grey-200">
            <span>
                Donner anonymement
                <span class="mt-0.5 block text-xs text-grey-500">
                    Votre nom n'apparaitra pas. Le montant de votre don, lui, reste public.
                </span>
            </span>
        </label>

        @unless ($isAnonymous)
            <div class="mt-4">
                <label for="donorName" class="mb-1.5 block text-sm font-medium text-ink">
                    Votre nom <span class="font-normal text-grey-400">(facultatif)</span>
                </label>
                <input id="donorName" type="text" autocomplete="name" wire:model="donorName"
                       class="field" placeholder="Marie Nzeng">
                @error('donorName') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
            </div>
        @endunless

        <div class="mt-4">
            <label for="donorEmail" class="mb-1.5 block text-sm font-medium text-ink">
                Adresse email <span class="font-normal text-grey-400">(facultatif)</span>
            </label>
            <input id="donorEmail" type="email" autocomplete="email" wire:model="donorEmail"
                   class="field" placeholder="marie@exemple.ga">
            <p class="mt-1.5 text-xs text-grey-400">
                Pour recevoir votre recu. Elle n'apparait jamais publiquement.
            </p>
            @error('donorEmail') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
        </div>

        {{-- Section 16.1 : l'operateur est un choix explicite, jamais une
             deduction faite depuis le numero. --}}
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

        <button type="submit" class="btn btn-primary btn-pill mt-5" wire:loading.attr="disabled">
            <x-lucide-hand-coins class="size-4" stroke-width="2" />
            <span wire:loading.remove wire:target="donate">Faire mon don</span>
            <span wire:loading wire:target="donate">Envoi en cours</span>
        </button>

        <p class="mt-3 text-xs leading-relaxed text-grey-400">
            Pass241 encaisse votre don et le reverse a l'organisateur, apres commission.
            Aucun message ne peut etre joint a un don.
        </p>
    </form>
</div>
