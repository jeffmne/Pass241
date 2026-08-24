@props([
    'eventName',
    'date',
    'venue',
    'ticketType',
    'holder',
    'reference',
    'qrData',
    'animate' => false,
])

@php
    $qr = app(\App\Services\Ticketing\QrRenderer::class)->svg($qrData, 240);
@endphp

{{--
    Le billet en verre liquide — element signature de Pass241 (section 70.5).

    Trois contraintes non negociables sont tenues ici :

    - le QR Code vit dans un encart blanc totalement opaque, jamais sur le
      verre : un flou residuel derriere le code peut faire echouer un scan a
      l'entree, et un scan rate se paie en file d'attente (regle 70.4) ;
    - un seul niveau de verre par ecran, donc aucune carte en verre imbriquee ;
    - le QR encode un jeton, jamais un identifiant brut (section 18).

    Les encoches et la ligne de dechirure viennent du billet papier. C'est ce
    qui fait reconnaitre l'objet avant meme d'en lire le texte.
--}}
<div
    {{ $attributes->class([
        'glass glass-specular relative w-full max-w-sm select-none',
        'ticket-arrive' => $animate,
    ]) }}
    role="img"
    aria-label="Exemple de billet {{ $ticketType }} pour {{ $eventName }}, le {{ $date }} a {{ $venue }}"
>
    {{-- Partie haute : l'evenement --}}
    <div class="px-6 pt-6 pb-5">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold tracking-[0.14em] text-azure-700/70 uppercase">
                    {{ $ticketType }}
                </p>
                <h3 class="mt-1.5 font-display text-xl leading-tight font-extrabold text-ink">
                    {{ $eventName }}
                </h3>
            </div>
            <span class="shrink-0 rounded-lg bg-white/70 px-2 py-1 font-display text-[11px] font-bold text-azure-700">
                Pass241
            </span>
        </div>

        <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div class="min-w-0">
                <dt class="text-[11px] font-medium tracking-wide text-grey-500 uppercase">Date</dt>
                <dd class="mt-0.5 truncate font-semibold text-ink tabular">{{ $date }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[11px] font-medium tracking-wide text-grey-500 uppercase">Lieu</dt>
                <dd class="mt-0.5 truncate font-semibold text-ink">{{ $venue }}</dd>
            </div>
            <div class="col-span-2 min-w-0">
                <dt class="text-[11px] font-medium tracking-wide text-grey-500 uppercase">Titulaire</dt>
                <dd class="mt-0.5 truncate font-semibold text-ink">{{ $holder }}</dd>
            </div>
        </dl>
    </div>

    {{-- Ligne de dechirure, entre la souche et le talon --}}
    <div class="relative h-6">
        <span class="ticket-notch top-0 -left-3 -translate-y-1/2"></span>
        <span class="ticket-notch top-0 -right-3 -translate-y-1/2"></span>
        <div class="absolute inset-x-6 top-0 border-t border-dashed border-azure-700/25"></div>
    </div>

    {{-- Partie basse : le talon, avec le QR sur encart blanc opaque --}}
    <div class="flex items-center gap-5 px-6 pt-1 pb-6">
        {{--
            `bg-white` plein et non `bg-white/80` : l'opacite totale est la
            condition du scan (regle 70.4). Ne jamais alleger cette valeur.
        --}}
        <div class="shrink-0 rounded-2xl bg-white p-3 shadow-[0_2px_10px_rgba(20,84,155,0.14)]">
            <div class="size-24 [&>svg]:size-full">
                {!! $qr !!}
            </div>
        </div>

        <div class="min-w-0">
            <p class="text-[11px] font-medium tracking-wide text-grey-500 uppercase">Reference</p>
            <p class="mt-0.5 font-display text-base font-bold text-ink tabular">{{ $reference }}</p>
            <p class="mt-2 text-xs leading-relaxed text-grey-500">
                Presentez ce code a l'entree. Il fonctionne sans reseau.
            </p>
        </div>
    </div>
</div>
