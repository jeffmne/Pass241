@php
    use App\Enums\PermissionName;

    $organization = app(\App\Support\Tenancy\OrganizationContext::class)->current();

    /*
     * Mobile-first : la meme liste sert la barre inferieure sur telephone et la
     * colonne laterale a partir de lg.
     *
     * PLUS AUCUNE ENTREE INERTE. Le menu annoncait « Billetterie » et
     * « Controle d'acces » en gris, en attendant leur module. Ces deux modules
     * existent depuis — mais ils ne sont pas des destinations : ils vivent DANS
     * un evenement, et l'ecran d'un evenement y mene. Une entree grise
     * permanente ne dit plus « c'est pour bientot », elle se lit comme une
     * panne.
     *
     * Ce qui figure ici mene donc quelque part, toujours.
     */
    $navigation = [
        ['label' => 'Vue generale', 'icon' => 'layout-dashboard', 'route' => 'dashboard', 'ready' => true],
        ['label' => 'Evenements', 'icon' => 'calendar-days', 'route' => 'events.index', 'match' => 'events.*', 'ready' => true, 'permission' => PermissionName::EventsView->value],
        ['label' => 'Collectes', 'icon' => 'hand-coins', 'route' => 'campaigns.index', 'match' => 'campaigns.*', 'ready' => true, 'permission' => PermissionName::CampaignsView->value],
        // Remboursements : le seul ecran financier transverse. Les reversements,
        // eux, s'affichent sur la vue generale — l'organisateur n'a rien a y
        // decider (section 21.2).
        ['label' => 'Finances', 'icon' => 'wallet', 'route' => 'refunds.index', 'match' => 'refunds.*', 'ready' => true, 'permission' => PermissionName::FinancesView->value],
        ['label' => 'Parametres', 'icon' => 'settings', 'route' => 'organization.settings', 'ready' => true],
    ];

    $visible = array_values(array_filter(
        $navigation,
        fn (array $item) => ! isset($item['permission']) || auth()->user()?->can($item['permission'])
    ));
@endphp
<!DOCTYPE html>
<html lang="fr" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#2F8FE6">
    <title>{{ $title ?? 'Pass241' }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-offwhite">
    <div class="lg:flex">
        {{-- Colonne laterale : uniquement a partir de lg --}}
        <aside class="hidden lg:sticky lg:top-0 lg:flex lg:h-dvh lg:w-64 lg:shrink-0 lg:flex-col lg:border-r lg:border-grey-200 lg:bg-white">
            <div class="px-5 py-6">
                <span class="flex items-center gap-2 font-display text-xl font-extrabold tracking-tight text-azure-700">
                    <x-brand-mark class="h-7 w-auto" />
                    Pass241
                </span>
            </div>

            <nav class="flex-1 space-y-0.5 px-3">
                @foreach ($visible as $item)
                    @if ($item['ready'] && $item['route'])
                        <a href="{{ route($item['route']) }}"
                           @class([
                               'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                               'bg-azure-100 text-azure-700' => request()->routeIs($item['match'] ?? $item['route']),
                               'text-grey-500 hover:bg-azure-50 hover:text-azure-700' => ! request()->routeIs($item['match'] ?? $item['route']),
                           ])>
                            <x-dynamic-component :component="'lucide-' . $item['icon']" class="size-5" stroke-width="1.5" />
                            {{ $item['label'] }}
                        </a>
                    @else
                        <span class="flex cursor-not-allowed items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-grey-400"
                              title="Module a venir">
                            <x-dynamic-component :component="'lucide-' . $item['icon']" class="size-5" stroke-width="1.5" />
                            {{ $item['label'] }}
                        </span>
                    @endif
                @endforeach
            </nav>

            <div class="border-t border-grey-200 p-3">
                <livewire:organizations.switcher />
            </div>
        </aside>

        <div class="flex min-h-dvh flex-1 flex-col">
            {{-- En-tete mobile --}}
            <header class="sticky top-0 z-20 border-b border-grey-200 bg-white/90 backdrop-blur lg:hidden">
                <div class="flex items-center justify-between px-4 py-3">
                    <span class="flex items-center gap-2 font-display text-lg font-extrabold tracking-tight text-azure-700">
                        <x-brand-mark class="h-7 w-auto" />
                        Pass241
                    </span>
                    <livewire:organizations.switcher :compact="true" />
                </div>
            </header>

            <main class="flex-1 px-4 pt-5 pb-24 sm:px-6 lg:px-8 lg:pt-8 lg:pb-10">
                <div class="mx-auto w-full max-w-5xl">
                    @if ($organization?->needsKycAttention())
                        <x-kyc-banner :organization="$organization" />
                    @endif

                    {{ $slot }}
                </div>
            </main>

            {{-- Barre de navigation inferieure : telephone uniquement.
                 Limitee a quatre entrees, au-dela les cibles tactiles deviennent
                 trop etroites sur les petits ecrans du marche vise. --}}
            <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-grey-200 bg-white lg:hidden"
                 style="padding-bottom: env(safe-area-inset-bottom)">
                <div class="grid grid-cols-4">
                    @foreach (array_slice($visible, 0, 4) as $item)
                        @if ($item['ready'] && $item['route'])
                            <a href="{{ route($item['route']) }}"
                               @class([
                                   'flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-[11px] font-medium',
                                   'text-azure-600' => request()->routeIs($item['match'] ?? $item['route']),
                                   'text-grey-500' => ! request()->routeIs($item['match'] ?? $item['route']),
                               ])>
                                <x-dynamic-component :component="'lucide-' . $item['icon']" class="size-5" stroke-width="1.5" />
                                <span class="truncate">{{ $item['label'] }}</span>
                            </a>
                        @else
                            <span class="flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-[11px] font-medium text-grey-400">
                                <x-dynamic-component :component="'lucide-' . $item['icon']" class="size-5" stroke-width="1.5" />
                                <span class="truncate">{{ $item['label'] }}</span>
                            </span>
                        @endif
                    @endforeach
                </div>
            </nav>
        </div>
    </div>
</body>
</html>
