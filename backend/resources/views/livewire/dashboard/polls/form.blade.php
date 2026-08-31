@php
    use App\Enums\PollStatus;

    $tz = config('app.display_timezone');
    $editing = $poll !== null;
@endphp

{{--
    Creation, modification et pilotage d'un vote (section 86.4).

    AUCUN CHAMP DE RESULTAT ICI, et c'est le point : le nombre de votes et le
    classement n'existent pas en colonne (section 86.5). Un formulaire qui les
    porterait, meme desactive, laisserait croire qu'ils se saisissent.
--}}
<div class="space-y-5">

    <x-ui.page-header
        :title="$editing ? $poll->name : 'Nouveau vote'"
        :description="$editing ? 'Modifiez votre vote, puis reglez ses categories et ses candidats.' : 'Un concours, une election, un prix du public.'">
        <x-slot:actions>
            <a href="{{ route('polls.index') }}" wire:navigate class="btn btn-secondary">
                Retour
            </a>

            @if ($editing)
                <a href="{{ route('polls.categories', $poll) }}" wire:navigate class="btn btn-secondary">
                    <x-lucide-list-checks class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                    Categories
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($editing)
        <div class="flex flex-wrap items-center gap-3">
            <x-status-badge :status="$poll->displayStatus()" />

            @if ($poll->status === PollStatus::Cancelled && $poll->cancellation_reason)
                <p class="text-sm text-grey-500">Motif : {{ $poll->cancellation_reason }}</p>
            @endif
        </div>
    @endif

    @error('publication')
        <div role="alert"
             class="flex items-start gap-2.5 rounded-[var(--radius-md)] border border-warning/30 bg-warning/5 px-4 py-3 text-sm text-ink">
            <x-lucide-triangle-alert class="mt-0.5 size-4 shrink-0 text-warning" stroke-width="2" aria-hidden="true" />
            <span>{{ $message }}</span>
        </div>
    @enderror

    {{--
        Le lien public, des qu'il existe.

        C'EST LE PRODUIT DU TRAVAIL DE L'ORGANISATEUR. Il n'y a pas de vitrine
        sur Pass241 : un scrutin n'existe pour le public que par ce lien, que
        l'organisateur partage lui-meme (sections 4 a 6). Il manquait ici, alors
        que l'evenement et la collecte l'affichent tous les deux — un concours
        publie dont on ne peut pas recuperer l'adresse n'est pas publie.

        Il apparait des la PUBLICATION et non a l'ouverture des votes : un
        scrutin programme circule pendant la campagne d'annonce, sa page est
        deja consultable, et c'est precisement le moment ou l'on a besoin du
        lien (decision D-049).
    --}}
    @if ($editing && $poll->isPublished())
        <div class="glass-inset flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5"
             x-data="{ copied: false }">
            <div class="min-w-0">
                <p class="text-xs font-bold tracking-wider text-grey-500 uppercase">Lien public</p>
                <p class="mt-1 truncate font-semibold text-azure-700">{{ $poll->publicUrl() }}</p>
            </div>

            <div class="flex shrink-0 gap-2">
                {{-- La confirmation de copie remplace le libelle sur place et
                     revient seule au bout de deux secondes : une notification
                     flottante pour un geste aussi anodin coute plus d'attention
                     qu'elle n'en merite. --}}
                <button type="button"
                        @click="navigator.clipboard.writeText('{{ $poll->publicUrl() }}'); copied = true; setTimeout(() => copied = false, 2000)"
                        class="btn btn-secondary">
                    <x-lucide-copy class="size-4 shrink-0" stroke-width="2" x-show="!copied" aria-hidden="true" />
                    <x-lucide-check class="size-4 shrink-0 text-success" stroke-width="2.5" x-show="copied" x-cloak aria-hidden="true" />
                    <span x-text="copied ? 'Copie' : 'Copier'">Copier</span>
                </button>

                <a href="{{ $poll->publicUrl() }}" target="_blank" rel="noopener" class="btn btn-primary">
                    <x-lucide-external-link class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                    Ouvrir
                </a>
            </div>
        </div>
    @endif

    <form wire:submit="save" class="space-y-5">

        <x-ui.panel title="Le vote">
            <div class="space-y-4">
                <x-ui.field label="Nom du vote" for="poll-name" :error="$errors->first('name')" required>
                    <input id="poll-name" type="text" wire:model="name" class="field"
                           placeholder="Election Miss Estuaire 2026" maxlength="150" required>
                </x-ui.field>

                <x-ui.field label="Description" for="poll-description" :error="$errors->first('description')"
                            hint="Elle explique ce qui se joue. Exigee pour publier.">
                    <textarea id="poll-description" wire:model="description" rows="4" class="field"
                              maxlength="5000"></textarea>
                </x-ui.field>
            </div>
        </x-ui.panel>

        <x-ui.panel title="Les regles du jeu">
            {{-- Figees une fois le vote ouvert. Ceux qui ont vote — et surtout
                 ceux qui ont paye pour voter — l'ont fait sous les regles
                 annoncees ; les changer en cours de partie reviendrait a
                 rallonger un scrutin qui se joue mal. --}}
            @unless ($termsEditable)
                <div class="mb-4 flex items-start gap-2.5 rounded-[var(--radius-md)] border border-grey-200 bg-surface-muted px-4 py-3 text-sm text-grey-500">
                    <x-lucide-lock class="mt-0.5 size-4 shrink-0 text-grey-400" stroke-width="1.75" aria-hidden="true" />
                    <span>
                        Le vote est ouvert : ses dates et la visibilite des resultats ne changent plus.
                        Ceux qui ont deja vote l'ont fait sous ces regles.
                    </span>
                </div>
            @endunless

            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field label="Ouverture" for="poll-starts" :error="$errors->first('startsAt')" required>
                        <input id="poll-starts" type="datetime-local" wire:model="startsAt" class="field"
                               @disabled(! $termsEditable) required>
                    </x-ui.field>

                    <x-ui.field label="Fermeture" for="poll-ends" :error="$errors->first('endsAt')" required>
                        <input id="poll-ends" type="datetime-local" wire:model="endsAt" class="field"
                               @disabled(! $termsEditable) required>
                    </x-ui.field>
                </div>

                <x-ui.field label="Resultats publics" for="poll-visibility"
                            :error="$errors->first('resultsVisibility')"
                            hint="Chaque categorie pourra suivre ce reglage ou avoir le sien.">
                    <select id="poll-visibility" wire:model="resultsVisibility" class="field"
                            @disabled(! $termsEditable)>
                        @foreach ($visibilities as $visibility)
                            <option value="{{ $visibility->value }}">{{ $visibility->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>

                {{-- Le choix n'est pas cosmetique : afficher un classement en
                     direct CHANGE le vote — un candidat en tete attire, un
                     candidat distance decourage. Le dire evite de le decouvrir
                     apres coup. --}}
                <p class="text-sm text-grey-500">
                    @foreach ($visibilities as $visibility)
                        @if ($visibility->value === $resultsVisibility)
                            {{ $visibility->description() }}
                        @endif
                    @endforeach
                </p>
            </div>
        </x-ui.panel>

        <x-ui.panel title="Images"
                    description="L'image sert d'apercu quand le lien est partage. Exigee pour publier.">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Affiche" for="poll-poster" :error="$errors->first('poster')"
                            hint="JPG, PNG ou WEBP. 4 Mo maximum.">
                    <input id="poll-poster" type="file" wire:model="poster" class="field"
                           accept="image/jpeg,image/png,image/webp">
                </x-ui.field>

                <x-ui.field label="Banniere" for="poll-banner" :error="$errors->first('banner')"
                            hint="Facultative. Sans elle, l'affiche fait l'affaire.">
                    <input id="poll-banner" type="file" wire:model="banner" class="field"
                           accept="image/jpeg,image/png,image/webp">
                </x-ui.field>
            </div>

            @if ($editing && $poll->posterUrl())
                <img src="{{ $poll->posterUrl() }}" alt="Affiche actuelle du vote"
                     class="mt-4 h-32 w-auto rounded-[var(--radius-md)] object-cover">
            @endif
        </x-ui.panel>

        {{-- Ce qui manque encore pour publier. Liste complete plutot qu'un
             manque a la fois : corriger un point pour en decouvrir un autre au
             clic suivant est penible. --}}
        @if ($editing && $blockers !== [] && ! $poll->isPublished())
            <div class="surface p-4 sm:p-5">
                <h2 class="flex items-center gap-2 font-display text-sm font-bold text-ink">
                    <x-lucide-circle-alert class="size-4 shrink-0 text-warning" stroke-width="2" aria-hidden="true" />
                    Avant de publier
                </h2>
                <ul class="mt-3 space-y-1.5 text-sm text-grey-500">
                    @foreach ($blockers as $blocker)
                        <li class="flex items-start gap-2">
                            <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-grey-400"></span>
                            {{ $blocker }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2.5">
            <button type="submit" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="save">
                Enregistrer
            </button>

            @if (! $editing || ! $poll->isPublished())
                <button type="button" wire:click="saveAndPublish" class="btn btn-primary"
                        wire:loading.attr="disabled" wire:target="saveAndPublish">
                    Enregistrer et publier
                </button>
            @endif

            @if ($editing && $poll->status === PollStatus::Scheduled)
                <button type="button" wire:click="openNow" class="btn btn-primary">
                    Ouvrir le vote maintenant
                </button>
            @endif

            @if ($editing && $poll->status === PollStatus::Active)
                <button type="button" wire:click="close" class="btn btn-secondary">
                    Cloturer le vote
                </button>
            @endif
        </div>
    </form>

    {{-- Annulation : toujours motivee, et le motif est public. --}}
    @if ($editing && $poll->status->canTransitionTo(PollStatus::Cancelled))
        <x-ui.panel title="Annuler ce vote"
                    description="La page reste en ligne et affiche le motif. Les votes deja exprimes restent comptes.">
            @if ($confirmingCancellation)
                <div class="space-y-3">
                    <x-ui.field label="Motif de l'annulation" for="poll-cancel-reason"
                                :error="$errors->first('cancellationReason')" required>
                        <textarea id="poll-cancel-reason" wire:model="cancellationReason" rows="3"
                                  class="field" maxlength="500" required></textarea>
                    </x-ui.field>

                    <div class="flex flex-wrap gap-2.5">
                        <button type="button" wire:click="cancel" class="btn btn-danger-solid">
                            Confirmer l'annulation
                        </button>
                        <button type="button" wire:click="$set('confirmingCancellation', false)"
                                class="btn btn-secondary">
                            Revenir
                        </button>
                    </div>
                </div>
            @else
                <button type="button" wire:click="$set('confirmingCancellation', true)" class="btn btn-danger">
                    Annuler ce vote
                </button>
            @endif
        </x-ui.panel>
    @endif
</div>
