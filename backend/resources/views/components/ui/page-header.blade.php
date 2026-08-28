@props([
    'title',
    'description' => null,
    // Lien de retour, quand l'ecran est une profondeur d'un autre.
    'back' => null,
    'backLabel' => 'Retour',
    // Surtitre discret : nom de l'evenement ou de la collecte dont on regarde
    // un sous-ecran. Il situe la page sans allonger son titre.
    'eyebrow' => null,
])

{{--
    En-tete de page. UN SEUL pour tout le produit (regle 34).

    Trois choses, toujours dans le meme ordre : ou l'on est, ce qu'on peut y
    faire, et — quand il y a une profondeur — comment revenir. Un utilisateur
    qui passe de « Evenements » a « Reversements » ne doit pas avoir a chercher
    ou se trouve le titre.

    Mobile-first : l'action principale passe SOUS le titre et occupe toute la
    largeur. Coincee a droite d'un titre sur 375px, elle se reduit a une cible
    de 90px collee au bord de l'ecran — le pouce d'un droitier la manque une
    fois sur trois. A partir de `sm`, elle reprend sa place a droite.
--}}
<header {{ $attributes->class('mb-6') }}>
    @if ($back)
        <a href="{{ $back }}" wire:navigate
           class="mb-3 -ml-1.5 inline-flex min-h-11 items-center gap-1.5 rounded-lg px-1.5 text-sm font-semibold text-grey-500 transition hover:text-azure-700">
            <x-lucide-arrow-left class="size-4" stroke-width="2" />
            {{ $backLabel }}
        </a>
    @endif

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            @if ($eyebrow)
                <p class="mb-1 truncate text-xs font-bold tracking-wider text-grey-400 uppercase">
                    {{ $eyebrow }}
                </p>
            @endif

            <h1 class="font-display text-2xl font-extrabold tracking-tight text-ink sm:text-3xl">
                {{ $title }}
            </h1>

            @if ($description)
                <p class="mt-1 max-w-2xl text-sm text-grey-500">{{ $description }}</p>
            @endif
        </div>

        @isset($actions)
            {{-- `shrink-0` cote a cote, pleine largeur empile : sur telephone
                 chaque bouton fait toute la largeur, ce qui donne la cible la
                 plus sure possible. --}}
            <div class="flex shrink-0 flex-col gap-2 sm:flex-row sm:items-center [&>*]:w-full sm:[&>*]:w-auto">
                {{ $actions }}
            </div>
        @endisset
    </div>
</header>
