@php
    use App\Enums\OrderStatus as Status;

    $order = $this->order;
    $status = $order->status;
    $tz = config('app.display_timezone');
@endphp

{{-- Le rafraichissement automatique ne tourne QUE tant que la commande est en
     attente. Continuer a interroger le serveur pour une transaction close
     coute de la batterie et des donnees mobiles pour rien. --}}
<div @if ($order->isPending()) wire:poll.5s="refreshStatus" @endif
     class="mx-auto w-full max-w-2xl px-5 pt-28 pb-16 sm:px-6 sm:pt-32">

    <a href="{{ $event->publicUrl() }}"
       class="inline-flex items-center gap-1.5 text-sm font-medium text-grey-500 transition-colors hover:text-azure-700">
        <x-lucide-arrow-left class="size-4" stroke-width="1.75" />
        {{ $event->name }}
    </a>

    <div class="glass glass-panel mt-5 p-6 sm:p-10">

        @if ($order->isPending())
            {{-- ------------------------------------------------------------
                 En attente de validation sur le telephone (section 21.1)
            ------------------------------------------------------------- --}}
            <div class="text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-warning/10">
                    <x-lucide-smartphone class="size-7 text-warning" stroke-width="1.5" />
                </span>

                <h1 class="mt-4 font-display text-2xl font-extrabold text-ink sm:text-3xl">
                    Validez sur votre telephone
                </h1>

                <p class="mx-auto mt-3 max-w-md leading-relaxed text-grey-500">
                    Un message {{ $order->payment_provider->label() }} a ete envoye au
                    <span class="font-semibold text-ink tabular">{{ $order->payer_phone }}</span>.
                    Saisissez votre code pour confirmer le paiement.
                </p>

                @if ($secondsLeft > 0)
                    {{-- Compte a rebours cote client : il descend a chaque
                         seconde sans solliciter le serveur, qui n'est
                         interroge que toutes les cinq secondes. --}}
                    <p class="mt-6 text-sm text-grey-500"
                       x-data="{ left: {{ $secondsLeft }} }"
                       x-init="setInterval(() => { if (left > 0) left-- }, 1000)">
                        Temps restant
                        <span class="ml-1 font-display text-lg font-bold text-ink tabular"
                              x-text="Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0')">
                        </span>
                    </p>
                @endif

                <p class="mt-6 flex items-center justify-center gap-2 text-sm text-grey-400">
                    <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" />
                    En attente de confirmation
                </p>
            </div>

        @elseif ($order->isPaid())
            {{-- ------------------------------------------------------------
                 Paiement confirme (section 15.1)
            ------------------------------------------------------------- --}}
            <div class="text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-success/10">
                    <x-lucide-circle-check class="size-7 text-success" stroke-width="1.5" />
                </span>

                <h1 class="mt-4 font-display text-2xl font-extrabold text-ink sm:text-3xl">
                    Paiement confirme
                </h1>

                <p class="mx-auto mt-3 max-w-md leading-relaxed text-grey-500">
                    {{ $order->tickets->count() }} billet{{ $order->tickets->count() > 1 ? 's' : '' }}
                    {{ $order->tickets->count() > 1 ? 'ont ete emis' : 'a ete emis' }} a votre nom.
                </p>
            </div>

            @if ($order->tickets->isNotEmpty())
                <ul class="mt-8 space-y-2">
                    @foreach ($order->tickets as $ticket)
                        <li class="glass-inset flex items-center justify-between gap-4 p-4">
                            <span class="min-w-0">
                                <span class="block font-display text-base font-bold text-ink tabular">
                                    {{ $ticket->reference }}
                                </span>
                                <span class="mt-0.5 block text-sm text-grey-500">
                                    {{ $ticket->holder_name }}
                                </span>
                            </span>
                            <x-status-badge :status="$ticket->status" />
                        </li>
                    @endforeach
                </ul>

                {{-- Le QR Code et le telechargement arrivent au module suivant.
                     Le dire est plus honnete qu'un bouton inerte. --}}
                <p class="mt-6 flex items-start gap-2.5 rounded-xl border border-grey-200 bg-white px-4 py-3 text-sm text-grey-500">
                    <x-lucide-info class="mt-0.5 size-4 shrink-0 text-azure-600" stroke-width="1.75" />
                    <span>
                        L'affichage du QR Code et le telechargement du billet arrivent tres
                        prochainement. Conservez ce lien : il vous ramenera a vos billets.
                    </span>
                </p>
            @endif

        @else
            {{-- ------------------------------------------------------------
                 Echec, annulation ou remboursement
            ------------------------------------------------------------- --}}
            <div class="text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-danger/10">
                    <x-lucide-circle-alert class="size-7 text-danger" stroke-width="1.5" />
                </span>

                <h1 class="mt-4 font-display text-2xl font-extrabold text-ink sm:text-3xl">
                    {{ $status === Status::Failed ? 'Paiement non abouti' : $status->label() }}
                </h1>

                <p class="mx-auto mt-3 max-w-md leading-relaxed text-grey-500">
                    {{ $order->failure_reason ?: 'Cette commande n\'a pas ete finalisee.' }}
                </p>

                <p class="mx-auto mt-2 max-w-md text-sm text-grey-400">
                    Aucun montant n'a ete debite. Vos billets sont revenus a la vente.
                </p>

                <a href="{{ $event->publicUrl() }}#billets" class="btn btn-primary btn-pill mt-7">
                    Reessayer
                </a>
            </div>
        @endif

        @error('payment')
            <p class="mt-6 flex items-start gap-2.5 rounded-xl border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-ink">
                <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-danger" stroke-width="1.75" />
                <span>{{ $message }}</span>
            </p>
        @enderror

        {{-- Recapitulatif : surface pleine, chiffres tabulaires. --}}
        <dl class="mt-8 space-y-2.5 border-t border-white/70 pt-6 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-grey-500">Reference</dt>
                <dd class="font-display font-bold text-ink tabular">{{ $order->reference }}</dd>
            </div>

            @foreach ($order->items as $item)
                <div class="flex justify-between gap-4">
                    <dt class="text-grey-500">
                        {{ $item->ticket_type_name }}
                        <span class="tabular">&times; {{ $item->quantity }}</span>
                    </dt>
                    <dd class="text-ink tabular">{{ $item->formattedSubtotal() }}</dd>
                </div>
            @endforeach

            <div class="flex justify-between gap-4 border-t border-grey-200/70 pt-2.5">
                <dt class="font-medium text-ink">Total</dt>
                <dd class="font-display text-base font-bold text-ink tabular">
                    {{ $order->formattedAmount() }}
                </dd>
            </div>

            <div class="flex justify-between gap-4">
                <dt class="text-grey-500">Evenement</dt>
                <dd class="text-ink">{{ $event->name }}</dd>
            </div>

            <div class="flex justify-between gap-4">
                <dt class="text-grey-500">Date</dt>
                <dd class="text-ink tabular">
                    {{ $event->starts_at->timezone($tz)->translatedFormat('j F Y \a\ H\hi') }}
                </dd>
            </div>
        </dl>
    </div>

    @if ($canSimulate && $order->isPending())
        {{--
            Ecran de simulation, developpement uniquement.

            Aucun acces Airtel ni Moov n'est disponible (decision D-002) : ces
            trois boutons reproduisent les trois issues de la section 21.1 pour
            que le parcours complet soit verifiable a la main. Ils n'existent
            pas des lors qu'un vrai operateur est branche.
        --}}
        <div class="surface mt-5 p-4 sm:p-5">
            <p class="flex items-center gap-2 text-sm font-semibold text-ink">
                <x-lucide-flask-conical class="size-4 shrink-0 text-grey-400" stroke-width="1.75" />
                Simulation — developpement uniquement
            </p>
            <p class="mt-1.5 text-sm text-grey-500">
                Reproduit ce que renverrait l'operateur. Le paiement passe par le meme
                webhook signe qu'en production.
            </p>

            <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                <form method="POST" action="{{ route('dev.payments.simulate', $order->reference) }}" class="sm:flex-1">
                    @csrf
                    <input type="hidden" name="outcome" value="SUCCEEDED">
                    <button type="submit" class="btn btn-primary w-full">Valider le paiement</button>
                </form>

                <form method="POST" action="{{ route('dev.payments.simulate', $order->reference) }}" class="sm:flex-1">
                    @csrf
                    <input type="hidden" name="outcome" value="FAILED">
                    <button type="submit" class="btn btn-ghost w-full">Refuser</button>
                </form>

                <form method="POST" action="{{ route('dev.payments.simulate', $order->reference) }}" class="sm:flex-1">
                    @csrf
                    <input type="hidden" name="outcome" value="TIMEOUT">
                    <button type="submit" class="btn btn-ghost w-full">Ne rien renvoyer</button>
                </form>
            </div>
        </div>
    @endif
</div>
