@props([
    'title' => null,
    'description' => null,
    // `solid` : surface pleine. C'est le DEFAUT, et ce n'est pas un hasard —
    // un panneau porte presque toujours des donnees qu'on relit ligne a ligne,
    // et le flou leur retire le contraste dont cette lecture depend
    // (section 70.4).
    //
    // `glass` : reserve aux panneaux de synthese, ou l'on regarde une forme
    // (une courbe, quatre chiffres) plutot qu'on ne dechiffre un texte.
    'variant' => 'solid',
    // Retire les marges internes du corps : pour un tableau, qui porte les
    // siennes dans ses cellules.
    'flush' => false,
])

{{--
    Panneau de section. L'enveloppe unique de tout bloc de contenu titre
    (regle 34) : liste, tableau, graphique, groupe de champs.

    L'en-tete n'apparait que s'il y a un titre : un panneau sans titre reste un
    simple cadre, sans espace perdu en haut.
--}}
<section {{ $attributes->class([
    'overflow-hidden',
    'surface' => $variant === 'solid',
    'glass-card' => $variant === 'glass',
]) }}>
    @if ($title || isset($actions))
        <div @class([
            'flex flex-col gap-3 px-4 pt-4 sm:flex-row sm:items-center sm:justify-between sm:px-5 sm:pt-5',
            'pb-4 sm:pb-5' => $flush,
        ])>
            <div class="min-w-0">
                @if ($title)
                    <h2 class="font-display text-base font-bold text-ink sm:text-lg">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-sm text-grey-500">{{ $description }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif

    <div @class([
        'px-4 pb-4 sm:px-5 sm:pb-5' => ! $flush,
        'pt-4 sm:pt-5' => ! $flush && ! $title && ! isset($actions),
        'mt-4 sm:mt-5' => ! $flush && ($title || isset($actions)),
    ])>
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-grey-200 bg-surface-muted px-4 py-3 text-xs leading-relaxed text-grey-500 sm:px-5">
            {{ $footer }}
        </div>
    @endisset
</section>
