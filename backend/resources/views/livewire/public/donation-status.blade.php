@php
    $nbsp = "\u{202F}";
    $money = fn (int $amount) => number_format($amount, 0, ',', $nbsp).' F';
@endphp

{{--
    Suivi d'un don (sections 21.1, 39).

    Le rafraichissement automatique ne tourne QUE tant que le don est en
    attente : continuer a interroger le serveur pour une transaction close coute
    de la batterie et des donnees mobiles pour rien.

    Aucun emoji (regle 80.6).
--}}
<div @if ($donation->isPending()) wire:poll.5s="refreshStatus" @endif
     class="mx-auto w-full max-w-2xl px-5 pt-28 pb-16 sm:px-6 sm:pt-32">

    @if ($campaign)
        <a href="{{ $campaign->publicUrl() }}"
           class="inline-flex items-center gap-1.5 text-sm font-medium text-grey-500 transition-colors hover:text-azure-700">
            <x-lucide-arrow-left class="size-4" stroke-width="1.75" />
            {{ $campaign->name }}
        </a>
    @endif

    <div class="glass glass-panel mt-5 p-6 sm:p-10">
        @if ($donation->isPending())
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
                Une demande de paiement de <span class="font-semibold text-ink tabular">{{ $money($donation->amount) }}</span>
                a ete envoyee au {{ $donation->payer_phone }}. Composez votre code secret
                pour la confirmer.
            </p>

            @if ($secondsLeft > 0)
                <p class="mt-4 flex items-center gap-2 text-sm text-grey-500">
                    <x-lucide-clock class="size-4 shrink-0" stroke-width="1.75" />
                    <span class="tabular">
                        Il reste {{ floor($secondsLeft / 60) }} min {{ str_pad($secondsLeft % 60, 2, '0', STR_PAD_LEFT) }} s
                    </span>
                </p>
            @endif

        @elseif ($donation->isConfirmed())
            {{-- ------------------------------------------------------------
                 Don confirme : c'est le moment ou il entre dans le total
                 public (section 39)
            ------------------------------------------------------------- --}}
            <div class="flex items-center gap-3">
                <x-lucide-circle-check class="size-6 shrink-0 text-success" stroke-width="2" />
                <h1 class="font-display text-xl font-extrabold text-ink sm:text-2xl">
                    Merci pour votre don
                </h1>
            </div>

            {{-- Directive jamais collee a un mot : Blade ne reconnait pas un
                 `@if` precede d'un caractere de mot, mais compile quand meme le
                 `@endif` correspondant — la vue casse a la compilation. --}}
            <p class="mt-3 leading-relaxed text-grey-500">
                Votre don de <span class="font-semibold text-ink tabular">{{ $donation->formattedSubtotal() }}</span>
                est confirme.
                @if ($campaign)
                    Il compte desormais dans la collecte « {{ $campaign->name }} ».
                @endif
            </p>

            @if ($donation->is_anonymous)
                <p class="mt-2 text-sm text-grey-500">
                    Il apparait publiquement sous « Donateur anonyme ». Seul le montant est visible.
                </p>
            @endif

            @if ($progress)
                <div class="mt-6">
                    <div class="h-3 w-full overflow-hidden rounded-full bg-azure-100">
                        <div class="progress-fill h-full rounded-full bg-gradient-to-r from-azure-500 to-azure-600"
                             style="--progress: {{ $progress['displayPercentage'] }}%"></div>
                    </div>
                    <p class="mt-2 text-sm text-grey-500 tabular">
                        <span class="font-semibold text-ink">{{ $money($progress['collected']) }}</span>
                        collectes sur {{ $money($progress['goal']) }}
                        · {{ number_format($progress['donations'], 0, ',', $nbsp) }}
                        don{{ $progress['donations'] > 1 ? 's' : '' }}
                    </p>
                </div>
            @endif

            {{-- Section 53 : le recu se telecharge. C'est une piece comptable
                 qu'on classe ou qu'on joint a une note de frais — le PDF est
                 son format naturel. --}}
            <a href="{{ $donation->receiptUrl() }}" class="btn btn-ghost btn-pill mt-6">
                <x-lucide-download class="size-4" stroke-width="1.75" />
                Telecharger mon recu
            </a>

            <p class="mt-4 rounded-xl bg-canvas px-4 py-3 text-sm text-grey-500">
                Reference de votre don : <span class="font-semibold text-ink">{{ $donation->reference }}</span>.
                Conservez ce lien, il vous ramenera ici.
            </p>

        @else
            {{-- ------------------------------------------------------------
                 Echec ou annulation : dire quoi faire, pas seulement quoi
                 constater
            ------------------------------------------------------------- --}}
            <div class="flex items-center gap-3">
                <x-lucide-circle-alert class="size-6 shrink-0 text-danger" stroke-width="2" />
                <h1 class="font-display text-xl font-extrabold text-ink sm:text-2xl">
                    Le paiement n'a pas abouti
                </h1>
            </div>

            <p class="mt-3 leading-relaxed text-grey-500">
                {{ $donation->failure_reason ?? 'La demande de paiement n\'a pas ete validee a temps.' }}
                Aucun montant n'a ete preleve.
            </p>

            @if ($campaign && $campaign->acceptsDonations())
                <a href="{{ $campaign->publicUrl() }}" wire:navigate class="btn btn-primary btn-pill mt-6">
                    Reessayer
                </a>
            @endif
        @endif
    </div>

    {{-- Ecran de simulation, developpement uniquement (decision D-002). Il
         passe par le VRAI webhook, signe comme le sera celui d'un operateur. --}}
    @if ($canSimulate && $donation->isPending())
        <div class="surface mt-6 p-5">
            <p class="text-sm font-semibold text-ink">Simulation de l'operateur</p>
            <p class="mt-1 text-xs text-grey-500">
                Developpement uniquement. Le chemin exerce est celui de la production :
                webhook signe, idempotence, verification du montant.
            </p>

            <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                <form method="POST" action="{{ route('dev.payments.simulate', $donation->reference) }}" class="sm:flex-1">
                    @csrf
                    <input type="hidden" name="outcome" value="SUCCEEDED">
                    <button type="submit" class="btn btn-primary w-full">Valider le paiement</button>
                </form>

                <form method="POST" action="{{ route('dev.payments.simulate', $donation->reference) }}" class="sm:flex-1">
                    @csrf
                    <input type="hidden" name="outcome" value="FAILED">
                    <button type="submit" class="btn btn-ghost w-full">Refuser</button>
                </form>

                <form method="POST" action="{{ route('dev.payments.simulate', $donation->reference) }}" class="sm:flex-1">
                    @csrf
                    <input type="hidden" name="outcome" value="TIMEOUT">
                    <button type="submit" class="btn btn-ghost w-full">Ne rien renvoyer</button>
                </form>
            </div>
        </div>
    @endif
</div>
