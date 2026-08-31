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
         Banniere : l'affiche en premier, le titre pose dessus.

         C'est le premier element du mini-site (section 7), et la seule image
         que la plupart des visiteurs auront deja vue passer dans l'apercu
         WhatsApp du lien. La reprendre en grand, avec le titre dessus, fait
         reconnaitre l'evenement avant meme la lecture.

         Hauteur PROPORTIONNELLE et non plus une fraction de la fenetre. Un
         heros en `vh` reglait la place prise par l'affiche sur la hauteur du
         telephone, ce qui n'a rien a voir : sur un ecran allonge, l'affiche
         s'etirait jusqu'a repousser le premier billet sous la ligne de
         flottaison. Un rapport fixe la cadre toujours pareil, et le contenu
         commence a la meme hauteur pour tout le monde.

         Bord a bord, y compris sur grand ecran : l'en-tete du produit flotte
         par-dessus (`heroDark`), et sa lisibilite tient a l'image qui passe
         dessous. Une banniere en retrait laisserait le logo blanc sur la
         nappe claire de la page.
    ---------------------------------------------------------------- --}}
    {{-- `w-full` n'est pas decoratif : sans largeur definie, un bloc qui porte
         a la fois `aspect-ratio` et `max-height` voit le navigateur RETRECIR
         SA LARGEUR pour tenir le rapport, au lieu de rogner sa hauteur. La
         banniere cessait alors d'aller d'un bord a l'autre sur grand ecran, et
         laissait une bande de page nue a sa droite. --}}
    <section class="relative isolate flex aspect-[4/3] max-h-[70vh] min-h-[22rem] w-full items-end sm:aspect-[16/9] lg:aspect-[21/9]">
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

        {{-- Retrait bas genereux : la carte remonte de 3rem par-dessus, et rien
             du titre ne doit passer dessous. --}}
        <div class="mx-auto w-full max-w-5xl px-5 pt-24 pb-16 sm:px-6 sm:pt-32 sm:pb-20">
            @if ($cancelled)
                {{-- L'annulation passe avant tout : quelqu'un qui rouvre le
                     lien recu doit la lire immediatement, pas la deduire. --}}
                <div class="mb-5 inline-flex items-center gap-2.5 rounded-full bg-danger px-4 py-2 text-sm font-semibold text-white">
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
                'mt-2.5 max-w-3xl font-display text-3xl leading-[1.06] font-extrabold sm:text-5xl lg:text-6xl',
                'text-white [text-shadow:0_2px_24px_rgba(16,19,26,0.45)]' => $cover,
                'text-ink' => ! $cover,
                'line-through decoration-danger decoration-4' => $cancelled,
            ])>
                {{ $event->name }}
            </h1>
        </div>
    </section>

    {{-- ---------------------------------------------------------------
         Carte d'identite de l'evenement.

         Elle CHEVAUCHE la banniere : le geste rattache la carte a l'affiche
         plutot qu'a la page, et signale d'un coup d'oeil ou commence le
         contenu utile. C'est aussi ce qui remonte la date, le lieu et le
         bouton d'achat au-dessus de la ligne de flottaison sur telephone —
         avant, il fallait defiler pour savoir quand l'evenement avait lieu.

         En verre, et c'est l'un des rares endroits ou il est pleinement
         justifie (section 70.3) : la carte est posee sur une image, donc sur
         un fond qui a reellement de la matiere a refracter.
    ---------------------------------------------------------------- --}}
    <section class="relative z-10 mx-auto -mt-12 w-full max-w-5xl px-5 sm:px-6">
        <div class="glass glass-specular glass-panel p-5 sm:p-7" data-reveal>
            {{-- Pastilles : ce qu'on cherche en priorite quand on vient de
                 recevoir un lien. En pilules plutot qu'en ligne continue —
                 sur un telephone, chaque information garde son bloc au lieu
                 de passer a la ligne au milieu d'une adresse. --}}
            <div class="flex flex-wrap items-center gap-2">
                {{-- L'heure de FIN est portee ici depuis le tableau supprime :
                     c'est ce qu'on regarde pour savoir si on peut y passer en
                     sortant d'ailleurs. --}}
                <span class="info-chip">
                    <x-lucide-calendar-days class="size-4 shrink-0" stroke-width="1.75" />
                    <span class="tabular">
                        {{ $start->translatedFormat('l j F Y') }} a {{ $start->format('H\hi') }}
                        @if ($end)
                            @if ($end->isSameDay($start))
                                — {{ $end->format('H\hi') }}
                            @else
                                jusqu'au {{ $end->translatedFormat('j F \a\ H\hi') }}
                            @endif
                        @endif
                    </span>
                </span>

                @if ($location)
                    {{-- Adresse complete, et non plus abregee : la rue vivait
                         dans le tableau supprime, elle n'a pas d'autre place. --}}
                    <span class="info-chip">
                        <x-lucide-map-pin class="size-4 shrink-0" stroke-width="1.75" />
                        {{ implode(', ', array_filter([$location->name, $location->address, $location->city])) }}
                    </span>
                @endif

                @if ($event->max_capacity)
                    <span class="info-chip">
                        <x-lucide-users class="size-4 shrink-0" stroke-width="1.75" />
                        <span class="tabular">{{ number_format($event->max_capacity, 0, ',', ' ') }} places</span>
                    </span>
                @endif
            </div>

            {{-- Organisateur et achat sur la meme rangee : c'est la reponse a
                 « qui organise, et comment j'y vais ». Empiles sur telephone,
                 cote a cote des qu'il y a la place. --}}
            <div class="mt-5 flex flex-col gap-4 border-t border-grey-200/60 pt-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-azure-100">
                        <x-lucide-building-2 class="size-5 text-azure-700" stroke-width="1.75" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs text-grey-500">Organise par</p>
                        <p class="truncate font-semibold text-ink">{{ $event->organization->name }}</p>
                    </div>
                </div>

                @unless ($cancelled)
                    <a href="#billets" class="btn btn-primary btn-pill btn-lg w-full shrink-0 sm:w-auto">
                        <x-lucide-ticket class="size-4" stroke-width="2" />
                        Voir les billets
                    </a>
                @endunless
            </div>
        </div>
    </section>

    {{-- ---------------------------------------------------------------
         Informations pratiques detaillees.

         Les pastilles ci-dessus donnent la version lue d'un coup d'oeil ;
         ce bloc porte ce qu'elles ne peuvent pas tenir — heure de fin,
         adresse complete, ville.
    ---------------------------------------------------------------- --}}
    <section class="mx-auto w-full max-w-5xl px-5 pt-8 pb-10 sm:px-6">
        @if ($cancelled && $event->cancellation_reason)
            <div class="mb-6 flex items-start gap-3 rounded-2xl border border-danger/30 bg-danger/5 p-4 sm:p-5">
                <x-lucide-circle-alert class="mt-0.5 size-5 shrink-0 text-danger" stroke-width="1.75" />
                <div class="text-sm">
                    <p class="font-display text-base font-bold text-ink">Motif de l'annulation</p>
                    <p class="mt-1 leading-relaxed text-grey-500">{{ $event->cancellation_reason }}</p>
                </div>
            </div>
        @endif

        {{-- Le tableau « Date / Lieu / Capacite » a ete RETIRE : il repetait,
             juste en dessous, les pastilles de la carte d'identite. Deux blocs
             disant la meme chose a un ecran d'intervalle font douter qu'ils
             disent bien la meme chose, et repoussaient les billets d'autant
             plus bas sur telephone.

             Le peu qu'il portait en propre — heure de fin, adresse de rue — a
             rejoint les pastilles ci-dessus : rien n'est perdu. --}}
    </section>

    {{-- ---------------------------------------------------------------
         Billetterie.

         Les types de billets arrivent au jalon suivant. Plutot qu'une
         section vide ou un faux bouton, l'etat est annonce honnetement :
         un visiteur qui clique dans le vide perd confiance.
    ---------------------------------------------------------------- --}}
    {{-- La revelation est posee ICI, sur la section, et non a l'interieur du
         composant Livewire : ce qu'il rend est remplace a chaque appui sur
         « + », ce qui effacerait la classe posee par le navigateur et
         re-animerait le bloc a chaque clic.

         Elle peut vivre ici parce que la barre d'achat collante est teleportee
         au `body` — sans quoi le `transform` laisse par la revelation
         l'ancrerait a cette section au lieu de la fenetre. --}}
    <section id="billets" class="mx-auto w-full max-w-5xl scroll-mt-20 px-5 pb-10 sm:px-6" data-reveal>
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
