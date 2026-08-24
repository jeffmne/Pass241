<!DOCTYPE html>
<html lang="fr" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#F5F7FD">
    <title>{{ $title ?? 'Pass241' }}</title>
    <meta name="robots" content="noindex">
    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="azure-wash min-h-dvh">
    {{-- Mobile-first : une colonne centree, respiration verticale reduite sur
         petit ecran, jamais de mise en page a deux colonnes avant lg. --}}
    <main class="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-5 py-10 sm:px-6">
        <a href="{{ route('home') }}" wire:navigate
           class="mx-auto mb-8 flex items-center gap-2 font-display text-2xl font-extrabold tracking-tight text-azure-700">
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
