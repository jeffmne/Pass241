@props([
    'icon',
    'label',
    'href' => null,
    'tone' => 'primary',
])

@php
    $iconTone = match ($tone) {
        'success' => 'text-success',
        'warning' => 'text-warning',
        'danger' => 'text-danger',
        'neutral' => 'text-grey-500',
        default => 'text-azure-500',
    };

    // Un lien quand cela mene ailleurs, un bouton quand cela declenche quelque
    // chose. La distinction n'est pas cosmetique : elle decide si le clic-droit
    // propose « ouvrir dans un nouvel onglet », et ce que le lecteur d'ecran
    // annonce.
    $tag = $href ? 'a' : 'button';
@endphp

{{--
    Action rapide. La rangee de raccourcis du tableau de bord.

    Chaque tuile fait au minimum 44px de haut — largement depasse ici — et la
    grille passe a deux colonnes sur telephone plutot qu'a une seule : quatre
    tuiles empilees sur toute la largeur repousseraient le contenu utile sous
    la ligne de flottaison.
--}}
<{{ $tag }}
    @if ($href) href="{{ $href }}" wire:navigate @else type="button" @endif
    {{ $attributes->class('btn glass-card card-lift flex-col gap-2.5 px-3 py-5 text-center') }}>
    <x-dynamic-component :component="'lucide-' . $icon"
                         @class(['size-6 shrink-0', $iconTone])
                         stroke-width="1.75" aria-hidden="true" />
    <span class="text-sm leading-tight font-semibold text-ink">{{ $label }}</span>
</{{ $tag }}>
