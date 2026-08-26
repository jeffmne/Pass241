{{--
    Journal d'audit de la plateforme (sections 58, 67).

    L'ecran qui donne sa valeur aux autres : tout le reste montre un etat,
    celui-ci montre COMMENT on y est arrive. C'est ici qu'on arbitre un litige
    entre un client et un organisateur des mois plus tard.

    Le journal est en ajout seul, garanti par declencheur. Cet ecran ne fait
    que le lire, et n'exposera jamais de suppression : un journal qu'on peut
    nettoyer ne prouve rien.
--}}
<div>
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-extrabold text-ink sm:text-3xl">Journal</h1>
            <p class="mt-1 text-sm text-grey-500">
                {{ number_format($logs->total(), 0, ',', "\u{202F}") }} entree{{ $logs->total() > 1 ? 's' : '' }}.
                En ajout seul, jamais modifiable.
            </p>
        </div>

        <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
            <select wire:model.live="action" class="field sm:w-56" aria-label="Filtrer par action">
                <option value="">Toutes les actions</option>
                @foreach ($actions as $available)
                    <option value="{{ $available->value }}">{{ $available->label() }}</option>
                @endforeach
            </select>

            <div class="relative sm:w-64">
                <x-lucide-search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-grey-400"
                                 stroke-width="1.75" />
                <input type="search" wire:model.live.debounce.400ms="search" class="field pl-10"
                       placeholder="Organisation, email" aria-label="Rechercher dans le journal">
            </div>
        </div>
    </header>

    <div class="space-y-2">
        @forelse ($logs as $index => $log)
            <div @class([
                    'glass-stat p-4',
                    // Les actions financieres portent un liseré azur : ce sont
                    // celles qu'on cherche quand on remonte un litige.
                    'border-l-[3px] border-l-azure-500' => $log->action->isFinancial(),
                 ])
                 data-reveal data-reveal-step="{{ $index % 5 }}">
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <p class="font-medium text-ink">{{ $log->action->label() }}</p>
                    <p class="text-xs text-grey-400 tabular">
                        {{ $log->created_at->timezone(config('app.display_timezone'))->translatedFormat('j F Y \a\ H\hi\m\i\n\s') }}
                    </p>
                </div>

                <p class="mt-1 text-sm text-grey-500">
                    @if ($log->organization)
                        {{ $log->organization->name }}
                    @else
                        <span class="text-grey-400">Hors organisation</span>
                    @endif

                    @if ($log->user)
                        · {{ $log->user->email }}
                    @else
                        {{-- Aucun acteur : une action du systeme, ou une action
                             d'un acheteur qui n'a pas de compte. La distinction
                             compte pour arbitrer. --}}
                        · <span class="text-grey-400">systeme</span>
                    @endif
                </p>

                @if (! empty($log->metadata))
                    {{-- Metadonnees affichees telles qu'ecrites. Les numeros y
                         sont deja masques a l'ecriture (decision D-025) :
                         filtrer ici donnerait la fausse impression que la
                         donnee brute est propre. --}}
                    <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 border-t border-white/60 pt-2 text-xs">
                        @foreach ($log->metadata as $key => $value)
                            {{-- Une cle sans valeur n'apprend rien et allonge
                                 une ligne qu'on parcourt vite. --}}
                            @continue(is_array($value) || is_object($value) || $value === null || $value === '')
                            <div class="flex gap-1.5">
                                <dt class="text-grey-400">{{ $key }}</dt>
                                <dd class="text-grey-500 tabular">
                                    {{ is_bool($value) ? ($value ? 'oui' : 'non') : $value }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </div>
        @empty
            <div class="surface p-10 text-center text-sm text-grey-500">
                Aucune entree ne correspond.
            </div>
        @endforelse
    </div>

    @if ($logs->hasPages())
        <div class="mt-6">{{ $logs->links() }}</div>
    @endif
</div>
