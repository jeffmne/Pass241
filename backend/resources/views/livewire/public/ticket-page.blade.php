@php
    use App\Enums\TicketStatus;

    $ticket = $this->ticket;
    $valid = $ticket->status === TicketStatus::Active;
@endphp

<div class="mx-auto w-full max-w-md px-5 pt-28 pb-16 sm:px-6 sm:pt-32"
     x-data="{
         busy: false,
         failed: false,
         async download() {
             if (this.busy) return;
             this.busy = true;
             this.failed = false;

             try {
                 await window.Pass241.downloadTicketImage(
                     this.$refs.ticket,
                     'billet-{{ $ticket->reference }}.png',
                 );
             } catch (error) {
                 // Le billet reste accessible par son lien : un echec de
                 // capture ne doit pas ressembler a un billet perdu.
                 this.failed = true;
             } finally {
                 this.busy = false;
             }
         },
     }">

    {{-- Section 15.2 : le statut s'affiche AU-DESSUS du billet, jamais dessus.
         Un badge pose sur le billet generait la lecture du QR Code. --}}
    <div class="mb-4 flex justify-center">
        <span @class([
            'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold',
            'bg-success/10 text-success' => $valid,
            'bg-grey-200/80 text-grey-500' => $ticket->status === TicketStatus::Used,
            'bg-danger/10 text-danger' => ! $valid && $ticket->status !== TicketStatus::Used,
        ])>
            <x-dynamic-component
                :component="$valid ? 'lucide-circle-check' : 'lucide-circle-alert'"
                class="size-4" stroke-width="2" />
            {{ $ticket->status->label() }}
        </span>
    </div>

    <div class="flex justify-center">
        <x-glass-ticket :ticket="$ticket" :animate="true" x-ref="ticket" />
    </div>

    @unless ($valid)
        <p class="mt-5 flex items-start gap-2.5 rounded-xl border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-danger" stroke-width="1.75" />
            <span>
                @if ($ticket->status === TicketStatus::Used)
                    Ce billet a deja ete utilise
                    @if ($ticket->used_at)
                        le {{ $ticket->used_at->timezone(config('app.display_timezone'))->translatedFormat('j F \a\ H\hi') }}
                    @endif
                    et ne permet plus l'entree.
                @else
                    Ce billet ne permet pas l'entree.
                @endif
            </span>
        </p>
    @endunless

    {{-- Actions (section 15.2, point 10).
         `whitespace-nowrap` : sans lui, les libelles passent a la ligne dans
         une pilule et cassent la silhouette du bouton. --}}
    <div class="mt-6 flex flex-col gap-2.5 sm:flex-row">
        {{-- Telechargement en image, capturee sur le billet affiche
             lui-meme (resources/js/ticket-image.js). Le PDF ne circule plus
             que par email : ce que l'acheteur enregistre dans sa galerie doit
             etre exactement ce qu'il a sous les yeux. --}}
        <button type="button"
                class="btn btn-primary btn-pill flex-1 whitespace-nowrap"
                @click="download()"
                :disabled="busy">
            <x-lucide-download class="size-4" stroke-width="2" x-show="!busy" />
            <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" x-show="busy" x-cloak />
            <span x-text="busy ? 'Preparation' : 'Telecharger'">Telecharger</span>
        </button>

        {{-- Partage natif quand le navigateur le propose, copie du lien sinon.
             Sur telephone, le partage natif ouvre directement WhatsApp, qui
             est le canal reel de ce marche. --}}
        <button type="button"
                class="btn btn-ghost btn-pill flex-1 whitespace-nowrap"
                x-data="{ copied: false }"
                @click="
                    const url = '{{ $ticket->publicUrl() }}';
                    if (navigator.share) {
                        navigator.share({ title: 'Mon billet {{ $event->name }}', url });
                    } else {
                        navigator.clipboard.writeText(url);
                        copied = true;
                        setTimeout(() => copied = false, 2000);
                    }
                ">
            <x-lucide-share-2 class="size-4" stroke-width="2" x-show="!copied" />
            <x-lucide-check class="size-4 text-success" stroke-width="2" x-show="copied" x-cloak />
            <span x-text="copied ? 'Lien copie' : 'Partager'">Partager</span>
        </button>
    </div>

    <p x-show="failed" x-cloak
       class="mt-4 flex items-start gap-2.5 rounded-xl border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-ink">
        <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-danger" stroke-width="1.75" />
        <span>
            L'image n'a pas pu etre creee sur cet appareil. Votre billet reste
            valide : conservez ce lien, ou faites une capture d'ecran.
        </span>
    </p>

    <p class="mt-6 text-center text-xs leading-relaxed text-grey-400">
        Conservez ce lien : il vous ramene a votre billet, meme sans adresse email.
        @if ($ticket->order?->email)
            Une copie en PDF a ete envoyee a {{ $ticket->order->email }}.
        @endif
    </p>

    <p class="mt-4 text-center text-sm">
        <a href="{{ route('tickets.mine') }}" wire:navigate class="font-semibold text-azure-600 hover:text-azure-700">
            Voir tous mes billets
        </a>
    </p>
</div>
