@props([
    'label',
    'value',
    'icon' => null,
    // Ligne de contexte sous le chiffre : « sur 1 200 mis en vente ».
    'hint' => null,
    // Teinte de l'icone. La VALEUR reste toujours a l'encre du texte : teinter
    // un montant en vert le fait lire comme un gain, alors que c'est une
    // recette. Seule l'icone porte la couleur.
    'tone' => 'primary',
    // Variation chiffree, avec son sens : ['value' => '+12 %', 'direction' => 'up']
    'trend' => null,
    // Ecran a venir ou indicateur sans donnee : la carte s'efface au lieu
    // d'annoncer un zero qui se lirait comme un echec.
    'muted' => false,
])

@php
    $iconTone = match ($tone) {
        'success' => 'text-success',
        'warning' => 'text-warning',
        'danger' => 'text-danger',
        'neutral' => 'text-grey-400',
        default => 'text-azure-500',
    };

    // La direction porte sa propre icone en plus de sa couleur : la couleur
    // seule ne distingue pas une hausse d'une baisse pour un daltonien.
    $trendDirection = $trend['direction'] ?? null;
@endphp

{{--
    Carte de chiffre. Le composant unique de tous les indicateurs du produit
    (regle 34) : dashboard organisateur, ecran d'un evenement, console Super
    Admin.

    Hierarchie volontairement franche — libelle discret en petites capitales,
    VALEUR en 800, contexte en gris. C'est le chiffre qu'on vient lire ; tout
    le reste doit s'effacer devant lui.
--}}
<div {{ $attributes->class(['glass-stat glass-specular p-5', 'opacity-70' => $muted]) }}>
    <div class="flex items-center gap-2">
        @if ($icon)
            <x-dynamic-component :component="'lucide-' . $icon"
                                 @class(['size-4 shrink-0', $iconTone])
                                 stroke-width="2" />
        @endif
        <span class="truncate text-xs font-bold tracking-wider text-grey-500 uppercase">
            {{ $label }}
        </span>
    </div>

    <p @class([
        'mt-2.5 font-display text-3xl leading-none font-extrabold tabular',
        'text-ink' => ! $muted,
        'text-grey-400' => $muted,
    ])>
        {{ $value }}
    </p>

    @if ($trend)
        <p @class([
            'mt-2 flex items-center gap-1 text-sm font-semibold tabular',
            'text-success' => $trendDirection === 'up',
            'text-danger' => $trendDirection === 'down',
            'text-grey-500' => ! in_array($trendDirection, ['up', 'down'], true),
        ])>
            @if ($trendDirection === 'up')
                <x-lucide-trending-up class="size-4 shrink-0" stroke-width="2" />
            @elseif ($trendDirection === 'down')
                <x-lucide-trending-down class="size-4 shrink-0" stroke-width="2" />
            @endif
            {{ $trend['value'] }}
        </p>
    @endif

    @if ($hint)
        <p class="mt-1.5 text-sm text-grey-500 tabular">{{ $hint }}</p>
    @endif
</div>
