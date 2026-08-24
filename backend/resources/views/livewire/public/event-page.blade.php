@php
    use App\Enums\EventStatus;

    $location = $event->location;
    $tz = config('app.display_timezone');

    $start = $event->starts_at->timezone($tz);
    $end = $event->ends_at?->timezone($tz);

    $cover = $event->coverUrl();
    $cancelled = $event->status === EventStatus::Cancelled;
@endphp

<div>
    {{-- ---------------------------------------------------------------
         Heros : la banniere occupe le haut de l'ecran, titre pose dessus.

         C'est le premier element du mini-site (section 7), et la seule image
         que la plupart des visiteurs auront deja vue passer dans l'apercu
         WhatsApp du lien. La reprendre en grand, avec le titre dessus, fait
         reconnaitre l'evenement avant meme la lecture.
    ---------------------------------------------------------------- --}}
    <section class="relative isolate flex min-h-[68vh] items-end sm:min-h-[72vh] lg:min-h-[78vh]">
        @if ($cover)
            <div aria-hidden="true" class="event-hero -z-10">
                <div class="event-hero-image" style="background-image: url({{ $cover }})"></div>
                <div class="event-hero-scrim"></div>
            </div>
        @else
            {{-- Sans banniere, la nappe azurine tient lieu de fond : un heros
                 vide serait pire qu'un heros sobre. --}}
            <div aria-hidden="true" class="azure-wash absolute inset-0 -z-10"></div>
        @endif

        <div class="mx-auto w-full max-w-5xl px-5 pt-24 pb-10 sm:px-6 sm:pt-32 lg:pb-14">
            @if ($cancelled)
                {{-- L'annulation passe avant tout : quelqu'un qui rouvre le
                     lien recu doit la lire immediatement, pas la deduire. --}}
                <div class="mb-6 inline-flex items-center gap-2.5 rounded-full bg-danger px-4 py-2 text-sm font-semibold text-white">
                    <x-lucide-circle-alert class="size-4 shrink-0" stroke-width="2" />
                    Evenement annule
                </div>
            @endif

            @if ($event->categoryLine())
                <p @class([
                    'text-xs font-semibold tracking-[0.16em] uppercase',
                    'text-white/75' => $cover,
                    'text-azure-700/80' => ! $cover,
                ])>
                    {{ $event->categoryLine() }}
                </p>
            @endif

            {{-- `text-shadow` plutot qu'un panneau derriere le titre : sur une
                 image claire, il garantit la lisibilite sans poser un bloc
                 opaque qui masquerait le visuel de l'organisateur. --}}
            <h1 @class([
                'mt-3 max-w-3xl font-display text-4xl leading-[1.04] font-extrabold sm:text-6xl lg:text-7xl',
                'text-white [text-shadow:0_2px_24px_rgba(16,19,26,0.45)]' => $cover,
                'text-ink' => ! $cover,
                'line-through decoration-danger decoration-4' => $cancelled,
            ])>
                {{ $event->name }}
            </h1>

            {{-- Ligne de metadonnees : ce qu'on cherche en priorite quand on
                 vient de recevoir un lien. --}}
            <div @class([
                'mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm font-medium',
                'text-white/90' => $cover,
                'text-grey-500' => ! $cover,
            ])>
                <span class="flex items-center gap-2">
                    <x-lucide-calendar-days class="size-4 shrink-0" stroke-width="1.75" />
                    <span class="tabular">{{ $start->translatedFormat('j F Y') }} a {{ $start->format('H\hi') }}</span>
                </span>

                @if ($location)
                    <span class="flex items-center gap-2">
                        <x-lucide-map-pin class="size-4 shrink-0" stroke-width="1.75" />
                        {{ $location->shortAddress() }}
                    </span>
                @endif

                @if ($event->max_capacity)
                    <span class="flex items-center gap-2">
                        <x-lucide-users class="size-4 shrink-0" stroke-width="1.75" />
                        <span class="tabular">{{ number_format($event->max_capacity, 0, ',', ' ') }} places</span>
                    </span>
                @endif
            </div>

            <p @class([
                'mt-4 text-sm',
                'text-white/70' => $cover,
                'text-grey-500' => ! $cover,
            ])>
                Organise par <span class="font-semibold">{{ $event->organization->name }}</span>
            </p>

            @unless ($cancelled)
                <a href="#billets" class="btn btn-primary btn-pill btn-lg mt-8">
                    <x-lucide-ticket class="size-4" stroke-width="2" />
                    Voir les billets
                </a>
            @endunless
        </div>
    </section>

    {{-- ---------------------------------------------------------------
         Informations pratiques detaillees.
    ---------------------------------------------------------------- --}}
    <section class="mx-auto w-full max-w-5xl px-5 pb-10 sm:px-6">
        @if ($cancelled && $event->cancellation_reason)
            <div class="mb-6 flex items-start gap-3 rounded-2xl border border-danger/30 bg-danger/5 p-4 sm:p-5">
                <x-lucide-circle-alert class="mt-0.5 size-5 shrink-0 text-danger" stroke-width="1.75" />
                <div class="text-sm">
                    <p class="font-display text-base font-bold text-ink">Motif de l'annulation</p>
                    <p class="mt-1 leading-relaxed text-grey-500">{{ $event->cancellation_reason }}</p>
                </div>
            </div>
        @endif

        {{-- Surface pleine et non du verre : ce sont les informations qu'on
             relit vite avant de partir, la lisibilite prime (section 70.4). --}}
        <dl class="glass-inset grid gap-px overflow-hidden bg-grey-200/60 sm:grid-cols-2 lg:grid-cols-3">
            <div class="flex gap-4 bg-white/90 p-5">
                <x-lucide-calendar-days class="mt-0.5 size-5 shrink-0 text-azure-600" stroke-width="1.5" />
                <div>
                    <dt class="text-xs font-semibold tracking-wide text-grey-500 uppercase">Date</dt>
                    <dd class="mt-1 font-semibold text-ink">{{ $start->translatedFormat('l j F Y') }}</dd>
                    <dd class="mt-0.5 text-sm text-grey-500 tabular">
                        {{ $start->format('H\hi') }}
                        @if ($end)
                            @if ($end->isSameDay($start))
                                — {{ $end->format('H\hi') }}
                            @else
                                jusqu'au {{ $end->translatedFormat('j F Y \a\ H\hi') }}
                            @endif
                        @endif
                    </dd>
                </div>
            </div>

            @if ($location)
                <div class="flex gap-4 bg-white/90 p-5">
                    <x-lucide-map-pin class="mt-0.5 size-5 shrink-0 text-azure-600" stroke-width="1.5" />
                    <div>
                        <dt class="text-xs font-semibold tracking-wide text-grey-500 uppercase">Lieu</dt>
                        <dd class="mt-1 font-semibold text-ink">{{ $location->name }}</dd>
                        @if ($location->address)
                            <dd class="mt-0.5 text-sm text-grey-500">{{ $location->address }}</dd>
                        @endif
                        <dd class="mt-0.5 text-sm text-grey-500">{{ $location->city }}</dd>
                    </div>
                </div>
            @endif

            @if ($event->max_capacity)
                <div class="flex gap-4 bg-white/90 p-5">
                    <x-lucide-users class="mt-0.5 size-5 shrink-0 text-azure-600" stroke-width="1.5" />
                    <div>
                        <dt class="text-xs font-semibold tracking-wide text-grey-500 uppercase">Capacite</dt>
                        <dd class="mt-1 font-semibold text-ink tabular">
                            {{ number_format($event->max_capacity, 0, ',', ' ') }} places
                        </dd>
                    </div>
                </div>
            @endif
        </dl>
    </section>

    {{-- ---------------------------------------------------------------
         Billetterie.

         Les types de billets arrivent au jalon suivant. Plutot qu'une
         section vide ou un faux bouton, l'etat est annonce honnetement :
         un visiteur qui clique dans le vide perd confiance.
    ---------------------------------------------------------------- --}}
    <section id="billets" class="mx-auto w-full max-w-5xl scroll-mt-20 px-5 pb-10 sm:px-6">
        @if ($event->allowsPurchase())
            {{-- Le parcours d'achat est un composant a part : il porte son
                 propre etat, et la page de l'evenement reste un document
                 rendu cote serveur, donc indexable (section 60.1). --}}
            <livewire:public.checkout :event="$event" />
        @else
            <div class="glass-inset p-6 sm:p-8">
                <h2 class="font-display text-xl font-extrabold text-ink sm:text-2xl">Billets</h2>
                <p class="mt-3 leading-relaxed text-grey-500">
                    @if ($cancelled)
                        La vente est fermee : cet evenement a ete annule.
                    @else
                        La vente n'est pas ouverte pour le moment.
                    @endif
                </p>
            </div>
        @endif
    </section>

    {{-- ---------------------------------------------------------------
         Description, programme, informations pratiques (section 7).
    ---------------------------------------------------------------- --}}
    @if ($event->description || $event->programme || $event->practical_info)
        <section class="defer-paint mx-auto w-full max-w-5xl px-5 pb-14 sm:px-6 lg:pb-20">
            <div class="grid gap-8 lg:grid-cols-[1.15fr_0.85fr] lg:gap-12">
                @if ($event->description)
                    <div data-reveal>
                        <h2 class="font-display text-xl font-extrabold text-ink sm:text-2xl">
                            A propos
                        </h2>
                        {{-- `whitespace-pre-line` : les sauts de ligne saisis par
                             l'organisateur sont conserves, sans jamais interpreter
                             son texte comme du HTML. --}}
                        <p class="mt-3 leading-relaxed whitespace-pre-line text-grey-500">
                            {{ $event->description }}
                        </p>
                    </div>
                @endif

                <div class="space-y-6">
                    @if ($event->programme)
                        <div data-reveal data-reveal-step="1" class="glass-inset p-5 sm:p-6">
                            <h2 class="flex items-center gap-2 font-display text-base font-bold text-ink">
                                <x-lucide-list-music class="size-4 text-azure-600" stroke-width="1.5" />
                                Programme
                            </h2>
                            <p class="mt-3 text-sm leading-relaxed whitespace-pre-line text-grey-500">
                                {{ $event->programme }}
                            </p>
                        </div>
                    @endif

                    @if ($event->practical_info)
                        <div data-reveal data-reveal-step="2" class="glass-inset p-5 sm:p-6">
                            <h2 class="flex items-center gap-2 font-display text-base font-bold text-ink">
                                <x-lucide-info class="size-4 text-azure-600" stroke-width="1.5" />
                                Informations pratiques
                            </h2>
                            <p class="mt-3 text-sm leading-relaxed whitespace-pre-line text-grey-500">
                                {{ $event->practical_info }}
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif
</div>
