<div x-data="{ open: false }" class="relative">
    <button type="button"
            @click="open = !open"
            @keydown.escape.window="open = false"
            :aria-expanded="open"
            aria-haspopup="menu"
            @class([
                'flex min-h-11 w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-left transition hover:bg-azure-50',
                'max-w-[60vw]' => $compact,
            ])>
        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-azure-100 text-xs font-bold text-azure-700">
            {{ $user->initials() }}
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-semibold text-ink">{{ $current?->name }}</span>
            @unless ($compact)
                <span class="block truncate text-xs text-grey-500">{{ $user->name }}</span>
            @endunless
        </span>
        <x-lucide-chevrons-up-down class="size-4 shrink-0 text-grey-400" stroke-width="1.5" />
    </button>

    <div x-show="open"
         x-cloak
         @click.outside="open = false"
         role="menu"
         class="absolute right-0 bottom-full z-30 mb-2 w-64 overflow-hidden rounded-2xl border border-grey-200 bg-white shadow-lg lg:bottom-full max-lg:top-full max-lg:bottom-auto max-lg:mt-2 max-lg:mb-0">

        @if ($organizations->count() > 1)
            <div class="border-b border-grey-200 p-1.5">
                <p class="px-2.5 py-1.5 text-xs font-semibold tracking-wide text-grey-400 uppercase">
                    Organisations
                </p>
                @foreach ($organizations as $organization)
                    <button type="button"
                            wire:click="switch({{ $organization->id }})"
                            role="menuitem"
                            @class([
                                'flex min-h-11 w-full items-center justify-between gap-2 rounded-lg px-2.5 py-2 text-left text-sm transition hover:bg-azure-50',
                                'font-semibold text-azure-700' => $current?->is($organization),
                                'text-grey-500' => ! $current?->is($organization),
                            ])>
                        <span class="truncate">{{ $organization->name }}</span>
                        @if ($current?->is($organization))
                            <x-lucide-check class="size-4 shrink-0 text-azure-500" stroke-width="2" />
                        @endif
                    </button>
                @endforeach
            </div>
        @endif

        <div class="p-1.5">
            <a href="{{ route('organization.settings') }}" wire:navigate role="menuitem"
               class="flex min-h-11 items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-grey-500 transition hover:bg-azure-50">
                <x-lucide-settings class="size-4" stroke-width="1.5" />
                Parametres
            </a>
            <a href="{{ route('organizations.create') }}" wire:navigate role="menuitem"
               class="flex min-h-11 items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-grey-500 transition hover:bg-azure-50">
                <x-lucide-plus class="size-4" stroke-width="1.5" />
                Nouvelle organisation
            </a>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" role="menuitem"
                        class="flex min-h-11 w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-grey-500 transition hover:bg-azure-50">
                    <x-lucide-log-out class="size-4" stroke-width="1.5" />
                    Se deconnecter
                </button>
            </form>
        </div>
    </div>
</div>
