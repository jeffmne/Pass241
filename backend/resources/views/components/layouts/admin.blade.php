@props(['title' => 'Administration Pass241'])

@php
    /*
     * Console d'administration de la plateforme (section 58).
     *
     * Reprend le gabarit du dashboard organisateur : meme colonne laterale a
     * partir de lg, meme barre inferieure sur telephone, meme palette azurine.
     * Un administrateur passe d'un espace a l'autre — lui faire changer de
     * langage visuel lui ferait reapprendre l'interface a chaque aller-retour.
     *
     * Une seule difference, et elle est necessaire : la pastille
     * « Administration » sous la marque. Quelqu'un qui voit toutes les
     * organisations doit le savoir en permanence, et ne jamais confondre cette
     * console avec l'espace d'un organisateur.
     *
     * La colonne laterale est le SEUL endroit du produit ou une colonne
     * entiere est en verre : elle est fixe, ne defile pas, et se detache de la
     * nappe ambiante. Ce sont les trois conditions qui rendent l'effet lisible
     * plutot que decoratif (section 70.4). Les tableaux, eux, restent des
     * surfaces pleines — la meme regle l'exige.
     */
    $navigation = [
        ['label' => 'Vue generale', 'icon' => 'layout-dashboard', 'route' => 'admin.overview'],
        ['label' => 'Reversements', 'icon' => 'wallet', 'route' => 'admin.settlements'],
        ['label' => 'Organisations', 'icon' => 'building-2', 'route' => 'admin.organizations'],
        ['label' => 'Transactions', 'icon' => 'receipt', 'route' => 'admin.transactions'],
        ['label' => 'Journal', 'icon' => 'scroll-text', 'route' => 'admin.audit'],
    ];
@endphp
<!DOCTYPE html>
<html lang="fr" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#2F8FE6">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="azure-wash min-h-dvh">
    <div class="lg:flex">
        {{-- Colonne laterale : uniquement a partir de lg --}}
        <aside class="glass-rail hidden lg:sticky lg:top-0 lg:flex lg:h-dvh lg:w-64 lg:shrink-0 lg:flex-col">
            <div class="px-5 py-6">
                <span class="flex items-center gap-2 font-display text-xl font-extrabold tracking-tight text-azure-700">
                    <x-brand-mark class="h-7 w-auto" />
                    Pass241
                </span>
                <span class="mt-2 inline-flex rounded-full bg-azure-100 px-2.5 py-0.5 text-[11px] font-semibold tracking-wide text-azure-700 uppercase">
                    Administration
                </span>
            </div>

            <nav class="flex-1 space-y-0.5 px-3">
                @foreach ($navigation as $item)
                    <a href="{{ route($item['route']) }}"
                       @class([
                           'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                           'bg-white/70 text-azure-700 shadow-[0_1px_8px_rgba(20,84,155,0.08)]' => request()->routeIs($item['route']),
                           'text-grey-500 hover:bg-white/50 hover:text-azure-700' => ! request()->routeIs($item['route']),
                       ])>
                        <x-dynamic-component :component="'lucide-'.$item['icon']" class="size-5" stroke-width="1.5" />
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="space-y-1 border-t border-white/60 p-3">
                <a href="{{ route('dashboard') }}"
                   class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-grey-500 transition hover:bg-azure-50 hover:text-azure-700">
                    <x-lucide-arrow-left class="size-5" stroke-width="1.5" />
                    Mon espace
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-grey-500 transition hover:bg-azure-50 hover:text-azure-700">
                        <x-lucide-log-out class="size-5" stroke-width="1.5" />
                        Deconnexion
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex min-h-dvh flex-1 flex-col">
            {{-- En-tete mobile --}}
            <header class="glass-rail sticky top-0 z-20 lg:hidden">
                <div class="flex items-center justify-between px-4 py-3">
                    <span class="flex items-center gap-2 font-display text-lg font-extrabold tracking-tight text-azure-700">
                        <x-brand-mark class="h-7 w-auto" />
                        Pass241
                    </span>
                    <span class="rounded-full bg-azure-100 px-2.5 py-1 text-[11px] font-semibold tracking-wide text-azure-700 uppercase">
                        Administration
                    </span>
                </div>
            </header>

            <main class="flex-1 px-4 pt-5 pb-24 sm:px-6 lg:px-8 lg:pt-8 lg:pb-10">
                <div class="mx-auto w-full max-w-6xl">
                    {{ $slot }}
                </div>
            </main>

            {{-- Barre de navigation inferieure : telephone uniquement.
                 Quatre entrees au maximum, au-dela les cibles tactiles
                 deviennent trop etroites sur les petits ecrans du marche vise. --}}
            <nav class="glass-rail fixed inset-x-0 bottom-0 z-20 lg:hidden"
                 style="padding-bottom: env(safe-area-inset-bottom)">
                <div class="grid grid-cols-4">
                    @foreach (array_slice($navigation, 0, 4) as $item)
                        <a href="{{ route($item['route']) }}"
                           @class([
                               'flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-[11px] font-medium',
                               'text-azure-600' => request()->routeIs($item['route']),
                               'text-grey-500' => ! request()->routeIs($item['route']),
                           ])>
                            <x-dynamic-component :component="'lucide-'.$item['icon']" class="size-5" stroke-width="1.5" />
                            <span class="truncate">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </nav>
        </div>
    </div>
</body>
</html>
