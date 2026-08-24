@props(['status'])

{{-- Badge de statut. Les couleurs semantiques ne sont jamais remplacees par de
     l'azur, meme sur un ecran tres bleute : un statut doit se lire d'un coup
     d'oeil (section 70.1). --}}
<span @class([
    'inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold',
    'bg-success/10 text-success' => $status->tone() === 'success',
    'bg-danger/10 text-danger' => $status->tone() === 'danger',
    'bg-grey-200/70 text-grey-500' => $status->tone() === 'neutral',
])>
    <span @class([
        'size-1.5 rounded-full',
        'bg-success' => $status->tone() === 'success',
        'bg-danger' => $status->tone() === 'danger',
        'bg-grey-400' => $status->tone() === 'neutral',
    ])></span>
    {{ $status->label() }}
</span>
