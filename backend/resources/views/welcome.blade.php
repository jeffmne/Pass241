@php
    /*
     * La navigation de la page, declaree UNE fois.
     *
     * Elle etait recopiee dans l'en-tete et dans le pied de page, avec deux
     * listes qui avaient deja commence a diverger. Elle sert desormais aussi la
     * feuille mobile, soit trois endroits : la faire vivre a un seul endroit est
     * la seule facon qu'ils disent la meme chose.
     */
    $lpNav = [
        ['#parcours', 'Comment ca marche'],
        ['#billetterie', 'Billetterie'],
        ['#collectes', 'Collectes'],
        ['#paiement', 'Paiement'],
    ];
@endphp
<!DOCTYPE html>
<html lang="fr" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#F5F7FD">

    {{-- Le theme AVANT la premiere peinture. Cette page ecrit son propre <head>
         au lieu de passer par un gabarit, et le script y avait ete oublie :
         `data-theme` n'etait jamais pose, donc `@custom-variant dark` ne se
         declenchait jamais. La page d'accueil restait claire meme pour un
         visiteur dont le telephone est en mode sombre — et c'est la seule page
         du produit ou le defaut existait. --}}
    <x-theme-script />
    <title>Pass241 — Billetterie et collecte de fonds au Gabon</title>
    <meta name="description" content="Creez votre evenement, partagez son lien, encaissez par Airtel Money et Moov Money. Vos participants recoivent leur billet immediatement, vos agents scannent a l'entree meme sans reseau.">
    {{-- Voir `components/app-shell.blade.php` pour le detail de cet ordre. --}}
    <link rel="icon" href="/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <meta property="og:type" content="website">
    <meta property="og:title" content="Pass241 — Billetterie et collecte de fonds au Gabon">
    <meta property="og:description" content="Vendez vos billets par Airtel Money et Moov Money. Controle des entrees meme sans reseau.">
    <meta property="og:url" content="{{ url('/') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="azure-wash flex min-h-dvh flex-col overflow-x-hidden">

