<!DOCTYPE html>
<html lang="fr" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#F5F7FD">
    <title>Pass241 — Billetterie et collecte de fonds au Gabon</title>
    <meta name="description" content="Creez votre evenement, partagez son lien, encaissez par Airtel Money et Moov Money. Vos participants recoivent leur billet immediatement, vos agents scannent a l'entree meme sans reseau.">
    <link rel="icon" href="/favicon.ico" sizes="any">

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
        class="fixed inset-x-0 top-0 z-50 transition-[background-color,box-shadow,backdrop-filter] duration-300
               data-[stuck=true]:bg-white/70 data-[stuck=true]:shadow-[0_1px_0_rgba(20,84,155,0.08)]
               data-[stuck=true]:backdrop-blur-xl">
    <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-5 py-3.5 sm:px-6">
        <a href="{{ route('home') }}"
           class="flex shrink-0 items-center gap-2 font-display text-xl font-extrabold tracking-tight text-azure-700">
            <x-lucide-ticket class="size-6" stroke-width="1.75" />
            Pass241
        </a>

        <nav class="hidden items-center gap-1 md:flex" aria-label="Navigation principale">
            @foreach ([
                ['#parcours', 'Comment ca marche'],
                ['#billetterie', 'Billetterie'],
                ['#collectes', 'Collectes'],
                ['#tarifs', 'Tarifs'],
            ] as [$href, $label])
                <a href="{{ $href }}"
                   class="rounded-lg px-3 py-2 text-sm font-semibold text-grey-500 transition-colors hover:bg-azure-100/70 hover:text-azure-700">
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        <div class="flex shrink-0 items-center gap-1.5">
            @auth
                <a href="{{ route('dashboard') }}" class="btn btn-primary btn-pill">Mon espace</a>
            @else
                <a href="{{ route('login') }}" wire:navigate class="btn btn-ghost btn-pill hidden sm:inline-flex">
                    Connexion
                </a>
                <a href="{{ route('register') }}" wire:navigate class="btn btn-primary btn-pill">
                    <span class="sm:hidden">Commencer</span>
                    <span class="hidden sm:inline">Creer un evenement</span>
                </a>
            @endauth

            {{-- Les trois modules du produit. Le menu est en <details> natif :
                 utilisable au clavier et sans script. --}}
            <details class="disclosure relative">
                <summary aria-label="Autres actions"
                         class="flex size-11 items-center justify-center rounded-full text-azure-700 transition-colors hover:bg-azure-100/70">
                    <x-lucide-ellipsis-vertical class="size-5" stroke-width="1.75" />
                </summary>

                <div class="disclosure-body glass glass-panel absolute right-0 z-10 mt-2 w-72 overflow-hidden p-2">
                    <a href="{{ route('register') }}" wire:navigate
                       class="flex items-start gap-3 rounded-2xl p-3 transition-colors hover:bg-white/70">
                        <x-lucide-ticket class="mt-0.5 size-5 shrink-0 text-azure-600" stroke-width="1.5" />
                        <span>
                            <span class="block text-sm font-semibold text-ink">Creer une billetterie</span>
                            <span class="mt-0.5 block text-xs text-grey-500">Evenement, types de billets, controle des entrees</span>
                        </span>
                    </a>

                    <a href="{{ route('register') }}" wire:navigate
                       class="flex items-start gap-3 rounded-2xl p-3 transition-colors hover:bg-white/70">
                        <x-lucide-hand-coins class="mt-0.5 size-5 shrink-0 text-azure-600" stroke-width="1.5" />
                        <span>
                            <span class="block text-sm font-semibold text-ink">Lancer une collecte</span>
                            <span class="mt-0.5 block text-xs text-grey-500">Campagne, objectif, progression publique</span>
                        </span>
                    </a>

                    {{-- Le module Votes est planifie en phase 9. Il est annonce
                         mais rendu inerte : un lien qui ne mene nulle part est
                         une promesse que le produit ne tient pas encore. --}}
                    <span class="flex cursor-not-allowed items-start gap-3 rounded-2xl p-3 opacity-60">
                        <x-lucide-vote class="mt-0.5 size-5 shrink-0 text-grey-400" stroke-width="1.5" />
                        <span>
                            <span class="flex items-center gap-2 text-sm font-semibold text-grey-500">
                                Creer un vote
                                <span class="rounded-full bg-grey-200 px-2 py-0.5 text-[10px] font-bold tracking-wide text-grey-500 uppercase">
                                    A venir
                                </span>
                            </span>
                            <span class="mt-0.5 block text-xs text-grey-400">Candidats, votes gratuits ou payants</span>
                        </span>
                    </span>
                </div>
            </details>
        </div>
    </div>
</header>

