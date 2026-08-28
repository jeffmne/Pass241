@php
    use App\Support\Money;

    $tz = config('app.display_timezone');
@endphp

{{--
    Controle d'acces d'un evenement (sections 29, 30).

    Points d'entree et agents sur le meme ecran : un agent s'affecte a une
    porte, et separer les deux obligerait a naviguer entre deux pages pour une
    seule decision.

    LES POINTS D'ENTREE SONT FACULTATIFS. Beaucoup de lieux n'ont qu'une porte :
    dans ce cas l'organisateur n'en declare aucun et affecte simplement ses
    agents a l'evenement. Plusieurs agents peuvent aussi tenir la meme entree —
    c'est le cas d'une porte a fort debit. Rien n'impose une correspondance
    d'un agent par entree, et l'ecran ne doit pas le laisser croire.
--}}
<div class="space-y-5">

    <x-ui.page-header
        title="Controle d'acces"
        :description="'Vos agents scannent les billets a l\'entree, meme sans reseau.'
            . ($scanTotal > 0
                ? ' ' . Money::number($scanTotal) . ' controle' . ($scanTotal > 1 ? 's' : '') . ' enregistre' . ($scanTotal > 1 ? 's' : '') . '.'
                : '')"
        :eyebrow="$event->name"
        :back="route('events.edit', $event)"
        :back-label="$event->name">

        {{-- Le journal ne s'ouvre que s'il a quelque chose a montrer :
             proposer un ecran vide avant le premier scan ferait perdre un
             clic a chaque visite. --}}
        @if ($scanTotal > 0)
            <x-slot:actions>
                <a href="{{ route('events.entries', $event) }}" wire:navigate class="btn btn-secondary">
                    <x-lucide-door-open class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                    Voir les entrees
                </a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    {{-- Identifiants du nouvel agent : affiches UNE SEULE FOIS.
         Le mot de passe n'est jamais stocke en clair ; l'organisateur le
         transmet a son agent, souvent de vive voix sur place. --}}
    @if ($newAgentCredentials)
        <div role="status" class="rounded-[var(--radius-lg)] border border-success/30 bg-success/5 p-4">
            <div class="flex items-start gap-3">
                <x-lucide-key-round class="mt-0.5 size-5 shrink-0 text-success" stroke-width="2" aria-hidden="true" />

                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-ink">Compte agent cree</p>
                    <p class="mt-1 text-sm text-grey-500">
                        Transmettez ces identifiants a votre agent. Ce mot de passe
                        ne sera plus affiche.
                    </p>
                    <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                        <div class="rounded-[var(--radius-sm)] bg-surface px-3 py-2">
                            <dt class="text-[10px] font-bold tracking-wider text-grey-500 uppercase">Identifiant</dt>
                            <dd class="mt-0.5 font-medium break-all text-ink">{{ $newAgentCredentials['email'] }}</dd>
                        </div>
                        <div class="rounded-[var(--radius-sm)] bg-surface px-3 py-2">
                            <dt class="text-[10px] font-bold tracking-wider text-grey-500 uppercase">Mot de passe</dt>
                            <dd class="mt-0.5 font-display font-bold text-ink tabular">{{ $newAgentCredentials['password'] }}</dd>
                        </div>
                    </dl>
                </div>

                <button type="button" wire:click="dismissCredentials"
                        class="btn btn-icon btn-icon-sm -mt-1 -mr-1 shrink-0">
                    <x-lucide-x class="size-4" stroke-width="2" aria-hidden="true" />
                    <span class="sr-only">Masquer les identifiants</span>
                </button>
            </div>
        </div>
    @endif

    {{-- ----------------------------------------------------------------
         Points d'entree
    ----------------------------------------------------------------- --}}
    <x-ui.panel
        title="Points d'entree"
        description="Facultatif. Declarez-en si votre lieu a plusieurs portes, pour savoir par ou chacun est entre.">

        @unless ($showEntryForm)
            <x-slot:actions>
                <button type="button" wire:click="$set('showEntryForm', true)" class="btn btn-secondary">
                    <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                    Ajouter une entree
                </button>
            </x-slot:actions>
        @endunless

        @if ($showEntryForm)
            <form wire:submit="saveEntry" class="glass-inset mb-4 p-4">
                <div class="grid gap-x-4 sm:grid-cols-2">
                    <x-ui.field label="Nom" for="entryName" required :error="$errors->first('entryName')">
                        <input id="entryName" type="text" wire:model="entryName" class="field"
                               placeholder="Entree principale"
                               @error('entryName') aria-invalid="true" aria-describedby="entryName-error" @enderror>
                    </x-ui.field>

                    <x-ui.field label="Description" for="entryDescription" hint="Facultatif."
                                :error="$errors->first('entryDescription')">
                        <input id="entryDescription" type="text" wire:model="entryDescription" class="field"
                               placeholder="Cote boulevard"
                               @error('entryDescription') aria-invalid="true" aria-describedby="entryDescription-error" @enderror>
                    </x-ui.field>
                </div>

                <div class="mt-3 flex flex-col-reverse gap-2 sm:flex-row-reverse sm:justify-start">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveEntry">
                        Enregistrer
                    </button>
                    <button type="button" wire:click="resetEntryForm" class="btn btn-secondary">Annuler</button>
                </div>
            </form>
        @endif

        <div class="space-y-2">
            @forelse ($entryPoints as $entry)
                <div wire:key="entry-{{ $entry->id }}"
                     class="flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-sm)] border border-grey-200 bg-surface p-3.5">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold text-ink">{{ $entry->name }}</p>
                            @unless ($entry->is_active)
                                <x-ui.badge tone="neutral">Fermee</x-ui.badge>
                            @endunless
                        </div>
                        <p class="mt-0.5 text-sm text-grey-500">
                            {{ $entry->description ?: 'Sans description' }}
                            ·
                            {{-- Plusieurs agents peuvent tenir la meme porte :
                                 c'est le cas d'une entree a fort debit. --}}
                            {{ $entry->agents_count }} agent{{ $entry->agents_count > 1 ? 's' : '' }}
                        </p>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <button type="button" wire:click="editEntry({{ $entry->id }})" class="btn btn-secondary btn-sm">
                            Modifier
                        </button>
                        <button type="button" wire:click="toggleEntry({{ $entry->id }})" class="btn btn-secondary btn-sm">
                            {{ $entry->is_active ? 'Fermer' : 'Rouvrir' }}
                        </button>
                    </div>
                </div>
            @empty
                <p class="rounded-[var(--radius-sm)] bg-surface-muted px-4 py-3 text-sm text-grey-500">
                    Aucun point d'entree declare. Vos agents controleront l'evenement
                    sans distinction de porte — ce qui convient a la plupart des lieux.
                </p>
            @endforelse
        </div>
    </x-ui.panel>

    {{-- ----------------------------------------------------------------
         Agents
    ----------------------------------------------------------------- --}}
    <x-ui.panel
        title="Agents"
        description="Ils se connectent a l'application mobile avec les identifiants que vous leur transmettez.">

        @unless ($showAgentForm)
            <x-slot:actions>
                <button type="button" wire:click="$set('showAgentForm', true)" class="btn btn-primary">
                    <x-lucide-user-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                    Ajouter un agent
                </button>
            </x-slot:actions>
        @endunless

        @if ($showAgentForm)
            <form wire:submit="saveAgent" class="glass-inset mb-4 p-4">
                <div class="grid gap-x-4 sm:grid-cols-3">
                    <x-ui.field label="Nom" for="agentName" required :error="$errors->first('agentName')">
                        <input id="agentName" type="text" wire:model="agentName" class="field"
                               @error('agentName') aria-invalid="true" aria-describedby="agentName-error" @enderror>
                    </x-ui.field>

                    <x-ui.field label="Identifiant" for="agentEmail" required :error="$errors->first('agentEmail')">
                        <input id="agentEmail" type="email" inputmode="email" autocapitalize="none"
                               wire:model="agentEmail" class="field"
                               @error('agentEmail') aria-invalid="true" aria-describedby="agentEmail-error" @enderror>
                    </x-ui.field>

                    <x-ui.field label="Entree" for="agentEntryPointId" hint="Facultatif."
                                :error="$errors->first('agentEntryPointId')">
                        <select id="agentEntryPointId" wire:model="agentEntryPointId" class="field"
                                @error('agentEntryPointId') aria-invalid="true" aria-describedby="agentEntryPointId-error" @enderror>
                            <option value="">Toutes les entrees</option>
                            @foreach ($entryPoints as $entry)
                                <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                </div>

                <p class="text-xs leading-relaxed text-grey-400">
                    Si cette adresse a deja un compte Pass241, il sera reutilise —
                    un agent qui travaille pour plusieurs organisateurs n'a qu'un
                    seul compte.
                </p>

                <div class="mt-3 flex flex-col-reverse gap-2 sm:flex-row-reverse sm:justify-start">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveAgent">
                        Affecter
                    </button>
                    <button type="button" wire:click="$set('showAgentForm', false)" class="btn btn-secondary">
                        Annuler
                    </button>
                </div>
            </form>
        @endif

        <div class="space-y-2">
            @forelse ($agents as $assignment)
                @php ($stat = $activity[$assignment->user_id] ?? null)
                <div wire:key="agent-{{ $assignment->id }}"
                     @class([
                        'flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-sm)] border border-grey-200 p-3.5',
                        'bg-surface' => $assignment->is_active,
                        // Un agent retire reste visible mais s'efface : ses
                        // controles, eux, ne disparaissent jamais.
                        'bg-surface-muted opacity-70' => ! $assignment->is_active,
                     ])>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p @class([
                                'font-semibold',
                                'text-ink' => $assignment->is_active,
                                'text-grey-400' => ! $assignment->is_active,
                            ])>
                                {{ $assignment->agent?->name }}
                            </p>
                            @unless ($assignment->is_active)
                                <x-ui.badge tone="neutral">Retire</x-ui.badge>
                            @endunless
                        </div>

                        <p class="mt-0.5 text-sm text-grey-500">
                            {{ $assignment->agent?->email }}
                            · {{ $assignment->entryPoint?->name ?? 'Toutes les entrees' }}
                        </p>

                        @if ($stat)
                            {{-- « Consulter l'activite de l'agent » (section 27.2).
                                 Les refus comptent autant que les entrees : un agent
                                 qui refuse beaucoup signale un probleme a sa porte. --}}
                            <p class="mt-1 text-xs text-grey-400 tabular">
                                {{ $stat->total }} controle{{ $stat->total > 1 ? 's' : '' }},
                                {{ $stat->accepted }} entree{{ $stat->accepted > 1 ? 's' : '' }}
                                @if ($stat->last_scan)
                                    — dernier a
                                    {{ \Illuminate\Support\Carbon::parse($stat->last_scan)->timezone($tz)->format('H\hi') }}
                                @endif
                            </p>
                        @endif
                    </div>

                    @if ($assignment->is_active)
                        <button type="button" wire:click="revokeAgent({{ $assignment->id }})"
                                wire:confirm="Retirer cet agent ? Son acces est coupe immediatement."
                                class="btn btn-danger btn-sm shrink-0">
                            Retirer
                        </button>
                    @endif
                </div>
            @empty
                <x-ui.empty-state
                    icon="user-plus"
                    title="Aucun agent affecte"
                    description="Sans agent, personne ne pourra scanner les billets a l'entree.">
                    <x-slot:action>
                        <button type="button" wire:click="$set('showAgentForm', true)" class="btn btn-primary">
                            Ajouter un agent
                        </button>
                    </x-slot:action>
                </x-ui.empty-state>
            @endforelse
        </div>

        <x-slot:footer>
            Retirer un agent lui coupe l'acces immediatement, sans effacer les
            controles qu'il a deja effectues.
        </x-slot:footer>
    </x-ui.panel>
</div>
