@props([
    'title' => 'Pass241',
    // [['label' =>, 'icon' =>, 'route' =>, 'match' =>, 'group' =>], ...]
    'navigation' => [],
    // Pastille sous la marque : « Administration » pour la console. Null dans
    // l'espace organisateur, ou l'absence de pastille EST l'information.
    'badge' => null,
    'maxWidth' => 'max-w-6xl',
    // Une console d'administration n'a rien a faire dans un index de moteur.
    'indexable' => false,
])

@php
    /*
     * Les entrees sont regroupees a la volee. L'ordre du tableau fait foi :
     * un groupe apparait a la position de sa premiere entree, ce qui evite
     * d'avoir a declarer les groupes separement et a les tenir synchronises.
     */
    $groups = [];
    foreach ($navigation as $item) {
        $groups[$item['group'] ?? ''][] = $item;
    }

    /*
     * Barre inferieure sur telephone : QUATRE entrees au maximum.
     *
     * Ce n'est pas une contrainte esthetique. Sur un ecran de 360px — le plus
     * repandu du marche vise — cinq entrees laissent 72px par cible, dont une
     * partie perdue en marges : on touche la voisine une fois sur trois. A
     * quatre, chaque cible fait 90px et le geste devient sur.
     *
     * Les entrees qui ne tiennent pas ne disparaissent pas : elles restent
     * accessibles depuis le menu du profil, en haut de l'ecran.
     */
    $primary = array_slice($navigation, 0, 4);
    $overflow = array_slice($navigation, 4);

    $supportEmail = config('app.support_email');
@endphp

<!DOCTYPE html>
<html lang="fr" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Tout en haut : le theme doit etre pose avant la premiere peinture. --}}
    <x-theme-script />

    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Valeur du mode clair. `app.js` la reecrit des que le theme change,
         pour que la barre d'adresse mobile suive le fond de la page. --}}
    <meta name="theme-color" content="#f7f9fb">
    @unless ($indexable)
        <meta name="robots" content="noindex, nofollow">
    @endunless
    <title>{{ $title }}</title>
    {{-- Icones de la marque.

         L'ordre compte : les navigateurs prennent la PREMIERE declaration
         qu'ils comprennent. Le PNG 32px d'abord, net sur un onglet ; le .ico
         ensuite, qui porte les tailles 16/32/48 pour les favoris de bureau et
         les navigateurs anciens.

         L'ancien `favicon.svg` a ete retire : il portait l'ancien symbole
         monochrome. Et l'ancien `favicon.ico` pesait ZERO octet — il etait
         declare depuis le debut mais completement vide, ce qui laissait
         l'onglet sans icone. --}}
    <link rel="icon" href="/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    {{--
        Polices auto-hebergees.

        `Vite::fonts()` pose les `@font-face` EN LIGNE et precharge les fichiers
        woff2 les plus utilises. Sans cet appel, les fichiers sont bien
        telecharges a la construction et deposes dans `public/build`, mais
        AUCUNE page ne les declare : le navigateur retombe silencieusement sur
        la police systeme, et la typographie du produit n'existe pas.

        La panne est discrete — le texte s'affiche, simplement pas dans la bonne
        police — ce qui explique qu'elle ait pu passer inapercue.

        Avant `@vite` : les `@font-face` doivent etre connus quand la feuille de
        styles s'applique, sinon le premier rendu se fait dans la police de
        repli puis saute.
    --}}
    {{ Vite::fonts() }}

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

