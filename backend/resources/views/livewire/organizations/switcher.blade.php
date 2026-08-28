{{--
    Bloc de profil et changement d'organisation.

    Il vit en pied de colonne laterale sur grand ecran et dans l'en-tete sur
    telephone. Un seul composant pour les deux : la liste des organisations et
    la deconnexion ne doivent jamais differer d'un contexte a l'autre.

    En version complete il reprend la composition de la maquette de reference —
    initiales dans un disque bleu, nom de l'organisation, role en dessous, et la
    sortie a droite. Le role n'est pas decoratif : quelqu'un qui gere plusieurs
    organisations n'a pas les memes droits partout, et le savoir avant de
    cliquer evite une erreur.
--}}
<div x-data="{ open: false }" class="relative">
    <button type="button"
            @click="open = !open"
            @keydown.escape.window="open = false"
            :aria-expanded="open"
            aria-haspopup="menu"
            @class([
                'flex w-full items-center gap-2.5 rounded-[var(--radius-sm)] border text-left transition',
                'min-h-11 max-w-[44vw] border-transparent px-2 py-1.5 hover:bg-[var(--rail-hover)]' => $compact,
                'min-h-14 border-[var(--rail-border)] bg-[var(--rail-active)] px-2.5 py-2 hover:bg-[var(--rail-hover)]' => ! $compact,
            ])>
        <span @class([
            'flex shrink-0 items-center justify-center rounded-full bg-azure-600 font-bold text-white',
            'size-8 text-xs' => $compact,
            'size-10 text-sm' => ! $compact,
        ])>
            {{ $user->initials() }}
        </span>

        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-bold text-ink">{{ $current?->name }}</span>
            @unless ($compact)
                <span class="block truncate text-xs text-grey-500">{{ $user->name }}</span>
            @endunless
        </span>

        <x-lucide-chevrons-up-down class="size-4 shrink-0 text-grey-400" stroke-width="1.75" aria-hidden="true" />
        <span class="sr-only">Changer d'organisation ou se deconnecter</span>
    </button>

    {{--
        Le menu s'ouvre VERS LE HAUT sur grand ecran — il est en pied de
        colonne, il n'a pas la place de descendre — et vers le bas sur
        telephone, ou il pend sous l'en-tete. D'ou les deux jeux de classes.
    --}}
    <div x-show="open"
         x-cloak
         x-transition:enter="layer-in"
         @click.outside="open = false"
         @keydown.escape.window="open = false"
         role="menu"
         class="layer-menu absolute right-0 bottom-full z-40 mb-2 w-64 overflow-hidden max-lg:top-full max-lg:bottom-auto max-lg:mt-2 max-lg:mb-0">

        @if ($organizations->count() > 1)
            <div class="border-b border-grey-200 p-1.5">
                <p class="px-2.5 py-1.5 text-[10px] font-bold tracking-wider text-grey-400 uppercase">
                    Organisations
                </p>
                @foreach ($organizations as $organization)
                    <button type="button"
                            wire:click="switch({{ $organization->id }})"
                            role="menuitem"
                            @class([
                                'flex min-h-11 w-full items-center justify-between gap-2 rounded-[var(--radius-xs)] px-2.5 py-2 text-left text-sm transition hover:bg-surface-muted',
                                'font-semibold text-azure-700' => $current?->is($organization),
                                'text-grey-500' => ! $current?->is($organization),
                            ])>
                        <span class="truncate">{{ $organization->name }}</span>
                        @if ($current?->is($organization))
                            <x-lucide-check class="size-4 shrink-0 text-azure-600" stroke-width="2.25" aria-hidden="true" />
                        @endif
                    </button>
                @endforeach
            </div>
        @endif

        <div class="p-1.5">
            <a href="{{ route('organization.settings') }}" wire:navigate role="menuitem"
               class="flex min-h-11 items-center gap-2.5 rounded-[var(--radius-xs)] px-2.5 py-2 text-sm text-grey-500 transition hover:bg-surface-muted hover:text-azure-700">
                <x-lucide-settings class="size-4 shrink-0" stroke-width="1.75" aria-hidden="true" />
                Parametres
            </a>
            <a href="{{ route('organizations.create') }}" wire:navigate role="menuitem"
               class="flex min-h-11 items-center gap-2.5 rounded-[var(--radius-xs)] px-2.5 py-2 text-sm text-grey-500 transition hover:bg-surface-muted hover:text-azure-700">
                <x-lucide-plus class="size-4 shrink-0" stroke-width="1.75" aria-hidden="true" />
                Nouvelle organisation
            </a>

            @if ($user->isSuperAdmin())
                {{-- La console n'est visible que de qui y a acces. Elle est
                     annoncee ici plutot que dans la navigation principale :
                     c'est un changement d'espace, pas une destination de plus
                     dans celui-ci. --}}
                <a href="{{ route('admin.overview') }}" wire:navigate role="menuitem"
                   class="flex min-h-11 items-center gap-2.5 rounded-[var(--radius-xs)] px-2.5 py-2 text-sm text-grey-500 transition hover:bg-surface-muted hover:text-azure-700">
                    <x-lucide-shield-check class="size-4 shrink-0" stroke-width="1.75" aria-hidden="true" />
                    Administration
                </a>
            @endif

            <form method="POST" action="{{ route('logout') }}" class="border-t border-grey-200 pt-1.5">
                @csrf
                <button type="submit" role="menuitem"
                        class="flex min-h-11 w-full items-center gap-2.5 rounded-[var(--radius-xs)] px-2.5 py-2 text-sm text-grey-500 transition hover:bg-surface-muted hover:text-danger">
                    <x-lucide-log-out class="size-4 shrink-0" stroke-width="1.75" aria-hidden="true" />
                    Se deconnecter
                </button>
            </form>
        </div>
    </div>
</div>
