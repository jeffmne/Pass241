@props([
    // Expression Alpine qui pilote l'ouverture, par exemple `open` ou
    // `$wire.confirming`. Le composant ne possede pas son etat : c'est
    // l'ecran appelant qui sait quand la modale doit s'ouvrir.
    'show',
    'title',
    'description' => null,
    // sm | md | lg
    'size' => 'md',
])

@php
    $id = 'modal-' . Str::random(6);
    $width = match ($size) {
        'sm' => 'sm:max-w-md',
        'lg' => 'sm:max-w-2xl',
        default => 'sm:max-w-lg',
    };
@endphp

{{--
    Modale. UNE seule ecriture pour toutes les confirmations du produit
    (regle 34) : publier un evenement, annuler une commande, decider d'un
    remboursement.

    Le PANNEAU est une surface pleine, malgre l'esthetique de verre du reste du
    produit. Ce n'est pas une entorse : une modale flotte au-dessus du contenu
    de la page, et c'est justement ce contenu qu'on veut cesser de lire. Un
    panneau translucide laisserait le tableau du dessous transparaitre sous le
    formulaire de confirmation — exactement l'inverse du but. Le verre reste sur
    le VOILE, ou il est a sa place.

    Sur telephone, elle arrive PAR LE BAS et se colle au bord inferieur. Une
    boite centree sur un ecran de 375px laisse ses boutons au milieu de l'ecran,
    hors de portee du pouce ; collee en bas, elle les amene sous la main. A
    partir de `sm`, elle reprend sa place au centre.

    Le clavier est pris en charge par Alpine : `x-trap.noscroll` enferme le
    focus dans la modale et bloque le defilement de la page derriere, `Escape`
    ferme. Sans le piege, la tabulation sort de la modale et va parcourir un
    formulaire que l'utilisateur ne voit plus.
--}}
<template x-teleport="body">
    <div x-show="{{ $show }}"
         x-cloak
         @keydown.escape.window="{{ $show }} = false"
         class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4"
         role="dialog"
         aria-modal="true"
         aria-labelledby="{{ $id }}-title">

        <div x-show="{{ $show }}"
             x-transition:enter="scrim-in"
             @click="{{ $show }} = false"
             class="overlay-scrim"
             aria-hidden="true"></div>

        <div x-show="{{ $show }}"
             x-transition:enter="layer-in"
             x-trap.noscroll="{{ $show }}"
             @class([
                 'layer-panel relative flex max-h-[90dvh] w-full flex-col',
                 'rounded-b-none sm:rounded-b-[var(--radius-2xl)]',
                 $width,
             ])>

            <div class="flex items-start justify-between gap-4 px-5 pt-5 sm:px-6 sm:pt-6">
                <div class="min-w-0">
                    <h2 id="{{ $id }}-title" class="font-display text-lg font-bold text-ink">{{ $title }}</h2>
                    @if ($description)
                        <p class="mt-1 text-sm text-grey-500">{{ $description }}</p>
                    @endif
                </div>

                <button type="button"
                        @click="{{ $show }} = false"
                        class="btn btn-icon btn-icon-sm -mt-1 -mr-1 shrink-0">
                    <x-lucide-x class="size-5" stroke-width="2" aria-hidden="true" />
                    <span class="sr-only">Fermer</span>
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4 sm:px-6">
                {{ $slot }}
            </div>

            @isset($footer)
                {{-- Les actions sont empilees sur telephone et l'action
                     principale est EN HAUT de la pile : c'est celle qu'on
                     cherche, elle doit etre la plus proche du pouce quand la
                     modale est collee en bas. A partir de `sm`, l'ordre visuel
                     redevient conventionnel — secondaire a gauche. --}}
                <div class="flex flex-col-reverse gap-2 border-t border-grey-200 px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</template>
