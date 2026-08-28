@php
    use App\Enums\CampaignStatus;
    use App\Support\Money;

    $money = fn (int $amount) => Money::formatShort($amount);
    $status = $campaign?->status;
@endphp

{{--
    Creation et pilotage d'une collecte (sections 34, 35).

    CE FORMULAIRE NE PORTE AUCUN MONTANT COLLECTE. Le chiffre affiche plus haut
    est calcule par le serveur et rendu en lecture seule : la regle 80.1 interdit
    qu'un organisateur puisse le saisir, et un champ desactive laisserait croire
    le contraire.
--}}
<div class="space-y-5">

    <x-ui.page-header
        :title="$isEditing ? $campaign->name : 'Nouvelle collecte'"
        :description="$isEditing && $campaign->isPublished() ? $campaign->publicUrl() : null"
        :back="route('campaigns.index')"
        back-label="Collectes">
        @if ($isEditing)
            <x-slot:actions>
                <x-status-badge :status="$campaign->displayStatus()" />
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if (session('notice'))
        <div role="status"
             class="flex items-center gap-2.5 rounded-[var(--radius-md)] border border-success/30 bg-success/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-check class="size-4 shrink-0 text-success" stroke-width="2" aria-hidden="true" />
            {{ session('notice') }}
        </div>
    @endif

    @error('publication')
        <div role="alert"
             class="flex items-start gap-2.5 rounded-[var(--radius-md)] border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-danger" stroke-width="2" aria-hidden="true" />
            {{ $message }}
        </div>
    @enderror

    {{-- Progression, en lecture seule (sections 37, 38). En verre : c'est de la
         synthese, on y lit une proportion. --}}
    @if ($isEditing && $progress)
        <x-ui.panel variant="glass" data-reveal>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-xs font-bold tracking-wider text-grey-500 uppercase">Collecte</p>
                    <p class="mt-1 font-display text-3xl leading-none font-extrabold text-ink tabular">
                        {{ $money($progress['collected']) }}
                    </p>
                </div>
                <p class="text-sm text-grey-500 tabular">
                    {{ Money::number($progress['donations']) }}
                    don{{ $progress['donations'] > 1 ? 's' : '' }}
                    · objectif {{ $money($progress['goal']) }}
                </p>
            </div>

            <div class="progress-track mt-4">
                <div @class([
                        'progress-fill',
                        'progress-fill-success' => $progress['displayPercentage'] >= 100,
                     ])
                     style="--progress: {{ $progress['displayPercentage'] }}%"></div>
            </div>

            <p class="mt-3 text-xs text-grey-500">
                Ces chiffres sont calcules a partir des dons confirmes. Ils ne se
                modifient pas a la main, ni ici ni ailleurs.
            </p>

            <a href="{{ route('campaigns.donations', $campaign) }}" wire:navigate
               class="btn btn-secondary mt-4">
                <x-lucide-list class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                Voir les dons recus
            </a>
        </x-ui.panel>
    @endif

    {{-- Conditions de publication, annoncees avant le clic et non decouvertes
         apres (meme parti pris que pour les evenements). --}}
    @if ($isEditing && $blockers !== [] && $status === CampaignStatus::Draft)
        <div class="rounded-[var(--radius-md)] border border-azure-300/50 bg-azure-50 p-4 sm:p-5">
            <p class="flex items-center gap-2 font-semibold text-ink">
                <x-lucide-info class="size-4 shrink-0 text-azure-600" stroke-width="2" aria-hidden="true" />
                Avant de publier
            </p>
            <ul class="mt-2 space-y-1 text-sm text-grey-500">
                @foreach ($blockers as $blocker)
                    <li class="flex items-start gap-2">
                        <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-azure-300" aria-hidden="true"></span>
                        {{ $blocker }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($locked && ! $closed)
        <div class="flex items-start gap-2.5 rounded-[var(--radius-md)] border border-grey-200 bg-surface px-4 py-3 text-sm text-grey-500">
            <x-lucide-lock class="mt-0.5 size-4 shrink-0" stroke-width="2" aria-hidden="true" />
            <span>
                L'objectif, le beneficiaire et les dates sont figes depuis la publication :
                ils engagent ceux qui ont deja donne. La description et les images restent
                modifiables.
            </span>
        </div>
    @endif

    <form wire:submit="save">
        <x-ui.panel>
            <div class="space-y-1">
                <x-ui.field label="Nom de la collecte" for="name" required :error="$errors->first('name')">
                    <input id="name" type="text" wire:model="name" class="field"
                           placeholder="Operation cartables 2026" @disabled($locked)
                           @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                </x-ui.field>

                <x-ui.field label="Description" for="description"
                            hint="C'est elle qui donne envie de contribuer. Elle reste modifiable apres publication."
                            :error="$errors->first('description')">
                    <textarea id="description" wire:model="description" rows="6" class="field"
                              placeholder="Expliquez la cause, l'usage prevu des fonds et l'echeance."
                              @disabled($closed)
                              @error('description') aria-invalid="true" aria-describedby="description-error" @enderror></textarea>
                </x-ui.field>

                <div class="grid gap-x-4 sm:grid-cols-2">
                    <x-ui.field label="Objectif" for="goalAmount" required :error="$errors->first('goalAmount')">
                        <div class="relative">
                            <input id="goalAmount" type="number" min="1000" step="1000" inputmode="numeric"
                                   wire:model="goalAmount" class="field tabular pr-16"
                                   placeholder="1000000" @disabled($locked)
                                   @error('goalAmount') aria-invalid="true" aria-describedby="goalAmount-error" @enderror>
                            {{-- L'unite DANS le champ : au moment de taper un
                                 montant, c'est la que le regard se trouve. --}}
                            <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm font-semibold text-grey-400">
                                FCFA
                            </span>
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Qui recoit les fonds" for="beneficiaryLabel" required
                                hint="Affiche publiquement sur la page de la collecte."
                                :error="$errors->first('beneficiaryLabel')">
                        <input id="beneficiaryLabel" type="text" wire:model="beneficiaryLabel" class="field"
                               placeholder="Famille Ndong" @disabled($locked)
                               @error('beneficiaryLabel') aria-invalid="true" aria-describedby="beneficiaryLabel-error" @enderror>
                    </x-ui.field>
                </div>

                <div class="grid gap-x-4 sm:grid-cols-2">
                    <x-ui.field label="Debut" for="startsAt" hint="Facultatif." :error="$errors->first('startsAt')">
                        <input id="startsAt" type="datetime-local" wire:model="startsAt" class="field"
                               @disabled($locked)
                               @error('startsAt') aria-invalid="true" aria-describedby="startsAt-error" @enderror>
                    </x-ui.field>

                    <x-ui.field label="Fin" for="endsAt"
                                hint="Facultatif. Passe cette date, la collecte n'accepte plus de dons."
                                :error="$errors->first('endsAt')">
                        <input id="endsAt" type="datetime-local" wire:model="endsAt" class="field"
                               @disabled($locked)
                               @error('endsAt') aria-invalid="true" aria-describedby="endsAt-error" @enderror>
                    </x-ui.field>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.field label="Image" for="poster" hint="Portrait. JPEG, PNG ou WebP."
                                :error="$errors->first('poster')">
                        @if ($isEditing && $campaign->posterUrl())
                            <img src="{{ $campaign->posterUrl() }}" alt="Image actuelle"
                                 class="mb-3 aspect-[4/5] w-28 rounded-[var(--radius-sm)] border border-grey-200 object-cover">
                        @endif

                        <input id="poster" type="file" accept="image/jpeg,image/png,image/webp"
                               wire:model="poster"
                               class="field file:mr-3 file:rounded-[var(--radius-xs)] file:border-0 file:bg-azure-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-azure-700"
                               @disabled($closed)
                               @error('poster') aria-invalid="true" aria-describedby="poster-error" @enderror>

                        <p wire:loading wire:target="poster" role="status"
                           class="mt-1.5 flex items-center gap-1.5 text-sm text-grey-500">
                            <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" aria-hidden="true" />
                            Televersement…
                        </p>
                    </x-ui.field>

                    <x-ui.field label="Banniere" for="cover" hint="Paysage, facultatif."
                                :error="$errors->first('cover')">
                        @if ($isEditing && $campaign->cover_path)
                            <img src="{{ $campaign->coverUrl() }}" alt="Banniere actuelle"
                                 class="mb-3 aspect-video w-40 rounded-[var(--radius-sm)] border border-grey-200 object-cover">
                        @endif

                        <input id="cover" type="file" accept="image/jpeg,image/png,image/webp"
                               wire:model="cover"
                               class="field file:mr-3 file:rounded-[var(--radius-xs)] file:border-0 file:bg-azure-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-azure-700"
                               @disabled($closed)
                               @error('cover') aria-invalid="true" aria-describedby="cover-error" @enderror>

                        <p wire:loading wire:target="cover" role="status"
                           class="mt-1.5 flex items-center gap-1.5 text-sm text-grey-500">
                            <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" aria-hidden="true" />
                            Televersement…
                        </p>
                    </x-ui.field>
                </div>
            </div>

            @unless ($closed)
                <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row-reverse sm:justify-start">
                    @if (! $isEditing || $status === CampaignStatus::Draft)
                        <button type="button" wire:click="saveAndPublish" class="btn btn-primary"
                                wire:loading.attr="disabled" wire:target="saveAndPublish">
                            <x-lucide-send class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                            Enregistrer et publier
                        </button>
                    @endif

                    <button type="submit" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Enregistrer</span>
                        <span wire:loading wire:target="save">Enregistrement…</span>
                    </button>
                </div>
            @endunless
        </x-ui.panel>
    </form>

    {{-- ---------------------------------------------------------------
         Decisions sur la collecte (section 35)

         Aucune de ces actions ne touche a un don deja recu : elles ferment la
         collecte pour l'avenir, jamais l'historique (sections 44 a 46).
    ---------------------------------------------------------------- --}}
    @if ($isEditing && ! $closed && $campaign->isPublished())
        <x-ui.panel title="Piloter la collecte">
            <div class="flex flex-wrap gap-2">
                @if ($status === CampaignStatus::Published)
                    <button type="button" wire:click="pause" class="btn btn-secondary">
                        <x-lucide-circle-pause class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                        Suspendre
                    </button>
                @elseif ($status === CampaignStatus::Paused)
                    <button type="button" wire:click="resume" class="btn btn-secondary">
                        <x-lucide-circle-play class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                        Rouvrir
                    </button>
                @endif

                <button type="button" wire:click="complete"
                        wire:confirm="Cloturer la collecte ? Elle n'acceptera plus de dons."
                        class="btn btn-secondary">
                    <x-lucide-flag class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                    Cloturer
                </button>

                @unless ($confirmingCancellation)
                    <button type="button" wire:click="$set('confirmingCancellation', true)"
                            class="btn btn-danger">
                        Annuler la collecte
                    </button>
                @endunless
            </div>

            <p class="mt-3 text-xs leading-relaxed text-grey-500">
                Suspendre ferme les dons en laissant la page et son historique en ligne.
                Cloturer met fin a la collecte. Aucune de ces actions ne retire un don deja recu.
            </p>

            @if ($confirmingCancellation)
                <div class="mt-4 rounded-[var(--radius-md)] border border-danger/30 bg-danger/5 p-4">
                    <x-ui.field label="Motif de l'annulation" for="cancellationReason" required
                                hint="Il sera affiche sur la page publique."
                                :error="$errors->first('cancellationReason')">
                        <textarea id="cancellationReason" wire:model="cancellationReason" rows="3" class="field"
                                  placeholder="Il sera affiche sur la page publique."
                                  @error('cancellationReason') aria-invalid="true" aria-describedby="cancellationReason-error" @enderror></textarea>
                    </x-ui.field>

                    <p class="text-xs leading-relaxed text-grey-500">
                        Les dons deja recus ne sont pas rembourses automatiquement : un don
                        remis a une cause a pu etre employe. Le remboursement se decide don
                        par don.
                    </p>

                    <div class="mt-3 flex flex-col-reverse gap-2 sm:flex-row-reverse sm:justify-start">
                        {{-- Rouge PLEIN, contrairement aux autres boutons
                             destructifs de l'ecran : ici il n'y a plus d'autre
                             choix a faire, c'est le point de non-retour. --}}
                        <button type="button" wire:click="cancel" class="btn btn-danger-solid">
                            Confirmer l'annulation
                        </button>
                        <button type="button" wire:click="$set('confirmingCancellation', false)"
                                class="btn btn-secondary">
                            Revenir
                        </button>
                    </div>
                </div>
            @endif
        </x-ui.panel>
    @endif
</div>
