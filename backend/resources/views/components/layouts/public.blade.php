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
    <meta name="theme-color" content="#F5F7FD">

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

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">

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
                'data-[stuck=true]:bg-white/70 data-[stuck=true]:shadow-[0_1px_0_rgba(20,84,155,0.08)]',
                'data-[stuck=true]:backdrop-blur-xl',
                'data-[stuck=false]:bg-gradient-to-b data-[stuck=false]:from-ink/50 data-[stuck=false]:to-transparent' => $heroDark,
            ])>
        <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-5 py-3.5 sm:px-6">
            <a href="{{ route('home') }}"
               @class([
                   'flex items-center gap-2 font-display text-lg font-extrabold tracking-tight transition-colors',
                   'text-white group-data-[stuck=true]:text-azure-700' => $heroDark,
                   'text-azure-700' => ! $heroDark,
               ])>
                <x-brand-mark class="h-7 w-auto" />
                Pass241
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

    <footer class="border-t border-grey-200/70 bg-white/60">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-2 px-5 py-8 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <a href="{{ route('home') }}" class="font-display text-base font-extrabold tracking-tight text-azure-700">
                Pass241
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
