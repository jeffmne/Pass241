<!DOCTYPE html>
<html lang="fr" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2F8FE6">
    <title>Pass241 — Billetterie et collecte de fonds</title>
    <meta name="description" content="Creez votre evenement, vendez vos billets par Airtel Money et Moov Money, controlez les entrees. Plateforme concue pour le Gabon.">
    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="azure-wash min-h-dvh">
    <header class="mx-auto flex w-full max-w-5xl items-center justify-between px-4 py-4 sm:px-6">
        <span class="font-display text-xl font-extrabold tracking-tight text-azure-700">Pass241</span>

        @auth
            <a href="{{ route('dashboard') }}" class="btn btn-primary">Mon espace</a>
        @else
            <div class="flex items-center gap-1">
                <a href="{{ route('login') }}" class="btn btn-ghost">Connexion</a>
                <a href="{{ route('register') }}" class="btn btn-primary">Creer un compte</a>
            </div>
        @endauth
    </header>

    <main class="mx-auto w-full max-w-5xl px-4 pt-8 pb-16 sm:px-6 sm:pt-16">
        <h1 class="max-w-2xl font-display text-3xl leading-tight font-extrabold text-ink sm:text-5xl">
            Vendez vos billets, controlez vos entrees, collectez des fonds.
        </h1>
        <p class="mt-4 max-w-xl text-base text-grey-500 sm:text-lg">
            Creez votre evenement, partagez son lien, encaissez par Airtel Money et Moov Money.
            Vos participants recoivent leur billet immediatement.
        </p>

        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
            <a href="{{ route('register') }}" class="btn btn-primary">Creer mon compte</a>
            <a href="{{ route('login') }}" class="btn btn-ghost">J'ai deja un compte</a>
        </div>

        <div class="mt-12 grid gap-3 sm:mt-16 sm:grid-cols-3">
            <div class="glass p-5">
                <x-lucide-ticket class="size-6 text-azure-500" stroke-width="1.5" />
                <h2 class="mt-3 font-display text-base font-bold text-ink">Billetterie</h2>
                <p class="mt-1 text-sm text-grey-500">
                    Types de billets, stock, codes promo et invitations.
                </p>
            </div>
            <div class="glass p-5">
                <x-lucide-scan-line class="size-6 text-azure-500" stroke-width="1.5" />
                <h2 class="mt-3 font-display text-base font-bold text-ink">Controle d'acces</h2>
                <p class="mt-1 text-sm text-grey-500">
                    Scan des QR codes par vos agents, y compris hors connexion.
                </p>
            </div>
            <div class="glass p-5">
                <x-lucide-hand-coins class="size-6 text-azure-500" stroke-width="1.5" />
                <h2 class="mt-3 font-display text-base font-bold text-ink">Collecte de fonds</h2>
                <p class="mt-1 text-sm text-grey-500">
                    Campagnes transparentes, progression calculee automatiquement.
                </p>
            </div>
        </div>
    </main>
</body>
</html>
