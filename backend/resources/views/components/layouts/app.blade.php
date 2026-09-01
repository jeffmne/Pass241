@php
    use App\Enums\PermissionName;

    $organization = app(\App\Support\Tenancy\OrganizationContext::class)->current();

    /*
     * Navigation de l'espace organisateur.
     *
     * La MEME liste sert la colonne laterale a partir de lg et la barre
     * inferieure sur telephone. C'est ce qui garantit qu'un organisateur qui
     * passe du bureau au telephone retrouve les memes destinations, dans le
     * meme ordre.
     *
     * PLUS AUCUNE ENTREE INERTE. Le menu annoncait autrefois « Billetterie » et
     * « Controle d'acces » en gris, en attendant leur module. Ces deux modules
     * existent depuis — mais ce ne sont pas des destinations : ils vivent DANS
     * un evenement, et l'ecran d'un evenement y mene. Une entree grise
     * permanente ne dit plus « c'est pour bientot », elle se lit comme une
     * panne.
     *
     * Ce qui figure ici mene donc quelque part, toujours.
     *
     * Les modules absents de la maquette de reference — Tombola, Billets
     * physiques, Vote — ne figurent PAS ici : ils n'existent pas dans le
     * produit. Le module Votes est planifie en phase 9 (section 86) et
     * prendra sa place le jour ou il aura un ecran a montrer ; les deux autres
     * sont hors perimetre (section 79). Une entree qui ne mene nulle part coute
     * plus cher qu'une entree manquante.
     */
    $navigation = array_values(array_filter([
        ['label' => 'Vue generale', 'icon' => 'layout-dashboard', 'route' => 'dashboard'],
        ['label' => 'Evenements', 'icon' => 'calendar-days', 'route' => 'events.index', 'match' => 'events.*', 'permission' => PermissionName::EventsView->value],
        ['label' => 'Collectes', 'icon' => 'hand-coins', 'route' => 'campaigns.index', 'match' => 'campaigns.*', 'permission' => PermissionName::CampaignsView->value],
        ['label' => 'Votes', 'icon' => 'vote', 'route' => 'polls.index', 'match' => 'polls.*', 'permission' => PermissionName::VotesView->value],
        // Finances : le grand livre des transactions d'abord — c'est ce qu'on
        // vient lire, alors qu'un remboursement se traite quand il arrive. Les
        // deux ecrans partagent une sous-navigation (`x-finance-nav`), d'ou le
        // `match` sur les deux familles de routes. Les reversements, eux,
        // s'affichent sur la vue generale : l'organisateur n'a rien a y
        // decider (section 21.2).
        ['label' => 'Finances', 'icon' => 'wallet', 'route' => 'finances.transactions', 'match' => ['finances.*', 'refunds.*'], 'permission' => PermissionName::FinancesView->value],
        ['label' => 'Parametres', 'icon' => 'settings', 'route' => 'organization.settings'],
    ], fn (array $item) => ! isset($item['permission']) || auth()->user()?->can($item['permission'])));
@endphp

<x-app-shell :title="$title ?? 'Pass241'" :navigation="$navigation">

    {{-- Pied de colonne : l'organisation courante, l'utilisateur, et de quoi
         changer de l'une a l'autre. C'est aussi la sortie — la deconnexion n'a
         rien a faire dans la navigation principale. --}}
    <x-slot:railFooter>
        <livewire:organizations.switcher />
    </x-slot:railFooter>

    <x-slot:mobileHeader>
        <livewire:organizations.switcher :compact="true" />
    </x-slot:mobileHeader>

    {{-- Le bandeau de verification precede TOUT contenu d'ecran : il porte la
         seule chose qui empeche l'organisateur d'etre paye (sections 10.1,
         21.2). Le releguer plus bas reviendrait a le cacher. --}}
    @if ($organization?->needsKycAttention())
        <x-kyc-banner :organization="$organization" />
    @endif

    {{ $slot }}
</x-app-shell>
