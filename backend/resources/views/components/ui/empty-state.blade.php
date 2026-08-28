@props([
    'icon' => 'inbox',
    'title',
    'description' => null,
    // `neutral` : il n'y a rien encore, c'est normal.
    // `filtered` : il y a des donnees, mais le filtre courant n'en laisse
    //              passer aucune. Le geste utile n'est alors pas « creer »,
    //              c'est « effacer le filtre » — deux situations differentes
    //              qui appellent deux ecrans differents.
    'tone' => 'neutral',
])

{{--
    Etat vide (regle 16 : jamais de page blanche).

    Trois choses : ce qui manque, pourquoi, et le geste qui le remplit. Sans le
    troisieme, l'ecran constate sans aider.

    L'icone est decorative — le titre juste dessous dit deja tout. Elle est donc
    masquee aux lecteurs d'ecran, qui annonceraient sinon un nom d'icone sans
    rapport avec la phrase.
--}}
<div {{ $attributes->class('empty-state') }}>
    <div @class([
        'empty-state-icon',
        'bg-surface-muted text-grey-400' => $tone === 'filtered',
    ])>
        <x-dynamic-component :component="'lucide-' . $icon" class="size-6" stroke-width="1.5" aria-hidden="true" />
    </div>

    <h3 class="font-display text-base font-bold text-ink">{{ $title }}</h3>

    @if ($description)
        <p class="max-w-sm text-sm leading-relaxed text-grey-500">{{ $description }}</p>
    @endif

    @isset($action)
        <div class="mt-3 flex flex-wrap items-center justify-center gap-2">
            {{ $action }}
        </div>
    @endisset
</div>
