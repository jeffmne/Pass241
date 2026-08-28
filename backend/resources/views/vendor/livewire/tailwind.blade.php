{{--
    Pagination Pass241.

    Le gabarit livre par Laravel est ecrit contre `bg-white`, `text-gray-500` et
    `border-gray-300` — des couleurs figees qui ne suivent aucun theme. Sur un
    ecran sombre, la pagination restait un bloc blanc en bas de chaque tableau.
    Il est donc reecrit contre les jetons du produit.

    Mobile-first, et c'est la difference la plus visible : sur telephone, seuls
    « Precedent » et « Suivant » restent, en deux cibles pleine largeur. La
    rangee de numeros de Laravel produit sur 375px une dizaine de cibles de
    28px collees les unes aux autres, impossibles a viser au pouce. Les numeros
    reapparaissent a partir de `sm`, ou il y a la place de les espacer.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex items-center justify-between gap-3">

        {{-- Telephone : deux cibles, rien d'autre. --}}
        <div class="flex flex-1 items-center justify-between gap-3 sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="btn btn-secondary flex-1 cursor-not-allowed opacity-50">Precedent</span>
            @else
                <button type="button" wire:click="previousPage" wire:loading.attr="disabled"
                        rel="prev" class="btn btn-secondary flex-1">Precedent</button>
            @endif

            <span class="shrink-0 text-xs text-grey-500 tabular">
                {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage" wire:loading.attr="disabled"
                        rel="next" class="btn btn-secondary flex-1">Suivant</button>
            @else
                <span class="btn btn-secondary flex-1 cursor-not-allowed opacity-50">Suivant</span>
            @endif
        </div>

        {{-- A partir de sm : le compte a gauche, les numeros a droite. --}}
        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between sm:gap-4">
            <p class="text-sm text-grey-500 tabular">
                {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
                sur {{ $paginator->total() }}
            </p>

            <div class="flex items-center gap-1">
                @if ($paginator->onFirstPage())
                    <span class="btn btn-icon btn-icon-sm cursor-not-allowed opacity-40" aria-disabled="true">
                        <x-lucide-chevron-left class="size-4" stroke-width="2" aria-hidden="true" />
                        <span class="sr-only">Page precedente</span>
                    </span>
                @else
                    <button type="button" wire:click="previousPage" wire:loading.attr="disabled"
                            rel="prev" class="btn btn-icon btn-icon-sm">
                        <x-lucide-chevron-left class="size-4" stroke-width="2" aria-hidden="true" />
                        <span class="sr-only">Page precedente</span>
                    </button>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="px-1.5 text-sm text-grey-400" aria-hidden="true">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                {{-- `aria-current` et non une simple couleur :
                                     c'est ce qui fait annoncer « page 3, page
                                     actuelle » par un lecteur d'ecran. --}}
                                <span aria-current="page"
                                      class="btn btn-primary btn-icon-sm px-2.5 text-sm tabular">{{ $page }}</span>
                            @else
                                <button type="button" wire:click="gotoPage({{ $page }})"
                                        wire:loading.attr="disabled"
                                        class="btn btn-icon btn-icon-sm px-2.5 text-sm tabular"
                                        aria-label="Aller a la page {{ $page }}">{{ $page }}</button>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage" wire:loading.attr="disabled"
                            rel="next" class="btn btn-icon btn-icon-sm">
                        <x-lucide-chevron-right class="size-4" stroke-width="2" aria-hidden="true" />
                        <span class="sr-only">Page suivante</span>
                    </button>
                @else
                    <span class="btn btn-icon btn-icon-sm cursor-not-allowed opacity-40" aria-disabled="true">
                        <x-lucide-chevron-right class="size-4" stroke-width="2" aria-hidden="true" />
                        <span class="sr-only">Page suivante</span>
                    </span>
                @endif
            </div>
        </div>
    </nav>
@endif
