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
<div>
    <header class="mb-6">
        <a href="{{ route('events.edit', $event) }}" wire:navigate
           class="inline-flex items-center gap-1.5 text-sm font-medium text-grey-500 transition-colors hover:text-azure-700">
            <x-lucide-arrow-left class="size-4" stroke-width="1.75" />
            {{ $event->name }}
        </a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="font-display text-2xl font-extrabold text-ink sm:text-3xl">Controle d'acces</h1>
                <p class="mt-1 text-sm text-grey-500">
                    Vos agents scannent les billets a l'entree, meme sans reseau.
                    @if ($scanTotal > 0)
                        {{ number_format($scanTotal, 0, ',', "\u{202F}") }} controle{{ $scanTotal > 1 ? 's' : '' }} enregistre{{ $scanTotal > 1 ? 's' : '' }}.
                    @endif
                </p>
            </div>

            {{-- Le journal ne s'ouvre que s'il a quelque chose a montrer :
                 proposer un ecran vide avant le premier scan ferait perdre un
                 clic a chaque visite. --}}
            @if ($scanTotal > 0)
                <a href="{{ route('events.entries', $event) }}" wire:navigate class="btn btn-ghost btn-pill">
                    <x-lucide-door-open class="size-4" stroke-width="1.75" />
                    Voir les entrees
                </a>
            @endif
        </div>
    </header>

    {{-- Identifiants du nouvel agent : affiches UNE SEULE FOIS.
         Le mot de passe n'est jamais stocke en clair ; l'organisateur le
         transmet a son agent, souvent de vive voix sur place. --}}
    @if ($newAgentCredentials)
        <div class="mb-6 rounded-2xl border border-success/30 bg-success/5 p-4">
            <div class="flex items-start gap-3">
                <x-lucide-key-round class="mt-0.5 size-5 shrink-0 text-success" stroke-width="1.75" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-ink">Compte agent cree</p>
                    <p class="mt-1 text-sm text-grey-500">
                        Transmettez ces identifiants a votre agent. Ce mot de passe
                        ne sera plus affiche.
                    </p>
                    <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                        <div class="rounded-xl bg-white px-3 py-2">
                            <dt class="text-[10px] font-semibold tracking-[0.14em] text-grey-500 uppercase">Identifiant</dt>
                            <dd class="mt-0.5 font-medium text-ink">{{ $newAgentCredentials['email'] }}</dd>
                        </div>
                        <div class="rounded-xl bg-white px-3 py-2">
                            <dt class="text-[10px] font-semibold tracking-[0.14em] text-grey-500 uppercase">Mot de passe</dt>
                            <dd class="mt-0.5 font-display font-bold text-ink tabular">{{ $newAgentCredentials['password'] }}</dd>
                        </div>
                    </dl>
                </div>
                <button type="button" wire:click="dismissCredentials"
                        class="shrink-0 text-grey-400 transition-colors hover:text-ink"
                        aria-label="Masquer les identifiants">
                    <x-lucide-x class="size-4" stroke-width="2" />
                </button>
            </div>
        </div>
    @endif

    {{-- ----------------------------------------------------------------
         Points d'entree
    ----------------------------------------------------------------- --}}
    <section class="surface p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Points d'entree</h2>
                <p class="mt-1 text-sm text-grey-500">
                    Facultatif. Declarez-en si votre lieu a plusieurs portes, pour
                    savoir par ou chacun est entre.
                </p>
            </div>

            @unless ($showEntryForm)
                <button type="button" wire:click="$set('showEntryForm', true)" class="btn btn-ghost btn-pill">
                    <x-lucide-plus class="size-4" stroke-width="2" />
                    Ajouter une entree
                </button>
            @endunless
        </div>

        @if ($showEntryForm)
            <form wire:submit="saveEntry" class="mt-5 rounded-2xl border border-azure-100 bg-azure-50 p-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="entryName" class="mb-1.5 block text-sm font-medium text-ink">Nom</label>
                        <input id="entryName" type="text" wire:model="entryName" class="field"
                               placeholder="Entree principale">
                        @error('entryName') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="entryDescription" class="mb-1.5 block text-sm font-medium text-ink">
                            Description <span class="font-normal text-grey-400">(facultatif)</span>
                        </label>
                        <input id="entryDescription" type="text" wire:model="entryDescription" class="field"
                               placeholder="Cote boulevard">
                        @error('entryDescription') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Enregistrer</button>
                    <button type="button" wire:click="resetEntryForm" class="btn btn-ghost">Annuler</button>
                </div>
            </form>
        @endif

        <div class="mt-5 space-y-2">
            @forelse ($entryPoints as $entry)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-grey-200 bg-white p-3.5">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-medium text-ink">{{ $entry->name }}</p>
                            @unless ($entry->is_active)
                                <span class="rounded-full bg-grey-200/80 px-2.5 py-0.5 text-xs font-semibold text-grey-500">
                                    Fermee
                                </span>
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
                        <button type="button" wire:click="editEntry({{ $entry->id }})" class="btn btn-ghost">
                            Modifier
                        </button>
                        <button type="button" wire:click="toggleEntry({{ $entry->id }})" class="btn btn-ghost">
                            {{ $entry->is_active ? 'Fermer' : 'Rouvrir' }}
                        </button>
                    </div>
                </div>
            @empty
                <p class="rounded-xl bg-grey-200/40 px-4 py-3 text-sm text-grey-500">
                    Aucun point d'entree declare. Vos agents controleront l'evenement
                    sans distinction de porte — ce qui convient a la plupart des lieux.
                </p>
            @endforelse
        </div>
    </section>

    {{-- ----------------------------------------------------------------
         Agents
    ----------------------------------------------------------------- --}}
    <section class="surface mt-5 p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Agents</h2>
                <p class="mt-1 text-sm text-grey-500">
                    Ils se connectent a l'application mobile avec les identifiants
                    que vous leur transmettez.
                </p>
            </div>

            @unless ($showAgentForm)
                <button type="button" wire:click="$set('showAgentForm', true)" class="btn btn-primary btn-pill">
                    <x-lucide-user-plus class="size-4" stroke-width="2" />
                    Ajouter un agent
                </button>
            @endunless
        </div>

        @if ($showAgentForm)
            <form wire:submit="saveAgent" class="mt-5 rounded-2xl border border-azure-100 bg-azure-50 p-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="agentName" class="mb-1.5 block text-sm font-medium text-ink">Nom</label>
                        <input id="agentName" type="text" wire:model="agentName" class="field">
                        @error('agentName') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="agentEmail" class="mb-1.5 block text-sm font-medium text-ink">Identifiant</label>
                        <input id="agentEmail" type="email" inputmode="email" autocapitalize="none"
                               wire:model="agentEmail" class="field">
                        @error('agentEmail') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="agentEntryPointId" class="mb-1.5 block text-sm font-medium text-ink">
                            Entree <span class="font-normal text-grey-400">(facultatif)</span>
                        </label>
                        <select id="agentEntryPointId" wire:model="agentEntryPointId" class="field">
                            <option value="">Toutes les entrees</option>
                            @foreach ($entryPoints as $entry)
                                <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                            @endforeach
                        </select>
                        @error('agentEntryPointId') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>

                <p class="mt-3 text-xs leading-relaxed text-grey-400">
                    Si cette adresse a deja un compte Pass241, il sera reutilise —
                    un agent qui travaille pour plusieurs organisateurs n'a qu'un
                    seul compte.
                </p>

                <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Affecter</button>
                    <button type="button" wire:click="$set('showAgentForm', false)" class="btn btn-ghost">Annuler</button>
                </div>
            </form>
        @endif

        <div class="mt-5 space-y-2">
            @forelse ($agents as $assignment)
                @php ($stat = $activity[$assignment->user_id] ?? null)
                <div @class([
                    'flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3.5',
                    'border-grey-200 bg-white' => $assignment->is_active,
                    'border-grey-200 bg-white/50' => ! $assignment->is_active,
                ])>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p @class(['font-medium', 'text-ink' => $assignment->is_active, 'text-grey-400' => ! $assignment->is_active])>
                                {{ $assignment->agent?->name }}
                            </p>
                            @unless ($assignment->is_active)
                                <span class="rounded-full bg-grey-200/80 px-2.5 py-0.5 text-xs font-semibold text-grey-500">
                                    Retire
                                </span>
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
                                    {{ \Illuminate\Support\Carbon::parse($stat->last_scan)
                                        ->timezone(config('app.display_timezone'))->format('H\hi') }}
                                @endif
                            </p>
                        @endif
                    </div>

                    @if ($assignment->is_active)
                        <button type="button" wire:click="revokeAgent({{ $assignment->id }})" class="btn btn-ghost shrink-0">
                            Retirer
                        </button>
                    @endif
                </div>
            @empty
                <p class="rounded-xl bg-grey-200/40 px-4 py-3 text-sm text-grey-500">
                    Aucun agent affecte. Sans agent, personne ne pourra scanner les
                    billets a l'entree.
                </p>
            @endforelse
        </div>

        <p class="mt-5 text-xs leading-relaxed text-grey-400">
            Retirer un agent lui coupe l'acces immediatement, sans effacer les
            controles qu'il a deja effectues.
        </p>
    </section>
</div>
