@php
    use App\Enums\PermissionName;

    /*
     * Les deux ecrans financiers de l'organisateur (section 56).
     *
     * Ils sont sous la meme entree de menu « Finances » : sans ces onglets,
     * atteindre les remboursements depuis les transactions imposerait de
     * repasser par le menu principal, qui pointe vers l'un des deux seulement.
     *
     * Les reversements n'y figurent pas : ils s'affichent sur la vue generale,
     * et l'organisateur n'a rien a y decider (section 21.2).
     */
    $tabs = array_values(array_filter([
        [
            'label' => 'Transactions',
            'route' => 'finances.transactions',
            'permission' => PermissionName::FinancesView->value,
        ],
        [
            'label' => 'Remboursements',
            'route' => 'refunds.index',
            'permission' => PermissionName::FinancesView->value,
        ],
    ], fn (array $tab) => auth()->user()?->can($tab['permission'])));
@endphp

{{--
    Onglets de la section Finances.

    De vrais liens et non des boutons : chaque ecran a son adresse, se met en
    favori et s'ouvre dans un onglet. Le defilement lateral evite qu'ils passent
    a la ligne sur telephone.
--}}
@if (count($tabs) > 1)
    <div class="scroll-x no-scrollbar -mx-1 px-1">
        <nav class="seg" aria-label="Sections des finances">
            @foreach ($tabs as $tab)
                @php $current = request()->routeIs($tab['route']); @endphp

                {{-- `aria-current` et non `aria-selected` : ce sont des liens
                     vers des pages, pas des onglets d'un meme document. --}}
                <a href="{{ route($tab['route']) }}"
                   wire:navigate
                   class="seg-item"
                   @if ($current) aria-current="page" @endif>
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </nav>
    </div>
@endif
