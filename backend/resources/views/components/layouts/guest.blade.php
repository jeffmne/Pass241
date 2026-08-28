@props([
    'title' => 'Pass241',
    // 'narrow' : formulaire court, une colonne centree (connexion).
    // 'wide'   : formulaire long, deux colonnes a partir de lg (inscription).
    'width' => 'narrow',
])
<!DOCTYPE html>
<html lang="fr" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-theme-script />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f7f9fb">
    <title>{{ $title }}</title>
    <meta name="robots" content="noindex">
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
    {{-- Polices auto-hebergees : sans cet appel, les `@font-face` ne sont
         declares nulle part et le navigateur retombe sur la police systeme.
         Voir le commentaire detaille dans `components/app-shell.blade.php`. --}}
    {{ Vite::fonts() }}

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="azure-wash min-h-dvh">
    {{-- Mobile-first : une colonne centree quel que soit le mode. La largeur
         ne s'ouvre qu'a partir de lg, et seulement pour les formulaires assez
         longs pour en tirer profit. --}}
    <main @class([
        'mx-auto flex min-h-dvh w-full flex-col justify-center px-5 py-10 sm:px-6',
        'max-w-md' => $width === 'narrow',
        'max-w-md lg:max-w-5xl lg:py-16' => $width === 'wide',
    ])>
        {{-- En mode large, le logo s'aligne sur la colonne de gauche plutot que
             sur le centre de la page : centre, il flotterait entre les deux
             colonnes sans se rattacher a rien. --}}
        <a href="{{ route('home') }}" wire:navigate
           @class([
               'mb-8 flex w-fit items-center gap-2 font-display text-2xl font-extrabold tracking-tight text-azure-700',
               'mx-auto' => $width === 'narrow',
               'mx-auto lg:mx-0' => $width === 'wide',
           ])>
            <x-brand-mark class="h-8 w-auto" />
            <x-brand-word />
        </a>

        {{ $slot }}

        <p class="mt-8 text-center text-xs text-grey-500">
            Billetterie, controle d'acces et collecte de fonds
        </p>
    </main>
</body>
</html>
