@props([
    'href',
    'icon',
    'title',
    'description' => null,
    // Vrai quand quelque chose manque a cet endroit : la carte prend alors la
    // teinte d'avertissement. Un evenement sans billet ne peut rien vendre —
    // le dire au moment ou l'organisateur regarde son evenement vaut mieux
    // qu'un refus au moment de publier.
    'attention' => false,
])

{{--
    Carte de navigation vers un sous-ecran.

    L'ecran d'un evenement en aligne trois — billets, controle d'acces,
    invitations — et chacune etait ecrite a la main, avec ses propres classes.
    Elles avaient deja commence a diverger d'un pixel.

    Toute la carte est cliquable, pas seulement le titre : sur telephone, une
    zone de 60px de haut se vise sans reflechir, un lien de la largeur d'un mot
    se manque.
--}}
<a href="{{ $href }}" wire:navigate
   {{ $attributes->class([
       'surface group flex items-center justify-between gap-4 p-4 transition-colors sm:p-5',
       'hover:border-azure-300' => ! $attention,
       'border-warning/40 bg-warning/5 hover:border-warning' => $attention,
   ]) }}>
    <span class="flex min-w-0 items-center gap-3.5">
        <span @class([
            'flex size-11 shrink-0 items-center justify-center rounded-[var(--radius-sm)]',
            'bg-azure-100 text-azure-700' => ! $attention,
            'bg-warning/15 text-warning' => $attention,
        ])>
            <x-dynamic-component :component="'lucide-' . $icon" class="size-5" stroke-width="1.75" aria-hidden="true" />
        </span>

        <span class="min-w-0">
            <span class="block font-display text-base font-bold text-ink">{{ $title }}</span>
            @if ($description)
                <span class="mt-0.5 block text-sm text-grey-500">{{ $description }}</span>
            @endif
        </span>
    </span>

    <x-lucide-chevron-right
        class="size-5 shrink-0 text-grey-400 transition-transform group-hover:translate-x-0.5"
        stroke-width="2" aria-hidden="true" />
</a>
