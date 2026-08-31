@php
    $money = fn (int $amount) => \App\Support\Money::formatShort($amount);
@endphp

{{--
    Suivi d'un vote paye (sections 21.1, 86.4).

    Le rafraichissement automatique ne tourne QUE tant que le bulletin est en
    attente : continuer a interroger le serveur pour une transaction close coute
    de la batterie et des donnees mobiles pour rien.

    Aucun emoji (regle 80.6).
--}}
<div @if ($ballot->isPending()) wire:poll.5s="refreshStatus" @endif
     class="mx-auto w-full max-w-2xl px-5 pt-28 pb-16 sm:px-6 sm:pt-32">

    @if ($poll)
        <a href="{{ $poll->publicUrl() }}"
           class="inline-flex items-center gap-1.5 text-sm font-medium text-grey-500 transition-colors hover:text-azure-700">
            <x-lucide-arrow-left class="size-4" stroke-width="1.75" />
            {{ $poll->name }}
        </a>
    @endif

    <div class="glass glass-panel mt-5 p-6 sm:p-10">
        @if ($ballot->isPending())
            {{-- ------------------------------------------------------------
                 En attente de validation sur le telephone (section 21.1)
            ------------------------------------------------------------- --}}
            <div class="flex items-center gap-3">
                <x-lucide-loader-circle class="size-5 shrink-0 animate-spin text-azure-600" stroke-width="2" />
                <h1 class="font-display text-xl font-extrabold text-ink sm:text-2xl">
                    Validez sur votre telephone
                </h1>
            </div>

            <p class="mt-3 leading-relaxed text-grey-500">
                Une demande de paiement de
                <span class="font-semibold text-ink tabular">{{ $money($ballot->amount) }}</span>
                a ete envoyee au {{ $ballot->payer_phone }}. Composez votre code secret
                pour la confirmer.
            </p>

            {{-- Dit sans detour : tant que le paiement n'est pas confirme, la
                 voix n'existe pour personne (section 86.4). Laisser croire le
                 contraire ferait fermer la page trop tot. --}}
            <p class="mt-3 text-sm leading-relaxed text-grey-500">
                Votre vote sera compte des la confirmation du paiement, pas avant.
            </p>

            @if ($secondsLeft > 0)
                <p class="mt-4 flex items-center gap-2 text-sm text-grey-500">
                    <x-lucide-clock class="size-4 shrink-0" stroke-width="1.75" />
                    <span class="tabular">
                        Il reste {{ floor($secondsLeft / 60) }} min {{ str_pad($secondsLeft % 60, 2, '0', STR_PAD_LEFT) }} s
                    </span>
                </p>
            @endif

        @elseif ($ballot->isConfirmed())
            {{-- ------------------------------------------------------------
                 Confirme : c'est le moment ou la voix entre au classement
            ------------------------------------------------------------- --}}
            <div class="flex items-center gap-3">
                <x-lucide-circle-check class="size-6 shrink-0 text-success" stroke-width="2" />
                <h1 class="font-display text-xl font-extrabold text-ink sm:text-2xl">
                    Votre vote est compte
                </h1>
            </div>

            @if ($candidate)
                <p class="mt-3 leading-relaxed text-grey-500">
                    <span class="font-display font-bold text-ink tabular">{{ $ballot->quantity }}</span>
                    vote{{ $ballot->quantity > 1 ? 's' : '' }} pour
                    <span class="font-semibold text-ink">{{ $candidate->displayName() }}</span>@if ($category)
                        , dans la categorie {{ $category->name }}@endif.
                </p>
            @endif

        @else
            {{-- ------------------------------------------------------------
                 Echec ou expiration : la voix a ete rendue
            ------------------------------------------------------------- --}}
            <div class="flex items-center gap-3">
                <x-lucide-circle-alert class="size-6 shrink-0 text-danger" stroke-width="2" />
                <h1 class="font-display text-xl font-extrabold text-ink sm:text-2xl">
                    Le paiement n'a pas abouti
                </h1>
            </div>

            <p class="mt-3 leading-relaxed text-grey-500">
                {{ $ballot->failure_reason ?? 'La demande de paiement n\'a pas ete confirmee.' }}
            </p>

            {{-- L'information qui evite l'appel au support : le votant n'a rien
                 perdu, ni son argent ni son droit de vote. --}}
            <p class="mt-3 text-sm leading-relaxed text-grey-500">
                Aucun montant n'a ete preleve, et votre vote vous est rendu : vous pouvez
                recommencer.
            </p>

            @if ($poll)
                <a href="{{ $poll->publicUrl() }}" class="btn btn-primary btn-pill mt-6">
                    Retourner au vote
                </a>
            @endif
        @endif

        {{-- Recapitulatif, quel que soit l'etat. Les frais sont montres a part
             (decision D-023) : le votant doit voir que le supplement est le
             prix du transport de l'argent, pas une marge cachee. --}}
        <dl class="surface mt-6 space-y-1.5 px-4 py-3 text-sm">
            <div class="flex items-center justify-between">
                <dt class="text-grey-500">
                    {{ $ballot->quantity }} vote{{ $ballot->quantity > 1 ? 's' : '' }}
                </dt>
                <dd class="text-ink tabular">{{ $money($ballot->subtotal) }}</dd>
            </div>

            @if ($ballot->psp_fee_amount > 0)
                <div class="flex items-center justify-between">
                    <dt class="text-grey-500">Frais de paiement</dt>
                    <dd class="text-grey-500 tabular">{{ $money($ballot->psp_fee_amount) }}</dd>
                </div>
            @endif

            <div class="flex items-center justify-between border-t border-grey-200 pt-1.5">
                <dt class="font-semibold text-ink">Total</dt>
                <dd class="font-display font-extrabold text-ink tabular">{{ $money($ballot->amount) }}</dd>
            </div>
        </dl>

        <p class="mt-3 text-xs text-grey-400">
            Reference <span class="tabular">{{ $ballot->reference }}</span>
        </p>
    </div>
</div>
