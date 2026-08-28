@props([
    'ticket' => null,
    // Mode vitrine, pour la page d'accueil : aucun billet reel n'existe.
    'eventName' => null,
    'date' => null,
    'venue' => null,
    'ticketType' => null,
    'holder' => null,
    'reference' => null,
    'qrData' => null,
    'bannerUrl' => null,
    'animate' => false,
])

@php
    use App\Enums\TicketStatus;

    $tz = config('app.display_timezone');
    $startsAt = null;
    $endsAt = null;
    $city = null;

    if ($ticket) {
        $event = $ticket->event;
        $item = $ticket->orderItem ?? null;

        $eventName = $event->name;
        $startsAt = $event->starts_at->timezone($tz);
        $endsAt = $event->ends_at?->timezone($tz);
        $date = $startsAt->translatedFormat('j F Y \a\ H\hi');
        $venue = $event->location?->name ?? '';
        $city = trim(implode(', ', array_filter([$event->location?->address, $event->location?->city])));
        $ticketType = $item?->ticket_type_name ?? '';
        $holder = $ticket->holder_name;
        $reference = $ticket->reference;
        // Section 18 : le QR encode un jeton signe, jamais l'identifiant.
        $qrData = $ticket->qrToken();
        $categories = $event->categoryLine();
        $paidPrice = $item ? $item->formattedUnitPrice() : null;
        $bannerUrl ??= $event->coverUrl();
        $valid = $ticket->status === TicketStatus::Active;
    } else {
        $categories = null;
        $paidPrice = null;
        $valid = true;
    }

    $qr = app(\App\Services\Ticketing\QrRenderer::class)->svg($qrData, 320);
    $isVip = str_contains(mb_strtoupper((string) $ticketType), 'VIP');
@endphp

{{--
    Le billet — element signature de Pass241 (sections 15.2, 70.5).

    Meme composition que la version imprimable (resources/views/pdf/ticket) :
    bandeau de marque, mention « billet officiel », encoches, banniere, bloc
    date et lieu avec carte de categorie, QR, statut, regles. Un acheteur doit
    reconnaitre le meme objet avant et apres telechargement.

    Trois contraintes non negociables :

    - le QR Code vit dans un encart blanc totalement opaque, jamais sur le
      verre : un flou residuel derriere le code peut faire echouer un scan a
      l'entree (regle 70.4) ;
    - un seul niveau de verre par ecran, donc aucune carte en verre imbriquee ;
    - le QR encode un jeton signe, jamais un identifiant brut (section 18).

    Ni « Rang » ni « Place » : la section 15.2 ne les autorise que si
    l'evenement utilise un plan de salle numerote, ce que la section 17 ne
    prevoit pas.
--}}
<div
    {{-- ILOT CLAIR, quel que soit le theme de la page.
         Le billet est un DOCUMENT, pas un ecran : il porte un QR Code qu'un
         lecteur d'entree de gamme doit lire, et il est capture en image pour
         circuler sur WhatsApp, ou il sera vu sur l'appareil de quelqu'un
         d'autre. Un billet dont l'apparence depend du reglage de celui qui
         l'ouvre n'est plus un document.
         Le systeme de themes prevoit exactement ce cas (section 2 du CSS) :
         `data-theme="light"` rend un ilot clair a l'interieur d'une page
         sombre. Sans lui, le bandeau `bg-azure-700` — clair en mode sombre —
         recevrait du texte blanc, illisible. --}}
    data-theme="light"
    {{ $attributes->class([
        'glass glass-specular relative w-full max-w-sm overflow-hidden select-none',
        'ticket-arrive' => $animate,
    ]) }}
    role="img"
    aria-label="Billet {{ $ticketType }} pour {{ $eventName }}, le {{ $date }} a {{ $venue }}"