<main class="flex-grow pt-20 sm:pt-24">

    {{-- ---------------------------------------------------------------
         Heros
    ---------------------------------------------------------------- --}}
    <section class="mx-auto w-full max-w-6xl px-5 pt-10 pb-4 text-center sm:px-6 sm:pt-16 lg:pt-20">
        <h1 class="mx-auto max-w-3xl font-display text-[2.5rem] leading-[1.06] font-extrabold text-ink sm:text-6xl lg:text-[4rem]">
            Publiez aujourd'hui.<br>
            <span class="text-azure-600">Vendez ce soir.</span>
        </h1>

        <p class="mx-auto mt-6 max-w-2xl text-base leading-relaxed text-grey-500 sm:text-lg">
            Creez votre evenement, partagez son lien sur WhatsApp. Vos participants paient
            par Airtel Money ou Moov Money et recoivent leur billet dans la seconde.
            Vos agents scannent a l'entree, meme sans reseau.
        </p>

        <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <a href="{{ route('register') }}" wire:navigate class="btn btn-primary btn-pill btn-lg w-full sm:w-auto">
                Creer ma billetterie
                <x-lucide-arrow-right class="size-4" stroke-width="2" />
            </a>
            <a href="#parcours" class="btn btn-ghost btn-pill btn-lg w-full sm:w-auto">
                Voir comment ca marche
            </a>
        </div>

        <p class="mt-7 flex items-center justify-center gap-2 text-sm text-grey-500">
            <x-lucide-shield-check class="size-4 shrink-0 text-azure-500" stroke-width="1.5" />
            Inscription libre. La verification n'intervient qu'au moment d'etre paye.
        </p>
    </section>

    {{-- ---------------------------------------------------------------
         Le billet — moment fort de la page.

         Ce que Pass241 produit, c'est ce billet. Le montrer en vrai, avec
         un QR reellement scannable, dit en une seconde ce qu'un paragraphe
         mettrait a expliquer. Le reflet suit le pointeur, comme un badge
         plastifie qu'on incline pour capter la lumiere.
    ---------------------------------------------------------------- --}}
    <section class="mx-auto w-full max-w-6xl px-5 py-12 sm:px-6 lg:py-20">
        {{-- Aucun panneau autour du billet : il est la seule surface en verre
             de cette section. Un panneau en verre l'aurait contenu dans un
             second niveau de flou, ce qu'interdit la section 70.4 — et qui lui
             aurait retire la presence que sa position de moment fort exige. --}}
        <div>
            <div class="grid items-center gap-10 lg:grid-cols-[1fr_auto] lg:gap-16">
                <div class="text-center lg:text-left">
                    <h2 class="font-display text-2xl leading-tight font-extrabold text-ink sm:text-3xl lg:text-4xl">
                        Le billet arrive avant que la file ne se forme
                    </h2>
                    <p class="mx-auto mt-4 max-w-md leading-relaxed text-grey-500 lg:mx-0">
                        Des que le paiement est confirme, le billet s'affiche sur le telephone du
                        participant. Il reste accessible depuis son espace, et fonctionne a l'entree
                        meme si le reseau tombe.
                    </p>

                    <ul class="mx-auto mt-7 grid max-w-md gap-3 text-left sm:grid-cols-2 lg:mx-0">
                        @foreach ([
                            'Aucune inscription pour acheter',
                            'Recuperable sans adresse email',
                            'Telechargeable en PDF',
                            'Code signe, jamais copiable',
                        ] as $point)
                            <li class="flex items-start gap-2 text-sm text-ink">
                                <x-lucide-check class="mt-0.5 size-4 shrink-0 text-azure-600" stroke-width="2.25" />
                                {{ $point }}
                            </li>
                        @endforeach
                    </ul>

                    <p class="glass-inset mt-7 inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold text-azure-700">
                        <x-lucide-scan-line class="size-4" stroke-width="1.75" />
                        Ce QR Code fonctionne vraiment. Scannez-le.
                    </p>
                </div>

                <div class="flex justify-center lg:justify-end">
                    <div class="w-full max-w-sm lg:rotate-[1.5deg]">
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

            <ol class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['calendar-plus', 'Vous publiez', 'Nom, date, lieu, types de billets et prix. Votre evenement recoit un lien unique.'],
                    ['share-2', 'Vous partagez', 'Le lien part sur WhatsApp, Instagram ou une affiche. Il ouvre un mini-site a votre nom.'],
                    ['smartphone', 'Le participant paie', 'Airtel Money ou Moov Money. Il valide sur son telephone, son billet s\'affiche aussitot.'],
                    ['scan-line', 'Vos agents scannent', 'A l\'entree, sur telephone. Le controle fonctionne hors connexion et se synchronise ensuite.'],
                ] as $step => $item)
                    <li data-reveal data-reveal-step="{{ $step }}" class="glass-inset flex flex-col p-6">
                        <span class="flex size-11 items-center justify-center rounded-2xl bg-azure-100 text-azure-700">
                            <x-dynamic-component :component="'lucide-' . $item[0]" class="size-5" stroke-width="1.5" />
                        </span>
                        <h3 class="mt-5 font-display text-base font-bold text-ink">{{ $item[1] }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-grey-500">{{ $item[2] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ---------------------------------------------------------------
         Billetterie en detail — accordeon natif.
    ---------------------------------------------------------------- --}}
    <section id="billetterie" class="defer-paint mx-auto w-full max-w-6xl scroll-mt-24 px-5 py-14 sm:px-6 lg:py-20">
        <div class="grid gap-10 lg:grid-cols-2 lg:items-start lg:gap-16">
            <div data-reveal>
                <h2 class="font-display text-3xl leading-tight font-extrabold text-ink sm:text-4xl">
                    Une experience simple pour vous,<br class="hidden sm:block">
                    vos equipes et vos participants.
                </h2>
                <p class="mt-4 max-w-md leading-relaxed text-grey-500">
                    Vous n'avez rien a developper. Chaque evenement publie devient un mini-site
                    complet, avec son affiche, ses billets et son paiement.
                </p>
                <a href="{{ route('register') }}" wire:navigate class="btn btn-primary btn-pill mt-7">
                    Commencer maintenant
                    <x-lucide-arrow-right class="size-4" stroke-width="2" />
                </a>
            </div>

            <div data-reveal data-reveal-step="1" class="space-y-3">
                @foreach ([
                    ['Mini-site evenement', 'Affiche, description, programme, informations pratiques, types de billets et paiement. Une adresse unique du type pass241.app/e/votre-evenement, prete a partager.'],
                    ['Billets et badges', 'Billets VIP, Premium, Standard ou Early Bird, avec stock et dates de vente. Invitations gratuites et codes promo. Les badges servent a identifier et a controler l\'acces, jamais a payer.'],
                    ['Controle des entrees', 'Vos agents scannent depuis leur telephone, avec plusieurs points d\'entree. Le mode hors connexion garde tout en memoire locale et se synchronise des le retour du reseau.'],
                    ['Integration a votre site', 'Un bouton d\'achat ou un module de billetterie a poser sur votre propre site, pour ceux qui en ont un. Le paiement et le billet restent identiques.'],
                ] as $index => [$title, $body])
                    <details class="disclosure glass-inset overflow-hidden" @if($index === 0) open @endif>
                        <summary class="flex items-center justify-between gap-4 p-5 sm:p-6">
                            <span class="font-display text-base font-bold text-ink sm:text-lg">{{ $title }}</span>
                            <x-lucide-chevron-down class="disclosure-chevron size-5 shrink-0 text-grey-400" stroke-width="2" />
                        </summary>
                        <div class="disclosure-body px-5 pb-5 text-sm leading-relaxed text-grey-500 sm:px-6 sm:pb-6">
                            {{ $body }}
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ---------------------------------------------------------------
         Paiement et commission.
    ---------------------------------------------------------------- --}}
    <section id="tarifs" class="defer-paint mx-auto w-full max-w-6xl scroll-mt-24 px-5 py-14 sm:px-6 lg:py-20">
        <div class="grid gap-10 lg:grid-cols-2 lg:gap-16">
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

            <div data-reveal data-reveal-step="1" class="glass glass-panel divide-y divide-white/60 overflow-hidden">
                @foreach ([
                    ['Billetterie', '5 %', 'preleves sur chaque billet vendu'],
                    ['Collecte de fonds', '2,5 %', 'preleves sur chaque don confirme'],
                ] as $line)
                    <div class="flex items-baseline justify-between gap-4 p-6 sm:p-8">
                        <div>
                            <p class="font-display text-base font-bold text-ink">{{ $line[0] }}</p>
                            <p class="mt-1 text-sm text-grey-500">{{ $line[2] }}</p>
                        </div>
                        <p class="font-display text-3xl font-extrabold text-azure-600 tabular">{{ $line[1] }}</p>
                    </div>
                @endforeach

                <p class="p-6 text-sm leading-relaxed text-grey-500 sm:p-8">
                    Aucun abonnement, aucun frais d'ouverture, aucun engagement. La commission est
                    calculee automatiquement a chaque transaction.
                </p>
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
            <a href="{{ route('register') }}" wire:navigate class="btn btn-primary btn-pill btn-lg mt-9">
                Creer mon compte
                <x-lucide-arrow-right class="size-4" stroke-width="2" />
            </a>
        </div>
    </section>
</main>

<footer class="border-t border-grey-200/70 bg-white/60">
    <div class="mx-auto w-full max-w-6xl px-5 py-14 sm:px-6 lg:py-16">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2 lg:col-span-1">
                <span class="flex items-center gap-2 font-display text-lg font-extrabold tracking-tight text-azure-700">
                    <x-lucide-ticket class="size-5" stroke-width="1.75" />
                    Pass241
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
                    ['Tarifs', '#tarifs'],
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
            &copy; {{ now()->year }} Pass241
        </div>
    </div>
</footer>

</body>
</html>
