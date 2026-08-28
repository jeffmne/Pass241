@props([
    'id' => 'search',
    'label' => 'Rechercher',
    'placeholder' => 'Rechercher…',
])

{{--
    Champ de recherche.

    Le libelle existe et il est reel — simplement masque visuellement. L'icone
    de loupe suffit a un oeil, elle ne dit rien a un lecteur d'ecran, qui
    annoncerait sinon « zone de saisie » sans plus de precision.

    `type="search"` et non `type="text"` : c'est ce qui donne la croix
    d'effacement native et, sur mobile, la touche « Rechercher » sur le clavier
    plutot que « Entree ».
--}}
<div class="relative">
    <label for="{{ $id }}" class="sr-only">{{ $label }}</label>

    <x-lucide-search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-grey-400"
                     stroke-width="2" aria-hidden="true" />

    {{-- Tous les attributs passes vont sur le CHAMP, pas sur l'enveloppe : un
         `wire:model` pose sur un <div> ne lie rien du tout, et l'erreur est
         silencieuse. --}}
    <input type="search"
           id="{{ $id }}"
           placeholder="{{ $placeholder }}"
           {{ $attributes->class('field field-search') }}>

    {{-- Le disque de chargement occupe la place de la croix native, a droite :
         il dit que la recherche travaille sans faire bouger le champ. --}}
    <span wire:loading.delay
          class="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin rounded-full border-2 border-azure-300 border-t-transparent"
          aria-hidden="true"></span>
</div>
