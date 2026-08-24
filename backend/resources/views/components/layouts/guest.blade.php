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
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#F5F7FD">
    <title>{{ $title }}</title>
    <meta name="robots" content="noindex">
    <link rel="icon" href="/favicon.ico" sizes="any">
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
            <x-lucide-ticket class="size-7" stroke-width="1.75" />
            Pass241
        </a>

        {{ $slot }}

        <p class="mt-8 text-center text-xs text-grey-500">
            Billetterie, controle d'acces et collecte de fonds
        </p>
    </main>
</body>
</html>
