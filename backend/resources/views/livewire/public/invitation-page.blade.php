@php
    use App\Enums\InvitationStatus;

    $tz = config('app.display_timezone');
    $state = $invitation?->displayStatus();
@endphp

{{--
    Page d'invitation (section 25).

    L'invite arrive par un lien recu dans un message. Il n'a pas de compte, il
    n'en creera pas, et il decide en une seconde s'il vient. La page tient donc
    en une phrase et un bouton.

    Rien ne lui est demande : son nom est deja la, c'est l'organisateur qui l'a
    invite. Lui redemander ce qu'on sait deja ajouterait un formulaire entre lui
    et son billet.
--}}
<div class="mx-auto w-full max-w-2xl px-5 pt-28 pb-16 sm:px-6 sm:pt-32">
    <div class="glass glass-panel p-6 text-center sm:p-10">
        <p class="text-xs font-semibold tracking-[0.28em] text-azure-600 uppercase">Invitation</p>

        <h1 class="mt-3 font-display text-2xl leading-tight font-extrabold text-ink sm:text-3xl">
            {{ $event?->name }}
        </h1>

        <p class="mt-3 text-grey-500">
            @if ($event?->starts_at)
                {{ ucfirst($event->starts_at->timezone($tz)->translatedFormat('l j F Y \a\ H\hi')) }}
            @endif
            @if ($event?->location?->name)
                · {{ $event->location->name }}
            @endif
        </p>

        <div class="glass-inset mt-6 inline-flex flex-col gap-1 px-6 py-4">
            <span class="text-[11px] font-semibold tracking-[0.18em] text-grey-500 uppercase">Invite</span>
            <span class="font-display text-lg font-extrabold text-ink">{{ $invitation?->guest_name }}</span>
            @if ($ticketTypeName)
                <span class="text-sm text-azure-700">{{ $ticketTypeName }}</span>
            @endif
        </div>

        @if ($error)
            <p class="mt-6 rounded-2xl bg-danger/10 px-4 py-3 text-sm font-semibold text-danger">
                {{ $error }}
            </p>
        @endif

        @if ($ticket)
            {{-- Deja acceptee : le lien rouvert doit ramener au billet, pas
                 annoncer une erreur. C'est le cas courant — on rouvre un lien
                 le jour de l'evenement pour retrouver son QR. --}}
            <p class="mt-6 text-grey-500">Votre billet vous attend.</p>

            <a href="{{ $ticket->publicUrl() }}" class="btn btn-primary btn-pill mt-4">
                Voir mon billet
            </a>

        @elseif ($state === InvitationStatus::Cancelled->value)
            <p class="mt-6 text-grey-500">
                Cette invitation a ete annulee par l'organisateur.
            </p>

        @elseif ($state === InvitationStatus::Expired->value)
            <p class="mt-6 text-grey-500">
                Cette invitation a expire le
                {{ $invitation?->expires_at?->timezone($tz)->translatedFormat('j F \a\ H\hi') }}.
                La place est repartie a la vente.
            </p>

            @if ($event)
                <a href="{{ $event->publicUrl() }}" class="btn btn-ghost btn-pill mt-4">
                    Voir l'evenement
                </a>
            @endif

        @else
            <p class="mt-6 text-grey-500">
                Une place vous est reservee. Confirmez pour recevoir votre billet.
            </p>

            <p class="mt-1 text-sm text-grey-400">
                A confirmer avant le
                {{ $invitation?->expires_at?->timezone($tz)->translatedFormat('j F \a\ H\hi') }}.
            </p>

            <button type="button" wire:click="accept" wire:loading.attr="disabled"
                    class="btn btn-primary btn-pill mt-6">
                <span wire:loading.remove wire:target="accept">J'accepte l'invitation</span>
                <span wire:loading wire:target="accept">Emission du billet...</span>
            </button>

            <p class="mt-4 text-xs text-grey-400">
                Gratuit. Aucun compte n'est necessaire.
            </p>
        @endif
    </div>
</div>
