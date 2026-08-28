@php
    use App\Support\Money;

    $tz = config('app.display_timezone');
@endphp

{{--
    Journal d'audit de la plateforme (sections 58, 67).

    L'ecran qui donne sa valeur aux autres : tout le reste montre un etat,
    celui-ci montre COMMENT on y est arrive. C'est ici qu'on arbitre un litige
    entre un client et un organisateur des mois plus tard.

    Le journal est en ajout seul, garanti par declencheur. Cet ecran ne fait
    que le lire, et n'exposera jamais de suppression : un journal qu'on peut
    nettoyer ne prouve rien.
--}}
<div class="space-y-5">

    <x-ui.page-header
        title="Journal"
        :description="Money::number($logs->total()) . ' entree' . ($logs->total() > 1 ? 's' : '') . '. En ajout seul, jamais modifiable.'"
        eyebrow="Administration Pass241">

        <x-slot:actions>
            <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                <div class="sm:w-56">
                    <label for="audit-action" class="sr-only">Filtrer par action</label>
                    <select id="audit-action" wire:model.live="action" class="field">
                        <option value="">Toutes les actions</option>
                        @foreach ($actions as $available)
                            <option value="{{ $available->value }}">{{ $available->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <x-ui.search-input
                    id="audit-search"
                    class="sm:w-64"
                    label="Rechercher dans le journal"
                    placeholder="Organisation, email"
                    wire:model.live.debounce.400ms="search" />
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    <div wire:loading.delay wire:target="search, action, gotoPage, previousPage, nextPage">
        <x-ui.skeleton variant="list" :rows="6" class="surface p-4" />
    </div>

    <div wire:loading.delay.class="opacity-40"
         wire:target="search, action, gotoPage, previousPage, nextPage"
         class="space-y-2 transition-opacity">

        @forelse ($logs as $index => $log)
            <div wire:key="log-{{ $log->id }}"
                 @class([
                    'glass-card p-4',
                    // Les actions financieres portent un liseré azur : ce sont
                    // celles qu'on cherche quand on remonte un litige.
                    'border-l-[3px] border-l-azure-500' => $log->action->isFinancial(),
                 ])
                 data-reveal data-reveal-step="{{ $index % 5 }}">
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <p class="font-semibold text-ink">{{ $log->action->label() }}</p>
                    <p class="text-xs text-grey-400 tabular">
                        {{ $log->created_at->timezone($tz)->translatedFormat('j F Y \a\ H\hi\m\i\n\s') }}
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
                    <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 border-t border-[var(--stat-border)] pt-2 text-xs">
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
            <div class="surface">
                <x-ui.empty-state
                    icon="search-x"
                    tone="filtered"
                    title="Aucune entree"
                    description="Aucune entree ne correspond a ce filtre." />
            </div>
        @endforelse
    </div>

    @if ($logs->hasPages())
        <div>{{ $logs->links() }}</div>
    @endif
</div>