{{--
    ---------------------------------------------------------------------
    Barre superieure
    ---------------------------------------------------------------------
    Le verre n'apparait qu'une fois la page defilee : en haut, il n'aurait
    rien a refracter et un backdrop-filter permanent coute cher pour rien.
--}}
<header data-sticky-header data-stuck="false"
        {{-- La matiere une fois defile est decrite en CSS, contre les jetons du
             rail : ecrite ici en `bg-white/70`, elle restait blanche en mode
             sombre et la barre devenait illisible. --}}
        class="fixed inset-x-0 top-0 z-50">
    <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-5 py-3.5 sm:px-6">
        <a href="{{ route('home') }}"
           class="flex shrink-0 items-center gap-2 font-display text-xl font-extrabold tracking-tight">
            <x-brand-mark class="h-8 w-auto" />
            {{-- A 320 px, le mot-symbole, les trois actions et leurs zones
                 tactiles ne tiennent pas honnêtement sur une ligne. Le
                 pictogramme reste la marque visible; le nom revient dès que
                 352 px sont disponibles. --}}
            <x-brand-word class="hidden min-[22rem]:inline" />
        </a>

        {{-- Navigation en clair a partir de md. Sous cette largeur elle vit
             dans la feuille, plus bas. --}}
        <nav class="hidden items-center gap-1 md:flex" aria-label="Navigation principale">
            @foreach ($lpNav as [$href, $label])
                <a href="{{ $href }}" class="lp-nav-link">{{ $label }}</a>
            @endforeach
        </nav>

        <div class="flex shrink-0 items-center gap-1.5">
            {{-- Bascule d'apparence. Elle n'existait pas sur cette page, alors
                 qu'elle est disponible partout ailleurs dans le produit : un
                 visiteur qui arrive en mode sombre depuis son telephone n'avait
                 aucun moyen d'en sortir.

                 Aucun etat n'est tenu en JavaScript — les deux icones se
                 croisent en CSS selon `data-theme` sur <html>, deja pose avant
                 la premiere peinture. --}}
            <button type="button"
                    data-theme-toggle
                    class="btn btn-icon"
                    aria-label="Changer d'apparence">
                <x-lucide-moon class="size-5 dark:hidden" stroke-width="1.75" aria-hidden="true" />
                <x-lucide-sun class="hidden size-5 dark:block" stroke-width="1.75" aria-hidden="true" />
            </button>

            @auth
                <a href="{{ route('dashboard') }}" class="btn btn-primary btn-pill">Mon espace</a>
            @else
                <a href="{{ route('login') }}" class="btn btn-ghost btn-pill hidden sm:inline-flex">
                    Connexion
                </a>
                <a href="{{ route('register') }}" class="btn btn-primary btn-pill">
                    <span class="sm:hidden">Commencer</span>
                    <span class="hidden sm:inline">Creer un evenement</span>
                </a>
            @endauth

            {{-- ============================================================
                 Menu mobile

                 Sous `md`, les quatre liens de navigation DISPARAISSAIENT
                 purement et simplement : le `<nav>` etait masque et rien ne le
                 remplacait. Un visiteur sur telephone — la majorite du trafic
                 vise — n'avait aucun acces aux sections de la page.

                 En `<details>` natif : ouvrable au clavier, annonce comme un
                 groupe repliable, et fonctionnel meme si aucun script ne se
                 charge. Cette page ne charge pas Livewire, donc pas Alpine ;
                 un menu qui dependrait d'un script serait un menu qui peut ne
                 pas s'ouvrir.
            ============================================================= --}}
            <details class="lp-menu md:hidden">
                <summary class="btn btn-icon" aria-label="Ouvrir le menu">
                    <x-lucide-menu class="lp-menu-open size-5" stroke-width="2" aria-hidden="true" />
                    <x-lucide-x class="lp-menu-close size-5" stroke-width="2" aria-hidden="true" />
                </summary>

                <div class="lp-menu-sheet glass glass-panel fixed inset-x-3 top-20 z-50 p-3">
                    <nav class="flex flex-col" aria-label="Navigation principale">
                        @foreach ($lpNav as [$href, $label])
                            <a href="{{ $href }}"
                               class="flex min-h-14 items-center rounded-[var(--radius-md)] px-4 text-base font-semibold text-ink transition-colors hover:bg-surface/70">
                                {{ $label }}
                            </a>
                        @endforeach
                    </nav>

                    @guest
                        <a href="{{ route('login') }}"
                           class="btn btn-secondary btn-pill mt-2 w-full sm:hidden">
                            Connexion
                        </a>
                    @endguest
                </div>
            </details>
        </div>
    </div>
</header>

