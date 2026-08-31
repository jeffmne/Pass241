@props([
    'title' => 'Pass241',
    'description' => null,
    'image' => null,
    'canonical' => null,
    // Section 11 : un evenement prive reste accessible par son lien mais ne
    // doit pas remonter dans un moteur de recherche.
    'indexable' => true,
    'event' => null,
    // JSON-LD deja encode, ou null. Voir le commentaire plus bas.
    'structuredData' => null,
    // Vrai quand la page ouvre sur un heros sombre : l'en-tete passe alors en
    // blanc tant qu'il flotte au-dessus de l'image.
    'heroDark' => false,
])
<!DOCTYPE html>
<html lang="fr" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Le theme est pose avant la premiere peinture, y compris sur les pages
         publiques : quelqu'un qui ouvre un lien de billet en pleine nuit ne
         doit pas recevoir un ecran blanc au visage. --}}
    <x-theme-script />
    <meta name="theme-color" content="#f7f9fb">

    <title>{{ $title }}</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    @if ($canonical)
        <link rel="canonical" href="{{ $canonical }}">
    @endif
    @unless ($indexable)
        <meta name="robots" content="noindex, nofollow">
    @endunless

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
        Apercu de partage (section 14). WhatsApp est le canal principal sur ce
        marche : il lit `og:title`, `og:description` et `og:image`, et ignore
        les images de plus de 600 Ko environ — d'ou une affiche bornee a 4 Mo
        et servie telle quelle, sans variante lourde.
    --}}
    <meta property="og:type" content="{{ $event ? 'article' : 'website' }}">
    <meta property="og:site_name" content="Pass241">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:title" content="{{ $title }}">
    @if ($description)
        <meta property="og:description" content="{{ $description }}">
    @endif
    @if ($canonical)
        <meta property="og:url" content="{{ $canonical }}">
    @endif
    @if ($image)
        <meta property="og:image" content="{{ $image }}">
        <meta name="twitter:card" content="summary_large_image">
    @else
        <meta name="twitter:card" content="summary">
    @endif

    {{--
        Donnees structurees : elles permettent a un moteur de recherche
        d'afficher la date et le lieu directement dans ses resultats.

        Le JSON est construit par le composant, jamais ici : les cles du
        vocabulaire schema.org commencent par `@` (`@context`, `@type`), que
        Blade prendrait pour des directives et refuserait de compiler.
    --}}
    @if ($structuredData)
        <script type="application/ld+json">{!! $structuredData !!}</script>
    @endif

    {{-- Polices auto-hebergees : sans cet appel, les `@font-face` ne sont
         declares nulle part et le navigateur retombe sur la police systeme.
         Voir le commentaire detaille dans `components/app-shell.blade.php`. --}}
    {{ Vite::fonts() }}

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="azure-wash flex min-h-dvh flex-col overflow-x-hidden">

    {{--
        En-tete flottant. Au-dessus d'un heros sombre il passe en blanc, avec
        un voile degrade derriere : sans lui, la lisibilite du logo dependrait
        de l'image choisie par l'organisateur.
    --}}
    {{-- `group` sur l'en-tete : c'est lui qui porte `data-stuck`, les enfants
         reagissent via `group-data-*`. Une variante `data-*` posee sur un
         enfant lirait son propre attribut, qui n'existe pas. --}}
    <header data-sticky-header data-stuck="false"
            @class([
                'group fixed inset-x-0 top-0 z-40 transition-[background-color,box-shadow,backdrop-filter] duration-300',
                'data-[stuck=false]:bg-gradient-to-b data-[stuck=false]:from-ink/50 data-[stuck=false]:to-transparent' => $heroDark,
            ])>
        <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-5 py-3.5 sm:px-6">
            <a href="{{ route('home') }}"
               @class([
                   'flex items-center gap-2 font-display text-lg font-extrabold tracking-tight transition-colors',
                   'text-white group-data-[stuck=true]:text-azure-700' => $heroDark,
                   'text-azure-700' => ! $heroDark,
               ])>
                {{-- LE LOGO EN COULEUR, PARTOUT, y compris au-dessus d'une
                     affiche sombre.

                     La variante `mono` etait employee ici : un contour plat en
                     `currentColor`, sans le billet blanc, sans le degrade, sans
                     le « 241 ». Ce n'est pas le logo, c'en est une silhouette —
                     et c'etait la premiere chose que voyait un visiteur qui
                     ouvre le lien, puis de nouveau des qu'il remontait en haut
                     de page.

                     Rien ne l'imposait : le pictogramme porte un billet BLANC
                     et un bleu vif, qui se detachent franchement du voile
                     sombre du heros. Et ce voile n'est pas l'image de
                     l'organisateur — c'est un degre que le produit maitrise
                     (`event-hero-scrim`), justement pour que la lisibilite de
                     ce qui est pose dessus ne dependent pas de la photo.

                     La variante `mono` reste utile la ou la couleur ne survit
                     pas au support : capture du billet en image, impression en
                     noir et blanc. Elle n'a simplement rien a faire ici. --}}
                <x-brand-mark class="h-7 w-auto" />

                @if ($heroDark)
                    {{-- Deux exemplaires, et un seul visible a la fois. Le
                         « 241 » ne peut pas avoir la meme teinte au-dessus
                         d'une affiche sombre et sur l'en-tete blanc une fois la
                         page defilee : le bleu clair disparaitrait sur blanc,
                         le bleu foncé sur la photo. Une classe conditionnelle
                         ne suffit pas — c'est la VARIANTE du composant qui
                         change, pas seulement sa couleur. --}}
                    <x-brand-word variant="light" class="group-data-[stuck=true]:hidden" />
                    <x-brand-word class="hidden group-data-[stuck=true]:inline" />
                @else
                    <x-brand-word />
                @endif
            </a>
            <span @class([
                'text-xs transition-colors',
                'text-white/80 group-data-[stuck=true]:text-grey-500' => $heroDark,
                'text-grey-500' => ! $heroDark,
            ])>
                Billetterie securisee
            </span>
        </div>
    </header>

    {{-- Pas de `pt` ici : le heros commence sous un en-tete flottant et remonte
         volontairement derriere lui. Les pages sans heros gerent leur propre
         retrait. --}}
    <main class="flex-grow">
        {{ $slot }}
    </main>

    <footer class="border-t border-grey-200 bg-surface/60">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-2 px-5 py-8 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <a href="{{ route('home') }}" class="font-display text-base font-extrabold tracking-tight text-azure-700">
                <x-brand-word />
            </a>
            <p class="text-sm text-grey-500">
                Vous organisez un evenement ?
                <a href="{{ route('register') }}" class="font-semibold text-azure-600 hover:text-azure-700">
                    Creez votre billetterie
                </a>
            </p>
        </div>
    </footer>

</body>
</html>