>
    {{-- Bandeau de marque (section 15.2, point 1) --}}
    <div class="relative bg-azure-700 px-5 pt-4 pb-3.5">
        <div class="flex items-center gap-3">
            <span class="flex items-center gap-1.5 font-display text-base font-extrabold tracking-tight text-white">
                {{-- Le logo en couleur tient sur ce bandeau : son « P » cyan se
                     detache franchement de l'aplat azur soutenu. Et la capture
                     en image le supporte — `ticket-image.js` attend le
                     chargement de chaque `<img>` du clone avant de declencher
                     le rendu, precisement pour ce cas. --}}
                <x-brand-mark class="h-6 w-auto" />
                <x-brand-word variant="light" />
            </span>
            <span class="h-4 w-px bg-white/25"></span>
            <span class="text-[11px] text-azure-100">Vivez vos evenements autrement</span>
        </div>

        <p class="mt-3 text-center text-[9px] font-semibold tracking-[0.32em] text-azure-300 uppercase">
            Billet officiel
        </p>

        {{-- Encoches, a la jonction du bandeau et de la banniere. C'est ce
             detail qui fait lire l'objet comme un billet. --}}
        <span class="ticket-notch -bottom-3 -left-3"></span>
        <span class="ticket-notch -right-3 -bottom-3"></span>
    </div>

    @unless ($valid)
        <p class="bg-danger px-5 py-2.5 text-center text-xs font-bold text-white">
            Billet {{ mb_strtolower($ticket->status->label()) }} — ne permet pas l'entree.
        </p>
    @endunless

    {{-- Banniere de l'evenement, nom en surimpression (point 2).
         Le voile sombre n'est pas decoratif : sans lui, la lisibilite du titre
         dependrait de l'image choisie par l'organisateur. --}}
    <div class="px-4 pt-4">
        <div class="relative aspect-[16/10] overflow-hidden rounded-2xl bg-azure-500">
            @if ($bannerUrl)
                <img src="{{ $bannerUrl }}" alt="" aria-hidden="true"
                     class="absolute inset-0 size-full object-cover object-[50%_32%]"
                     loading="lazy" decoding="async">
            @endif

            <div class="absolute inset-0 bg-gradient-to-t from-ink/85 via-ink/40 to-ink/5"></div>

            <div class="absolute inset-x-0 bottom-0 px-4 pt-8 pb-3.5">
                @if ($categories)
                    <p class="text-[10px] font-semibold tracking-[0.18em] text-white/70 uppercase">
                        {{ $categories }}
                    </p>
                @endif
                <h3 class="mt-1 font-display text-xl leading-[1.1] font-extrabold text-white [text-shadow:0_1px_12px_rgba(16,19,26,0.55)]">
                    {{ $eventName }}
                </h3>
            </div>
        </div>
    </div>

    {{-- Date, lieu et categorie (points 4 a 6) --}}
    <div class="px-5 pt-5 pb-4">
        <div class="flex items-start gap-3">
            <x-lucide-calendar-days class="mt-0.5 size-5 shrink-0 text-azure-700" stroke-width="1.75" />
            <div class="min-w-0">
                <p class="font-semibold text-ink">
                    {{ $startsAt ? ucfirst($startsAt->translatedFormat('l j F Y')) : $date }}
                </p>
                @if ($startsAt)
                    <p class="text-sm text-grey-500 tabular">
                        {{ $startsAt->format('H\hi') }}@if ($endsAt) – {{ $endsAt->format('H\hi') }} @endif
                    </p>
                @endif
            </div>
        </div>

        @if ($venue)
            <div class="mt-3.5 flex items-start gap-3">
                <x-lucide-map-pin class="mt-0.5 size-5 shrink-0 text-azure-700" stroke-width="1.75" />
                <div class="min-w-0">
                    <p class="font-semibold text-ink">{{ $venue }}</p>
                    @if ($city)
                        <p class="text-sm text-grey-500">{{ $city }}</p>
                    @endif
                </div>
            </div>
        @endif

        {{-- Carte de categorie. Surface pleine et non du verre : un seul
             niveau de verre par ecran (regle 70.4). --}}
        <div class="mt-4 flex items-center gap-3.5 rounded-2xl border border-azure-100 bg-azure-50 p-3.5">
            <span @class([
                'flex size-11 shrink-0 items-center justify-center rounded-full text-white',
                'bg-azure-600' => $isVip,
                'bg-azure-500' => ! $isVip,
            ])>
                <x-dynamic-component :component="$isVip ? 'lucide-star' : 'lucide-ticket'"
                                     class="size-5" stroke-width="2" />
            </span>

            <div class="min-w-0 flex-1">
                <p class="text-[10px] font-semibold tracking-[0.14em] text-grey-500 uppercase">Categorie</p>
                <p class="font-display text-lg leading-tight font-extrabold text-ink">{{ $ticketType }}</p>
                @if ($paidPrice)
                    <p class="text-sm font-medium text-azure-600 tabular">{{ $paidPrice }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Perforation --}}
    <div class="relative py-1">
        <div class="ticket-perforation mx-5"></div>
    </div>

    {{-- QR Code, reference, titulaire et statut (point 8) --}}
    <div class="ticket-stub px-5 py-5">
        <div class="flex gap-4">
            {{--
                `bg-white` plein et non `bg-white/80` : l'opacite totale est la
                condition du scan (regle 70.4). Ne jamais alleger cette valeur.
            --}}
            <div class="shrink-0 self-start">
                <div class="rounded-2xl bg-white p-3 shadow-[0_2px_12px_rgba(20,84,155,0.16)]">
                    <div class="size-28 [&>svg]:size-full">
                        {!! $qr !!}
                    </div>
                </div>
                {{-- Section 15.2, point 7 : le rappel de scan fait partie du
                     billet. Un porteur qui arrive a l'entree doit savoir ce
                     qu'on attend de lui sans qu'on le lui dise. --}}
                <p class="mt-2 max-w-[8.5rem] text-center text-[11px] leading-snug text-grey-500">
                    Scannez ce QR Code a l'entree
                </p>
            </div>

            <div class="min-w-0 flex-1">
                <p class="text-xs text-grey-500">N° de billet</p>
                <p class="font-display text-lg leading-tight font-extrabold tracking-wide text-ink tabular">
                    {{ $reference }}
                </p>

                <div class="mt-3 rounded-xl bg-white/70 px-3 py-2">
                    <p class="text-[10px] font-semibold tracking-[0.14em] text-grey-500 uppercase">Titulaire</p>
                    <p class="truncate font-semibold text-ink">{{ $holder }}</p>
                </div>
            </div>
        </div>

        {{-- Statut : lisible d'un coup d'oeil par un agent (section 19). --}}
        <div @class([
            'mt-4 flex items-center gap-3 rounded-xl px-3.5 py-2.5',
            'bg-success/10' => $valid,
            'bg-danger/10' => ! $valid,
        ])>
            <span @class([
                'flex size-7 shrink-0 items-center justify-center rounded-full text-white',
                'bg-success' => $valid,
                'bg-danger' => ! $valid,
            ])>
                <x-dynamic-component :component="$valid ? 'lucide-check' : 'lucide-x'"
                                     class="size-4" stroke-width="2.5" />
            </span>
            <div class="min-w-0">
                <p class="text-sm font-bold text-ink">
                    {{ $valid ? 'Billet valide' : 'Billet '.mb_strtolower($ticket->status->label()) }}
                </p>
                <p class="text-xs text-grey-500">
                    {{ $valid ? 'A presenter au controle d\'acces' : 'Ne permet pas l\'entree' }}
                </p>
            </div>
        </div>
    </div>

    {{-- Regles d'usage. Chacune correspond a une regle reelle du produit :
         un seul scan possible (section 19), billet nominatif (section 16),
         controle fonctionnant hors connexion (section 31). --}}
    <div class="border-t border-white/60 px-5 py-4">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-xs">
            @foreach ([
                ['ticket', 'Un seul acces par billet', null],
                ['scan-line', 'Controle hors connexion', 'le scan fonctionne sans reseau'],
                ['user', 'Billet nominatif', 'non transferable sans autorisation'],
                ['clock', 'Ouverture des portes', $startsAt?->format('H\hi')],
            ] as [$icon, $title, $note])
                <div class="flex gap-2">
                    <x-dynamic-component :component="'lucide-'.$icon"
                                         class="mt-0.5 size-3.5 shrink-0 text-azure-600" stroke-width="1.75" />
                    <div class="min-w-0">
                        <dt class="font-semibold text-ink">{{ $title }}</dt>
                        @if ($note)
                            <dd class="mt-0.5 leading-snug text-grey-500">{{ $note }}</dd>
                        @endif
                    </div>
                </div>
            @endforeach
        </dl>
    </div>
</div>