<main class="flex-grow pt-20 sm:pt-24">

    {{-- ---------------------------------------------------------------
         Heros

         Asymetrique : le discours a gauche, l'objet a droite. Il etait centre,
         sans aucune ancre visuelle — titre, texte, boutons, puis du vide. Le
         billet n'arrivait qu'a la section suivante, alors que c'est LUI le
         produit, et le seul argument que personne d'autre ne peut montrer.

         Le billet est donc remonte ici, au premier plan d'une mosaique
         d'affiches. Son QR Code fonctionne reellement.
    ---------------------------------------------------------------- --}}
    <section class="relative overflow-hidden">
        {{-- Nappe ambiante. `aria-hidden` et `pointer-events-none` : c'est du
             decor, il ne doit ni etre annonce ni intercepter un clic. --}}
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div class="lp-glow lp-glow-a -top-40 -left-32 size-[38rem]"></div>
            <div class="lp-glow lp-glow-b top-20 -right-40 size-[34rem]"></div>
        </div>

        <div class="mx-auto grid w-full max-w-6xl gap-8 px-5 pt-7 pb-6 sm:gap-12 sm:px-6 sm:pt-14 sm:pb-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)] lg:items-center lg:gap-10 lg:pt-16 lg:pb-12">

            <div class="text-center lg:text-left">
                {{-- L'accroche. Reprise de la section Tarifs plus bas : elle y
                     est deja ecrite noir sur blanc, donc verifiable. --}}
                <p class="lp-enter lp-enter-1 glass-inset inline-flex items-center gap-2 px-3.5 py-1.5 text-xs font-bold tracking-wide text-azure-700 uppercase">
                    <x-lucide-badge-check class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                    0 abonnement, 0 frais d'ouverture
                </p>

                <h1 class="lp-enter lp-enter-2 mt-5 font-display text-[2.5rem] leading-[1.06] font-extrabold text-ink sm:text-6xl lg:text-[3.75rem]">
                    Publiez aujourd'hui.<br>
                    <span class="text-azure-600">Vendez ce soir.</span>
                </h1>

                {{-- Le mot qui tourne : le seul effet focal de la page. Il dit
                     l'etendue du produit sans allonger le titre de quatre
                     lignes. Le premier mot reste affiche si le mouvement est
                     refuse — la phrase garde son sens. --}}
                <p class="lp-enter lp-enter-2 mt-3 font-display text-xl font-bold text-grey-500 sm:text-2xl">
                    Pour un
                    <span class="lp-rotator text-azure-600">
                        <span class="lp-rotator-sizer">concert de quartier</span>
                        <span class="lp-rotator-word">concert de quartier</span>
                        <span class="lp-rotator-word">gala d'association</span>
                        <span class="lp-rotator-word">tournoi de football</span>
                        <span class="lp-rotator-word">appel aux dons</span>
                    </span>
                </p>

                <p class="lp-enter lp-enter-3 mx-auto mt-5 max-w-xl text-base leading-relaxed text-grey-500 sm:mt-6 sm:text-lg lg:mx-0">
                    Creez votre evenement, partagez son lien sur WhatsApp. Vos participants paient
                    par Airtel Money ou Moov Money et recoivent leur billet dans la seconde.
                    Vos agents scannent a l'entree, meme sans reseau.
                </p>

                <div class="lp-enter lp-enter-4 mt-6 flex flex-col items-center justify-center gap-2.5 sm:mt-8 sm:flex-row sm:gap-3 lg:justify-start">
                    <a href="{{ route('register') }}" class="btn btn-primary btn-pill btn-lg w-full sm:w-auto">
                        Creer ma billetterie
                        <x-lucide-arrow-right class="size-4" stroke-width="2" aria-hidden="true" />
                    </a>
                    <a href="#parcours" class="lp-hero-secondary btn btn-secondary btn-pill btn-lg w-full sm:w-auto">
                        Voir comment ca marche
                    </a>
                </div>

                <p class="lp-enter lp-enter-4 mt-4 flex items-center justify-center gap-2 text-sm text-grey-500 sm:mt-6 lg:justify-start">
                    <x-lucide-shield-check class="size-4 shrink-0 text-azure-500" stroke-width="2" aria-hidden="true" />
                    Inscription libre. La verification n'intervient qu'au moment d'etre paye.
                </p>
            </div>

            {{-- ----------------------------------------------------------
                 Mosaique et billet

                 Les affiches sont des compositions ABSTRAITES, generees dans la
                 famille azurine : ni photographie, ni visage, ni marque.
                 Publier de fausses photos de concert sur le site d'une vraie
                 plateforme serait une information fausse.

                 Elles sont `aria-hidden` et sans texte alternatif : elles ne
                 portent aucune information, seulement une ambiance.
            ----------------------------------------------------------- --}}
            {{-- Le billet, seul.

                 Les affiches d'evenements qui l'entouraient ont leur propre
                 section, juste dessous : elles y disent quelque chose (la
                 variete des evenements couverts), alors qu'empilees derriere le
                 billet elles n'etaient qu'une texture, et lui prenaient la
                 place qui revient au seul objet que personne d'autre ne peut
                 montrer.

                 Vitrine, et non le billet a taille reelle : a sa taille,
                 l'objet depasse la hauteur de l'ecran et ecrase la colonne de
                 texte. `zoom` et non `scale` — le premier reduit la BOITE, le
                 second seulement le dessin, et la section garderait alors le
                 vide de la taille d'origine sous le billet. --}}
            <div class="lp-enter lp-enter-3 flex justify-center lg:justify-end">
                <div class="lp-ticket-solo">
                    <x-glass-ticket
                        :animate="true"
                        ticket-type="Billet VIP"
                        event-name="Concert Afro 2026"
                        date="14 mars 2026"
                        venue="Libreville"
                        holder="Awa Ndong"
                        reference="PS241-4KX9-27B"
                        :qr-data="route('register')"
                    />
                </div>
            </div>
        </div>

        {{-- L'invitation a scanner porte sur le QR du billet : elle doit rester
             a cote de lui, pas ailleurs dans la page. --}}
        <p class="mx-auto flex w-full max-w-6xl justify-center px-5 pb-8 sm:px-6 sm:pb-10 lg:justify-end lg:pb-14">
            <span class="glass-inset inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold text-azure-700">
                <x-lucide-scan-line class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                Ce QR Code fonctionne vraiment. Scannez-le.
            </span>
        </p>
    </section>

    {{-- ---------------------------------------------------------------
         Les evenements couverts.

         Sa propre section, et non une texture derriere le heros. Elle repond a
         la seule question qu'un organisateur se pose avant toute autre : « est
         ce que ca marche pour CE que j'organise ? » Une reponse en images se
         lit en un coup d'oeil, la meme reponse en liste demande d'etre lue.

         Les compositions sont ABSTRAITES, generees dans la famille azurine :
         ni photographie, ni visage, ni marque. Publier de fausses photos de
         concert sur le site d'une vraie plateforme serait une information
         fausse — la vignette pose une ambiance, c'est le libelle qui porte
         l'information.
    ---------------------------------------------------------------- --}}
    <section id="evenements" class="defer-paint mx-auto w-full max-w-6xl scroll-mt-24 px-5 py-14 sm:px-6 lg:py-20">
        <div data-reveal class="mx-auto max-w-2xl text-center">
            <h2 class="font-display text-3xl leading-tight font-extrabold text-ink sm:text-4xl">
                Un seul outil, tous vos evenements
            </h2>
            <p class="mx-auto mt-4 max-w-xl leading-relaxed text-grey-500">
                Que vous remplissiez une salle de cinquante personnes ou un stade, le parcours
                est le meme : vous publiez, vous partagez, vous encaissez.
            </p>
        </div>

        <div data-reveal data-reveal-step="1" class="lp-mosaic mt-12">
            @foreach ([
                ['affiche-concert', 'Concerts et festivals', true],
                ['affiche-gala', 'Galas et soirees', false],
                ['affiche-sport', 'Rencontres sportives', false],
                ['affiche-conference', 'Conferences et ateliers', true],
            ] as $index => [$file, $label, $tall])
                <figure class="lp-mosaic-item">
                    <img src="{{ asset('brand/landing/' . $file . '.webp') }}" alt=""
                         width="480" height="600" loading="lazy" decoding="async"
                         class="lp-tile {{ $tall ? 'lp-tile-tall' : '' }}">
                    <figcaption class="mt-4 text-center font-display text-sm font-bold text-ink sm:text-base">
                        {{ $label }}
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </section>

    {{-- ---------------------------------------------------------------
         Le parcours.

         Vraie sequence chronologique : l'ordre porte une information, donc
         la revelation en cascade a du sens ici — ce qu'elle n'aurait pas
         sur une section isolee.
    ---------------------------------------------------------------- --}}
    <section id="parcours" class="defer-paint mx-auto w-full max-w-6xl scroll-mt-24 px-5 py-14 sm:px-6 lg:py-20">
        <div class="glass glass-panel px-6 py-10 sm:px-10 lg:px-14 lg:py-14">
            <h2 class="max-w-2xl font-display text-3xl leading-tight font-extrabold text-ink sm:text-4xl">
                Du lien partage au billet scanne
            </h2>
            <p class="mt-3 max-w-xl text-grey-500">
                Vous apportez votre audience, nous fournissons la technique. Pas de catalogue
                public, pas d'intermediaire entre vous et vos participants.
            </p>

            {{-- La pastille deborde le haut de chaque carte : la rangee se lit
                 donc d'abord comme une FILE de quatre reperes, avant qu'un seul
                 mot ne soit lu. `mt-14` reserve la place de ce debordement,
                 faute de quoi la premiere rangee mordrait sur le titre. --}}
            <ol class="mt-14 grid gap-x-4 gap-y-14 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['calendar-plus', 'Vous publiez', 'Nom, date, lieu, types de billets et prix. Votre evenement recoit un lien unique.'],
                    ['share-2', 'Vous partagez', 'Le lien part sur WhatsApp, Instagram ou une affiche. Il ouvre un mini-site a votre nom.'],
                    ['smartphone', 'Le participant paie', 'Airtel Money ou Moov Money. Il valide sur son telephone, son billet s\'affiche aussitot.'],
                    ['scan-line', 'Vos agents scannent', 'A l\'entree, sur telephone. Le controle fonctionne hors connexion et se synchronise ensuite.'],
                ] as $step => $item)
                    <li data-reveal data-reveal-step="{{ $step }}" class="lp-feature-card">
                        <span class="lp-feature-badge">
                            <x-dynamic-component :component="'lucide-' . $item[0]" class="size-6" stroke-width="1.5" aria-hidden="true" />
                        </span>
                        <h3 class="font-display text-base font-bold text-ink">{{ $item[1] }}</h3>
                        <p class="mt-2.5 text-sm leading-relaxed text-grey-500">{{ $item[2] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ---------------------------------------------------------------
         Billetterie en detail — accordeon natif.
    ---------------------------------------------------------------- --}}
    <section id="billetterie" class="defer-paint mx-auto w-full max-w-6xl scroll-mt-24 px-5 py-14 sm:px-6 lg:py-20">
        <div data-reveal class="mx-auto max-w-2xl text-center">
            <h2 class="font-display text-3xl leading-tight font-extrabold text-ink sm:text-4xl">
                Une experience simple pour vous,<br class="hidden sm:block">
                vos equipes et vos participants.
            </h2>
            <p class="mx-auto mt-4 max-w-xl leading-relaxed text-grey-500">
                Vous n'avez rien a developper. Chaque evenement publie devient un mini-site
                complet, avec son affiche, ses billets et son paiement.
            </p>
        </div>

        {{-- ------------------------------------------------------------
             Un SEUL aplat decoupe par des filets, plutot que six cartes
             separees.

             La difference se lit : des cartes disent « voici des elements »,
             un bloc decoupe dit « voici un ENSEMBLE ». Pour des capacites qui
             vont toutes ensemble, c'est le second sens qui est juste.

             Il remplace l'accordeon qui occupait cette place. L'accordeon
             obligeait a ouvrir quatre fois pour connaitre l'offre, et trois
             lignes sur quatre restaient invisibles a l'arrivee — sur une page
             dont le travail est justement de dire ce que fait le produit.
        ------------------------------------------------------------- --}}
        <div data-reveal data-reveal-step="1" class="lp-grid mt-12">
            @foreach ([
                ['layout-template', 'Mini-site evenement', 'Affiche, description, programme, informations pratiques, types de billets et paiement. Une adresse unique du type pass241.app/e/votre-evenement, prete a partager.'],
                ['ticket', 'Billets et badges', 'Billets VIP, Premium, Standard ou Early Bird, avec stock et dates de vente. Invitations gratuites et codes promo. Les badges servent a identifier et a controler l\'acces, jamais a payer.'],
                ['scan-line', 'Controle des entrees', 'Vos agents scannent depuis leur telephone, avec plusieurs points d\'entree. Le mode hors connexion garde tout en memoire locale et se synchronise des le retour du reseau.'],
                ['code-xml', 'Integration a votre site', 'Un bouton d\'achat ou un module de billetterie a poser sur votre propre site, pour ceux qui en ont un. Le paiement et le billet restent identiques.'],
                ['users', 'Vos equipes', 'Vous pouvez confier la gestion operationnelle a une personne de confiance, sans lui ouvrir l\'acces a vos finances.'],
                ['line-chart', 'Suivi en direct', 'Billets vendus, recettes, entrees scannees : votre tableau de bord se met a jour au fil des ventes et des scans.'],
            ] as $item)
                <div class="lp-grid-cell">
                    <span class="lp-grid-icon">
                        <x-dynamic-component :component="'lucide-' . $item[0]" class="size-6" stroke-width="1.5" aria-hidden="true" />
                    </span>
                    {{-- Sans utilitaire de couleur : `text-white` vit dans la
                         couche `utilities`, qui l'emporte sur `components`. La
                         regle de survol de `.lp-grid-cell` ne pouvait donc
                         jamais reprendre la main — la cellule passait en clair
                         et le texte restait blanc, donc invisible. La couleur
                         est tenue en CSS, la ou l'etat survole la change. --}}
                    <h3 class="mt-5 font-display text-base font-bold sm:text-lg">{{ $item[1] }}</h3>
                    <p class="mt-2.5 max-w-xs text-sm leading-relaxed">{{ $item[2] }}</p>
                </div>
            @endforeach
        </div>

        <div data-reveal data-reveal-step="2" class="mt-10 flex justify-center">
            <a href="{{ route('register') }}" class="btn btn-primary btn-pill btn-lg">
                Commencer maintenant
                <x-lucide-arrow-right class="size-4" stroke-width="2" aria-hidden="true" />
            </a>
        </div>
    </section>

    {{-- ---------------------------------------------------------------
         Paiement et commission.
    ---------------------------------------------------------------- --}}
    <section id="paiement" class="defer-paint mx-auto w-full max-w-6xl scroll-mt-24 px-5 py-14 sm:px-6 lg:py-20">
        <div class="grid gap-10 lg:grid-cols-2 lg:items-center lg:gap-16">
            <div data-reveal>
                <h2 class="font-display text-3xl leading-tight font-extrabold text-ink sm:text-4xl">
                    Paye au telephone,<br>comme tout le monde ici.
                </h2>
                <p class="mt-4 max-w-md leading-relaxed text-grey-500">
                    Le participant choisit son operateur, saisit son numero, valide le code recu
                    sur son telephone. Aucune carte bancaire, aucun compte a creer.
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    @foreach ([['Airtel Money', 'bg-danger'], ['Moov Money', 'bg-azure-500']] as [$operator, $dot])
                        <span class="glass-inset flex items-center gap-2.5 px-4 py-2.5 text-sm font-semibold text-ink">
                            <span class="size-2.5 rounded-full {{ $dot }}"></span>
                            {{ $operator }}
                        </span>
                    @endforeach
                </div>

                <p class="mt-8 max-w-md text-sm leading-relaxed text-grey-500">
                    Nous encaissons le paiement, retenons notre commission et vous reversons le
                    reste. La plateforme n'est jamais depositaire de vos fonds.
                </p>
            </div>

            {{-- Banniere de modele economique.

                 Elle ne porte AUCUN taux : le detail de la commission ne figure
                 pas sur cette page. Ce qui est promis ici est verifiable sans
                 chiffre — pas d'abonnement, pas de frais d'entree, rien a
                 avancer — et c'est precisement ce qui leve le frein a
                 l'inscription. --}}
            <div data-reveal data-reveal-step="1" class="lp-banner px-6 py-10 text-center sm:px-10 sm:py-12">
                <p class="font-display text-3xl leading-tight font-extrabold text-white sm:text-4xl">
                    Vous n'avancez rien.
                </p>
                <p class="mx-auto mt-4 max-w-sm leading-relaxed text-white/85">
                    Aucun abonnement, aucun frais d'ouverture, aucun engagement. Notre commission
                    est prelevee automatiquement sur les transactions reussies, et sur elles
                    seules.
                </p>

                <div class="mt-8 grid gap-px text-left sm:grid-cols-2">
                    @foreach ([
                        ['wallet', 'Ouvrir votre billetterie ne coute rien'],
                        ['badge-check', 'Vous n\'etes preleve que si vous vendez'],
                    ] as $index => [$icon, $label])
                        <div class="flex items-start gap-3 py-4 sm:px-5 {{ $index === 1 ? 'lp-banner-split' : '' }}">
                            <x-dynamic-component :component="'lucide-' . $icon" class="mt-0.5 size-5 shrink-0 text-white/70" stroke-width="1.5" aria-hidden="true" />
                            <span class="text-sm leading-relaxed font-semibold text-white">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ---------------------------------------------------------------
         Collectes de fonds.
    ---------------------------------------------------------------- --}}
    <section id="collectes" class="defer-paint mx-auto w-full max-w-6xl scroll-mt-24 px-5 py-14 sm:px-6 lg:py-20">
        <div class="glass glass-panel px-6 py-10 sm:px-10 lg:px-14 lg:py-14">
            <div class="grid gap-10 lg:grid-cols-[0.95fr_1.05fr] lg:items-center lg:gap-16">
                <div data-reveal>
                    <h2 class="font-display text-3xl leading-tight font-extrabold text-ink sm:text-4xl">
                        Des collectes que personne ne peut maquiller
                    </h2>
                    <p class="mt-4 leading-relaxed text-grey-500">
                        Le montant collecte et la progression sont calcules par nos serveurs a partir
                        des dons reellement confirmes. Le createur d'une collecte ne peut ni supprimer
                        un don, ni modifier un montant, ni retoucher un pourcentage. Les donateurs
                        peuvent rester anonymes ; leur don, lui, reste toujours public.
                    </p>
                </div>

                {{-- La barre de progression est la donnee elle-meme, pas une
                     illustration : c'est a quoi ressemble une page de collecte. --}}
                <div data-reveal data-reveal-step="1" class="glass-inset p-6 sm:p-8">
                    <p class="text-sm font-semibold text-grey-500">Renovation du foyer des jeunes</p>

                    <div class="mt-4 flex items-baseline justify-between gap-4">
                        <p class="font-display text-2xl font-extrabold text-ink tabular sm:text-3xl">
                            650 000 <span class="text-lg font-bold text-grey-500">FCFA</span>
                        </p>
                        <p class="font-display text-xl font-bold text-azure-600 tabular">65 %</p>
                    </div>

                    <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-azure-100">
                        <div class="h-full rounded-full bg-gradient-to-r from-azure-600 to-azure-300" style="width: 65%"></div>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-sm text-grey-500">
                        <span class="tabular">Objectif 1 000 000 FCFA</span>
                        <span class="tabular">42 dons</span>
                    </div>

                    <p class="mt-6 border-t border-grey-200/70 pt-4 text-xs text-grey-400">
                        Exemple illustratif.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ---------------------------------------------------------------
         Appel final.
    ---------------------------------------------------------------- --}}
    <section class="defer-paint mx-auto w-full max-w-6xl px-5 py-14 sm:px-6 lg:py-20">
        <div data-reveal class="glass glass-panel glass-specular px-6 py-14 text-center sm:px-10 sm:py-20">
            <h2 class="mx-auto max-w-xl font-display text-3xl leading-tight font-extrabold text-ink sm:text-[2.75rem]">
                Votre prochain evenement peut ouvrir a la vente aujourd'hui
            </h2>
            <p class="mx-auto mt-5 max-w-md leading-relaxed text-grey-500">
                L'inscription est libre et la vente demarre immediatement. La verification
                d'identite n'intervient qu'au moment de recevoir vos recettes.
            </p>
            {{-- Sans `wire:navigate` : cette page ne charge pas Livewire, donc
                 l'attribut n'y est jamais interprete. --}}
            <a href="{{ route('register') }}" class="btn btn-primary btn-pill btn-lg mt-9">
                Creer mon compte
                <x-lucide-arrow-right class="size-4" stroke-width="2" />
            </a>
        </div>
    </section>
</main>

<footer class="border-t border-grey-200 bg-surface/60">
    <div class="mx-auto w-full max-w-6xl px-5 py-14 sm:px-6 lg:py-16">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2 lg:col-span-1">
                <span class="flex items-center gap-2 font-display text-lg font-extrabold tracking-tight text-azure-700">
                    <x-brand-mark class="h-6 w-auto" />
                    <x-brand-word />
                </span>
                <p class="mt-4 max-w-xs text-sm leading-relaxed text-grey-500">
                    Billetterie, controle d'acces et collecte de fonds. Concu au Gabon, pour
                    les organisateurs gabonais.
                </p>
            </div>

            @foreach ([
                ['Produit', [
                    ['Comment ca marche', '#parcours'],
                    ['Billetterie', '#billetterie'],
                    ['Collectes de fonds', '#collectes'],
                    ['Paiement', '#paiement'],
                ]],
                ['Commencer', [
                    ['Creer un compte', 'route:register'],
                    ['Se connecter', 'route:login'],
                ]],
            ] as [$heading, $links])
                <div>
                    <h3 class="font-display text-sm font-bold text-ink">{{ $heading }}</h3>
                    <ul class="mt-4 space-y-2.5">
                        @foreach ($links as [$label, $href])
                            <li>
                                <a href="{{ str_starts_with($href, 'route:') ? route(substr($href, 6)) : $href }}"
                                   class="text-sm text-grey-500 transition-colors hover:text-azure-700">
                                    {{ $label }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            {{--
                Coordonnees volontairement absentes : publier un numero ou une
                adresse inventes sur le site d'une vraie entreprise serait une
                fausse information. A completer avec les coordonnees reelles
                avant la mise en ligne.
            --}}
            <div>
                <h3 class="font-display text-sm font-bold text-ink">Contact</h3>
                <p class="mt-4 text-sm leading-relaxed text-grey-500">
                    Libreville, Gabon.
                </p>
            </div>
        </div>

        <div class="mt-12 border-t border-grey-200/70 pt-6 text-sm text-grey-400">
            &copy; {{ now()->year }} <x-brand-word />
        </div>
    </div>
</footer>

</body>
</html>
