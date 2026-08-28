@props(['title' => 'Administration Pass241'])

@php
    /*
     * Console d'administration de la plateforme (section 58).
     *
     * Elle partage le MEME shell que l'espace organisateur : meme colonne
     * laterale, meme barre inferieure, meme palette, meme bascule
     * d'apparence. Un administrateur passe d'un espace a l'autre plusieurs
     * fois par jour — lui faire changer de langage visuel lui ferait
     * reapprendre l'interface a chaque aller-retour.
     *
     * Une seule difference, et elle est necessaire : la pastille
     * « Administration » sous la marque. Quelqu'un qui voit toutes les
     * organisations doit le savoir en permanence, et ne jamais confondre cette
     * console avec l'espace d'un organisateur.
     *
     * Les entrees sont groupees parce qu'elles repondent a deux questions
     * differentes : « ou en est l'argent » et « qui sont les gens ». Sans
     * groupes, six entrees d'affilee se lisent comme une liste plate ou l'on
     * cherche a chaque fois.
     */
    $navigation = [
        ['label' => 'Vue generale', 'icon' => 'layout-dashboard', 'route' => 'admin.overview'],
        ['label' => 'Reversements', 'icon' => 'wallet', 'route' => 'admin.settlements', 'group' => 'Flux financiers'],
        ['label' => 'Transactions', 'icon' => 'receipt', 'route' => 'admin.transactions', 'group' => 'Flux financiers'],
        ['label' => 'Organisations', 'icon' => 'building-2', 'route' => 'admin.organizations', 'match' => 'admin.organization*', 'group' => 'Plateforme'],
        ['label' => 'Journal', 'icon' => 'scroll-text', 'route' => 'admin.audit', 'group' => 'Plateforme'],
        ['label' => 'Parametres', 'icon' => 'settings', 'route' => 'admin.settings', 'group' => 'Plateforme'],
    ];
@endphp

<x-app-shell :title="$title" :navigation="$navigation" badge="Administration">

    <x-slot:railFooter>
        <div class="space-y-0.5">
            <a href="{{ route('dashboard') }}" wire:navigate class="rail-link">
                <x-lucide-arrow-left class="size-5 shrink-0" stroke-width="1.75" aria-hidden="true" />
                Mon espace
            </a>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rail-link w-full">
                    <x-lucide-log-out class="size-5 shrink-0" stroke-width="1.75" aria-hidden="true" />
                    Deconnexion
                </button>
            </form>
        </div>
    </x-slot:railFooter>

    {{-- Sur telephone, les memes deux sorties vivent dans la feuille « Plus » :
         elles ne meritent pas une cible dans la barre inferieure, mais elles
         doivent rester atteignables sans passer par le bureau. --}}
    <x-slot:mobileOverflow>
        <div class="mt-1 space-y-0.5 border-t border-grey-200 pt-1">
            <a href="{{ route('dashboard') }}" wire:navigate role="menuitem"
               class="flex min-h-12 items-center gap-3 rounded-[var(--radius-sm)] px-3 text-sm font-medium text-grey-500 transition hover:bg-surface-muted hover:text-azure-700">
                <x-lucide-arrow-left class="size-5 shrink-0" stroke-width="1.75" aria-hidden="true" />
                Mon espace
            </a>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" role="menuitem"
                        class="flex min-h-12 w-full items-center gap-3 rounded-[var(--radius-sm)] px-3 text-sm font-medium text-grey-500 transition hover:bg-surface-muted hover:text-azure-700">
                    <x-lucide-log-out class="size-5 shrink-0" stroke-width="1.75" aria-hidden="true" />
                    Deconnexion
                </button>
            </form>
        </div>
    </x-slot:mobileOverflow>

    {{ $slot }}
</x-app-shell>