{{--
    La nappe ambiante est posee sur le <body> : c'est elle qui donne au verre
    quelque chose a refracter. Sur un aplat uni, le flou est invisible et coute
    du rendu pour rien (section 70.4).
--}}
<body class="azure-wash min-h-dvh">

    {{-- Un saut direct au contenu, en premier element focusable de la page.
         Sans lui, la navigation au clavier traverse les quinze liens du rail
         avant d'atteindre le tableau, a chaque changement de page. --}}
    <a href="#contenu"
       class="btn btn-primary sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50">
        Aller au contenu
    </a>

    <div class="lg:flex">

        {{-- =================================================================
             Colonne laterale — a partir de lg uniquement

             Le SEUL endroit du produit ou une colonne entiere est en verre.
             Elle remplit les trois conditions qui rendent l'effet lisible
             plutot que decoratif (section 70.4) : elle est fixe, elle ne
             defile pas, et elle se detache de la nappe ambiante. Les tableaux,
             eux, restent des surfaces pleines.
        ================================================================== --}}
        <aside class="glass-rail hidden lg:sticky lg:top-0 lg:flex lg:h-dvh lg:w-64 lg:shrink-0 lg:flex-col">

            <div class="px-5 pt-6 pb-5">
                <a href="{{ route('home') }}"
                   class="flex items-center gap-2 font-display text-xl font-extrabold tracking-tight text-ink">
                    <x-brand-mark class="h-7 w-auto" />
                    <x-brand-word />
                </a>

                @if ($badge)
                    <span class="mt-2 inline-flex rounded-full bg-azure-100 px-2.5 py-0.5 text-[10px] font-bold tracking-wider text-azure-700 uppercase">
                        {{ $badge }}
                    </span>
                @else
                    <p class="mt-1 text-[10px] font-bold tracking-wider text-grey-400 uppercase">
                        Plateforme de billetterie
                    </p>
                @endif
            </div>

            <nav class="no-scrollbar flex-1 space-y-4 overflow-y-auto px-3 pb-3"
                 aria-label="Navigation principale">
                @foreach ($groups as $groupLabel => $items)
                    <div class="space-y-0.5">
                        @if ($groupLabel !== '')
                            <p class="px-3 pt-1 pb-1.5 text-[10px] font-bold tracking-wider text-grey-400 uppercase">
                                {{ $groupLabel }}
                            </p>
                        @endif

                        @foreach ($items as $item)
                            @php $current = request()->routeIs($item['match'] ?? $item['route']); @endphp
                            <a href="{{ route($item['route']) }}"
                               wire:navigate
                               class="rail-link"
                               @if ($current) aria-current="page" @endif>
                                <x-dynamic-component :component="'lucide-' . $item['icon']"
                                                     class="size-5 shrink-0" stroke-width="1.75" aria-hidden="true" />
                                <span class="truncate">{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </nav>

            <div class="space-y-3 border-t border-[var(--rail-border)] p-3">
                @if ($supportEmail)
                    <a href="mailto:{{ $supportEmail }}"
                       class="flex min-h-11 items-center justify-center gap-2 rounded-[var(--radius-sm)] bg-azure-100 px-3 text-sm font-semibold text-azure-700 transition hover:bg-azure-100/70">
                        <x-lucide-life-buoy class="size-4 shrink-0" stroke-width="1.75" aria-hidden="true" />
                        Besoin d'aide ?
                    </a>
                @endif

                <x-ui.theme-toggle />

                {{ $railFooter ?? '' }}
            </div>
        </aside>

        <div class="flex min-h-dvh min-w-0 flex-1 flex-col">

            {{-- =============================================================
                 En-tete mobile

                 Il ne reprend PAS la navigation : celle-ci vit dans la barre
                 inferieure, sous le pouce. Il porte la marque, le choix
                 d'apparence, et l'acces au profil — trois choses qu'on
                 consulte, pas qu'on parcourt.
            ============================================================== --}}
            <header class="glass-rail glass-bar sticky top-0 z-30 lg:hidden">
                <div class="flex items-center justify-between gap-2 px-4 py-2.5">
                    {{-- Le mot-repere disparait sous 400px.

                         Trois elements se partagent cette barre : la marque, le
                         choix d'apparence et le profil. Sur 390px, les garder
                         tous les trois au complet tronque le mot « Pass241 » a
                         deux lettres — ce qui est pire que de ne pas l'afficher.
                         Le sigle seul suffit : il est en haut a gauche, la ou
                         un logo est attendu, et il mene a l'accueil. Le nom
                         complet revient des qu'il y a la place. --}}
                    <a href="{{ route('home') }}"
                       class="flex min-w-0 items-center gap-2 font-display text-lg font-extrabold tracking-tight text-ink">
                        <x-brand-mark class="h-6 w-auto shrink-0" />
                        <x-brand-word class="hidden truncate min-[400px]:inline" />
                        <span class="sr-only min-[400px]:hidden">Pass241, accueil</span>
                        @if ($badge)
                            <span class="shrink-0 rounded-full bg-azure-100 px-2 py-0.5 text-[10px] font-bold tracking-wider text-azure-700 uppercase">
                                {{ $badge }}
                            </span>
                        @endif
                    </a>

                    <div class="flex shrink-0 items-center gap-1.5">
                        <x-ui.theme-toggle variant="compact" />
                        {{ $mobileHeader ?? '' }}
                    </div>
                </div>
            </header>

            {{-- `min-w-0` sur le conteneur ET sur la colonne parente : sans
                 cela, un tableau large force la colonne flex a s'elargir et
                 c'est la PAGE entiere qui defile lateralement, pas le tableau.
                 C'est la cause numero un de debordement horizontal. --}}
            <main id="contenu" class="min-w-0 flex-1 px-4 pt-5 pb-nav sm:px-6 lg:px-8 lg:pt-8 lg:pb-10">
                <div class="mx-auto w-full min-w-0 {{ $maxWidth }}">
                    {{ $slot }}
                </div>
            </main>

            {{-- =============================================================
                 Barre inferieure — telephone uniquement
            ============================================================== --}}
            <nav class="glass-rail glass-bar-bottom pb-safe fixed inset-x-0 bottom-0 z-30 lg:hidden"
                 aria-label="Navigation principale">
                <div class="grid" style="grid-template-columns: repeat({{ count($primary) + (count($overflow) > 0 ? 1 : 0) }}, minmax(0, 1fr))">
                    @foreach ($primary as $item)
                        @php $current = request()->routeIs($item['match'] ?? $item['route']); @endphp
                        <a href="{{ route($item['route']) }}"
                           wire:navigate
                           @if ($current) aria-current="page" @endif
                           @class([
                               'group flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-[11px] font-semibold transition-colors',
                               'text-azure-600' => $current,
                               'text-grey-500' => ! $current,
                           ])>
                            {{-- La pastille derriere l'icone tient lieu d'etat
                                 actif : au doigt, il n'y a pas de survol, et un
                                 simple changement de couleur de trait se voit
                                 mal en plein soleil. --}}
                            <span @class([
                                'flex h-7 w-12 items-center justify-center rounded-full transition-colors',
                                'bg-azure-100' => $current,
                            ])>
                                <x-dynamic-component :component="'lucide-' . $item['icon']"
                                                     class="size-5" :stroke-width="$current ? '2' : '1.75'" aria-hidden="true" />
                            </span>
                            <span class="max-w-full truncate">{{ $item['label'] }}</span>
                        </a>
                    @endforeach

                    @if ($overflow !== [])
                        {{-- Les entrees qui ne tiennent pas dans la barre ne
                             disparaissent pas : elles s'ouvrent en feuille
                             depuis le bas, la ou le pouce est deja. --}}
                        <div x-data="{ open: false }" class="relative flex">
                            <button type="button"
                                    @click="open = true"
                                    :aria-expanded="open"
                                    aria-haspopup="menu"
                                    class="flex min-h-14 w-full flex-col items-center justify-center gap-1 px-1 text-[11px] font-semibold text-grey-500">
                                <span class="flex h-7 w-12 items-center justify-center rounded-full">
                                    <x-lucide-ellipsis class="size-5" stroke-width="1.75" aria-hidden="true" />
                                </span>
                                <span>Plus</span>
                            </button>

                            <template x-teleport="body">
                                <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end" role="menu">
                                    <div x-show="open" x-transition:enter="scrim-in"
                                         @click="open = false" class="overlay-scrim" aria-hidden="true"></div>

                                    <div x-show="open"
                                         x-transition:enter="layer-in"
                                         x-trap.noscroll="open"
                                         @keydown.escape.window="open = false"
                                         class="layer-panel pb-safe relative w-full rounded-b-none p-2">
                                        @foreach ($overflow as $item)
                                            <a href="{{ route($item['route']) }}"
                                               wire:navigate
                                               role="menuitem"
                                               class="flex min-h-12 items-center gap-3 rounded-[var(--radius-sm)] px-3 text-sm font-medium text-grey-500 transition hover:bg-surface-muted hover:text-azure-700">
                                                <x-dynamic-component :component="'lucide-' . $item['icon']"
                                                                     class="size-5 shrink-0" stroke-width="1.75" aria-hidden="true" />
                                                {{ $item['label'] }}
                                            </a>
                                        @endforeach

                                        {{ $mobileOverflow ?? '' }}
                                    </div>
                                </div>
                            </template>
                        </div>
                    @endif
                </div>
            </nav>
        </div>
    </div>
</body>
</html>
