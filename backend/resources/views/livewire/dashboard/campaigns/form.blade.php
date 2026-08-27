@php
    use App\Enums\CampaignStatus;

    $nbsp = "\u{202F}";
    $money = fn (int $amount) => number_format($amount, 0, ',', $nbsp).' F';
    $status = $campaign?->status;
@endphp

{{--
    Creation et pilotage d'une collecte (sections 34, 35).

    CE FORMULAIRE NE PORTE AUCUN MONTANT COLLECTE. Le chiffre affiche plus bas
    est calcule par le serveur et rendu en lecture seule : la regle 80.1 interdit
    qu'un organisateur puisse le saisir, et un champ desactive laisserait croire
    le contraire.

    Surfaces pleines : ecran de saisie dense (regle 70.4).
--}}
<div>
    <header class="mb-6">
        <a href="{{ route('campaigns.index') }}" wire:navigate
           class="inline-flex items-center gap-1.5 text-sm font-medium text-grey-500 transition-colors hover:text-azure-700">
            <x-lucide-arrow-left class="size-4" stroke-width="1.75" />
            Collectes
        </a>

        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="font-display text-2xl font-extrabold text-ink sm:text-3xl">
                {{ $isEditing ? $campaign->name : 'Nouvelle collecte' }}
            </h1>
            @if ($isEditing)
                <x-status-badge :status="$campaign->displayStatus()" />
            @endif
        </div>

        @if ($isEditing && $campaign->isPublished())
            <p class="mt-2 truncate text-sm text-azure-700">{{ $campaign->publicUrl() }}</p>
        @endif
    </header>

    @if (session('notice'))
        <div class="mb-5 flex items-center gap-2.5 rounded-xl border border-success/30 bg-success/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-check class="size-4 shrink-0 text-success" stroke-width="1.75" />
            {{ session('notice') }}
        </div>
    @endif

    @error('publication')
        <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-danger" stroke-width="1.75" />
            {{ $message }}
        </div>
    @enderror

    {{-- Progression, en lecture seule (sections 37, 38). --}}
    @if ($isEditing && $progress)
        <div class="surface mb-6 p-4 sm:p-5" data-reveal>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold tracking-wide text-grey-500 uppercase">Collecte</p>
                    <p class="mt-1 font-display text-2xl font-extrabold text-ink tabular">
                        {{ $money($progress['collected']) }}
                    </p>
                </div>
                <p class="text-sm text-grey-500 tabular">
                    {{ number_format($progress['donations'], 0, ',', $nbsp) }}
                    don{{ $progress['donations'] > 1 ? 's' : '' }}
                    · objectif {{ $money($progress['goal']) }}
                </p>
            </div>

            <div class="mt-4 h-2 w-full overflow-hidden rounded-full bg-azure-100">
                <div class="progress-fill h-full rounded-full bg-gradient-to-r from-azure-500 to-azure-600"
                     style="--progress: {{ $progress['displayPercentage'] }}%"></div>
            </div>

            <p class="mt-3 text-xs text-grey-500">
                Ces chiffres sont calcules a partir des dons confirmes. Ils ne se
                modifient pas a la main, ni ici ni ailleurs.
            </p>
        </div>
    @endif

    {{-- Conditions de publication, annoncees avant le clic et non decouvertes
         apres (meme parti pris que pour les evenements). --}}
    @if ($isEditing && $blockers !== [] && $status === CampaignStatus::Draft)
        <div class="mb-6 rounded-2xl border border-azure-200 bg-azure-50 p-4 sm:p-5">
            <p class="flex items-center gap-2 font-semibold text-ink">
                <x-lucide-info class="size-4 shrink-0 text-azure-600" stroke-width="1.75" />
                Avant de publier
            </p>
            <ul class="mt-2 space-y-1 text-sm text-grey-500">
                @foreach ($blockers as $blocker)
                    <li class="flex items-start gap-2">
                        <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-azure-400"></span>
                        {{ $blocker }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($locked && ! $closed)
        <div class="mb-6 flex items-start gap-2.5 rounded-xl border border-grey-200 bg-canvas px-4 py-3 text-sm text-grey-500">
            <x-lucide-lock class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
            <span>
                L'objectif, le beneficiaire et les dates sont figes depuis la publication :
                ils engagent ceux qui ont deja donne. La description et les images restent
                modifiables.
            </span>
        </div>
    @endif

    <form wire:submit="save" class="surface p-4 sm:p-6">
        <div class="grid gap-5">
            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-ink">Nom de la collecte</label>
                <input id="name" type="text" wire:model="name" class="field"
                       placeholder="Operation cartables 2026" @disabled($locked)>
                @error('name') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="description" class="mb-1.5 block text-sm font-medium text-ink">Description</label>
                <textarea id="description" wire:model="description" rows="6" class="field"
                          placeholder="Expliquez la cause, l'usage prevu des fonds et l'echeance."
                          @disabled($closed)></textarea>
                <p class="mt-1.5 text-xs text-grey-500">
                    C'est elle qui donne envie de contribuer. Elle reste modifiable apres publication.
                </p>
                @error('description') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="goalAmount" class="mb-1.5 block text-sm font-medium text-ink">
                        Objectif <span class="font-normal text-grey-400">(F CFA)</span>
                    </label>
                    <input id="goalAmount" type="number" min="1000" step="1000" inputmode="numeric"
                           wire:model="goalAmount" class="field tabular"
                           placeholder="1000000" @disabled($locked)>
                    @error('goalAmount') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="beneficiaryLabel" class="mb-1.5 block text-sm font-medium text-ink">
                        Qui recoit les fonds
                    </label>
                    <input id="beneficiaryLabel" type="text" wire:model="beneficiaryLabel" class="field"
                           placeholder="Famille Ndong" @disabled($locked)>
                    <p class="mt-1.5 text-xs text-grey-500">
                        Affiche publiquement sur la page de la collecte.
                    </p>
                    @error('beneficiaryLabel') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="startsAt" class="mb-1.5 block text-sm font-medium text-ink">
                        Debut <span class="font-normal text-grey-400">(facultatif)</span>
                    </label>
                    <input id="startsAt" type="datetime-local" wire:model="startsAt" class="field"
                           @disabled($locked)>
                    @error('startsAt') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="endsAt" class="mb-1.5 block text-sm font-medium text-ink">
                        Fin <span class="font-normal text-grey-400">(facultatif)</span>
                    </label>
                    <input id="endsAt" type="datetime-local" wire:model="endsAt" class="field"
                           @disabled($locked)>
                    <p class="mt-1.5 text-xs text-grey-500">
                        Passe cette date, la collecte n'accepte plus de dons.
                    </p>
                    @error('endsAt') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="poster" class="mb-1.5 block text-sm font-medium text-ink">
                        Image <span class="font-normal text-grey-400">(portrait)</span>
                    </label>

                    @if ($isEditing && $campaign->posterUrl())
                        <img src="{{ $campaign->posterUrl() }}" alt="Image actuelle"
                             class="mb-3 aspect-[4/5] w-28 rounded-xl border border-grey-200 object-cover">
                    @endif

                    <input id="poster" type="file" accept="image/jpeg,image/png,image/webp"
                           wire:model="poster" class="field file:mr-3 file:rounded-lg file:border-0 file:bg-azure-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-azure-700"
                           @disabled($closed)>

                    <p wire:loading wire:target="poster" class="mt-1.5 flex items-center gap-1.5 text-sm text-grey-500">
                        <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" />
                        Televersement
                    </p>
                    @error('poster') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="cover" class="mb-1.5 block text-sm font-medium text-ink">
                        Banniere <span class="font-normal text-grey-400">(paysage, facultatif)</span>
                    </label>

                    @if ($isEditing && $campaign->cover_path)
                        <img src="{{ $campaign->coverUrl() }}" alt="Banniere actuelle"
                             class="mb-3 aspect-video w-40 rounded-xl border border-grey-200 object-cover">
                    @endif

                    <input id="cover" type="file" accept="image/jpeg,image/png,image/webp"
                           wire:model="cover" class="field file:mr-3 file:rounded-lg file:border-0 file:bg-azure-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-azure-700"
                           @disabled($closed)>

                    <p wire:loading wire:target="cover" class="mt-1.5 flex items-center gap-1.5 text-sm text-grey-500">
                        <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" />
                        Televersement
                    </p>
                    @error('cover') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        @unless ($closed)
            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                @if (! $isEditing || $status === CampaignStatus::Draft)
                    <button type="button" wire:click="saveAndPublish" class="btn btn-primary btn-pill"
                            wire:loading.attr="disabled">
                        <x-lucide-send class="size-4" stroke-width="2" />
                        Enregistrer et publier
                    </button>
                @endif

                <button type="submit" class="btn btn-ghost btn-pill" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">Enregistrer</span>
                    <span wire:loading wire:target="save">Enregistrement</span>
                </button>
            </div>
        @endunless
    </form>

    {{-- ---------------------------------------------------------------
         Decisions sur la collecte (section 35)

         Aucune de ces actions ne touche a un don deja recu : elles ferment la
         collecte pour l'avenir, jamais l'historique (sections 44 a 46).
    ---------------------------------------------------------------- --}}
    @if ($isEditing && ! $closed && $campaign->isPublished())
        <section class="surface mt-6 p-4 sm:p-6">
            <h2 class="font-display text-lg font-bold text-ink">Piloter la collecte</h2>

            <div class="mt-4 flex flex-wrap gap-2">
                @if ($status === CampaignStatus::Published)
                    <button type="button" wire:click="pause" class="btn btn-ghost btn-pill">
                        <x-lucide-circle-pause class="size-4" stroke-width="1.75" />
                        Suspendre
                    </button>
                @elseif ($status === CampaignStatus::Paused)
                    <button type="button" wire:click="resume" class="btn btn-ghost btn-pill">
                        <x-lucide-circle-play class="size-4" stroke-width="1.75" />
                        Rouvrir
                    </button>
                @endif

                <button type="button" wire:click="complete" class="btn btn-ghost btn-pill">
                    <x-lucide-flag class="size-4" stroke-width="1.75" />
                    Cloturer
                </button>

                @unless ($confirmingCancellation)
                    <button type="button" wire:click="$set('confirmingCancellation', true)"
                            class="text-sm font-medium text-danger transition-opacity hover:opacity-70">
                        Annuler la collecte
                    </button>
                @endunless
            </div>

            <p class="mt-3 text-xs text-grey-500">
                Suspendre ferme les dons en laissant la page et son historique en ligne.
                Cloturer met fin a la collecte. Aucune de ces actions ne retire un don deja recu.
            </p>

            @if ($confirmingCancellation)
                <div class="mt-4 rounded-2xl border border-danger/30 bg-danger/5 p-4">
                    <label for="cancellationReason" class="mb-1.5 block text-sm font-medium text-ink">
                        Motif de l'annulation
                    </label>
                    <textarea id="cancellationReason" wire:model="cancellationReason" rows="3" class="field"
                              placeholder="Il sera affiche sur la page publique."></textarea>
                    @error('cancellationReason') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror

                    <p class="mt-2 text-xs text-grey-500">
                        Les dons deja recus ne sont pas rembourses automatiquement : un don
                        remis a une cause a pu etre employe. Le remboursement se decide don
                        par don.
                    </p>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="cancel" class="btn btn-pill bg-danger text-white hover:opacity-90">
                            Confirmer l'annulation
                        </button>
                        <button type="button" wire:click="$set('confirmingCancellation', false)"
                                class="btn btn-ghost btn-pill">
                            Revenir
                        </button>
                    </div>
                </div>
            @endif
        </section>
    @endif
</div>
