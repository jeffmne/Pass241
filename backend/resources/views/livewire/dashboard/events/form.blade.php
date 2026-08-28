@php
    use App\Enums\PermissionName;

    $isPublished = $event?->isPublished() ?? false;
    $locked = $isEditing && ! $event->status->isEditable();

    $user = auth()->user();
@endphp

<div class="space-y-5">

    <x-ui.page-header
        :title="$isEditing ? $event->name : 'Nouvel evenement'"
        :back="route('events.index')"
        back-label="Evenements">
        @if ($isEditing)
            <x-slot:actions>
                <x-status-badge :status="$event->status" />
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
        {{-- `role="alert"` ici, et non `status` : une publication refusee
             interrompt ce que l'utilisateur etait en train de faire, elle doit
             etre annoncee immediatement. --}}
        <div role="alert"
             class="flex items-start gap-2.5 rounded-[var(--radius-md)] border border-warning/30 bg-warning/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-warning" stroke-width="2" aria-hidden="true" />
            <span>{{ $message }}</span>
        </div>
    @enderror

    {{-- Le lien public, des qu'il existe. C'est le produit du travail de
         l'organisateur : il doit etre trouvable en une seconde, pas enfoui
         dans un menu (sections 4, 6). --}}
    @if ($isPublished)
        <div class="glass-inset flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5"
             x-data="{ copied: false }">
            <div class="min-w-0">
                <p class="text-xs font-bold tracking-wider text-grey-500 uppercase">Lien public</p>
                <p class="mt-1 truncate font-semibold text-azure-700">{{ $event->publicUrl() }}</p>
            </div>

            <div class="flex shrink-0 gap-2">
                {{-- La confirmation de copie remplace le libelle sur place et
                     revient seule au bout de deux secondes : une notification
                     flottante pour un geste aussi anodin coute plus d'attention
                     qu'elle n'en merite. --}}
                <button type="button"
                        @click="navigator.clipboard.writeText('{{ $event->publicUrl() }}'); copied = true; setTimeout(() => copied = false, 2000)"
                        class="btn btn-secondary">
                    <x-lucide-copy class="size-4 shrink-0" stroke-width="2" x-show="!copied" aria-hidden="true" />
                    <x-lucide-check class="size-4 shrink-0 text-success" stroke-width="2.5" x-show="copied" x-cloak aria-hidden="true" />
                    <span x-text="copied ? 'Copie' : 'Copier'">Copier</span>
                </button>

                <a href="{{ $event->publicUrl() }}" target="_blank" rel="noopener" class="btn btn-primary">
                    <x-lucide-external-link class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                    Ouvrir
                </a>
            </div>
        </div>
    @endif

    {{-- ================================================================
         Sous-ecrans de l'evenement

         Chacun n'apparait qu'a qui peut s'en servir : un lien menant a une page
         fermee se lit comme une panne, pas comme une restriction.
    ================================================================= --}}
    @if ($isEditing)
        <div class="space-y-3">
            @php $ticketTypeCount = $event->ticketTypes()->count(); @endphp
            <x-ui.nav-card
                :href="route('events.ticket-types', $event)"
                icon="ticket"
                title="Billets"
                :attention="$ticketTypeCount === 0"
                :description="$ticketTypeCount === 0
                    ? 'Aucun billet configure — indispensable pour vendre'
                    : $ticketTypeCount . ' type' . ($ticketTypeCount > 1 ? 's' : '') . ' de billet configure' . ($ticketTypeCount > 1 ? 's' : '')" />

            @can(PermissionName::AccessControlManage->value)
                @php $agentCount = $event->agents()->where('is_active', true)->count(); @endphp
                <x-ui.nav-card
                    :href="route('events.access-control', $event)"
                    icon="scan-line"
                    title="Controle d'acces"
                    :description="$agentCount === 0
                        ? 'Aucun agent affecte — a preparer avant l\'ouverture des portes'
                        : $agentCount . ' agent' . ($agentCount > 1 ? 's' : '') . ' affecte' . ($agentCount > 1 ? 's' : '')" />
            @endcan

            @can(PermissionName::InvitationsManage->value)
                @php $invitationCount = $event->invitations()->count(); @endphp
                <x-ui.nav-card
                    :href="route('events.invitations', $event)"
                    icon="mail"
                    title="Invitations"
                    :description="$invitationCount === 0
                        ? 'Offrir une place a un partenaire, un artiste, un proche'
                        : $invitationCount . ' invitation' . ($invitationCount > 1 ? 's' : '') . ' — chacune retient une place'" />
            @endcan
        </div>
    @endif

    {{-- Exports (section 59).

         Deux permissions decident de ce qui s'affiche : `exports.generate`
         ouvre la fonctionnalite, la permission des donnees ouvre chaque ligne.
         Un co-organisateur voit donc la liste des participants a l'ecran sans
         pouvoir en emporter le fichier — un fichier ne se reprend pas. --}}
    @if ($isEditing && $user->can(PermissionName::ExportsGenerate->value))
        <x-ui.panel title="Exports">
            <div class="space-y-4">
                @can(PermissionName::AttendeesView->value)
                    <x-export-buttons
                        label="Participants"
                        hint="Un billet par ligne, avec son etat et son acheteur."
                        :csv="route('exports.participants', [$event, 'csv'])"
                        :xlsx="route('exports.participants', [$event, 'xlsx'])" />
                @endcan

                @can(PermissionName::FinancesView->value)
                    <x-export-buttons
                        label="Commandes"
                        hint="Montants detailles, frais de paiement compris."
                        :csv="route('exports.orders', [$event, 'csv'])"
                        :xlsx="route('exports.orders', [$event, 'xlsx'])" />
                @endcan

                @can(PermissionName::AttendeesView->value)
                    <x-export-buttons
                        label="Controles a l'entree"
                        hint="Tous les scans, refus et admissions manuelles compris."
                        :csv="route('exports.scans', [$event, 'csv'])"
                        :xlsx="route('exports.scans', [$event, 'xlsx'])" />
                @endcan
            </div>
        </x-ui.panel>
    @endif

    @if ($locked)
        <div class="flex items-start gap-2.5 rounded-[var(--radius-md)] border border-grey-200 bg-surface px-4 py-3 text-sm text-grey-500">
            <x-lucide-lock class="mt-0.5 size-4 shrink-0" stroke-width="2" aria-hidden="true" />
            <span>
                Cet evenement n'est plus librement modifiable : changer sa date ou son
                lieu engage des participants qui ont deja leur billet.
            </span>
        </div>
    @endif

    <form wire:submit="save" class="space-y-5">

        {{-- ------------------------------------------------------------
             L'essentiel
        ------------------------------------------------------------- --}}
        <x-ui.panel title="L'essentiel">
            <div class="space-y-1">
                <x-ui.field label="Nom de l'evenement" for="name" required
                            :hint="$isPublished ? 'Le lien public reste inchange : il a pu etre partage ou imprime.' : null"
                            :error="$errors->first('name')">
                    <input id="name" type="text" wire:model="name" class="field"
                           placeholder="Concert Afro 2026" @disabled($locked)
                           @error('name') aria-invalid="true" aria-describedby="name-error" @enderror
                           required>
                </x-ui.field>

                <x-ui.field label="Description" for="description" :error="$errors->first('description')">
                    <textarea id="description" wire:model="description" rows="5" class="field"
                              placeholder="Ce que vos participants doivent savoir avant de reserver."
                              @disabled($locked)
                              @error('description') aria-invalid="true" aria-describedby="description-error" @enderror></textarea>
                </x-ui.field>

                {{-- Selection locale par Alpine : aucun aller-retour reseau
                     pour cocher une categorie. --}}
                <fieldset x-data="{ selected: $wire.categories }">
                    <legend class="mb-1.5 text-sm font-semibold text-ink">
                        Categories <span class="font-normal text-grey-400">(trois au maximum)</span>
                    </legend>

                    <div class="flex flex-wrap gap-2">
                        @foreach ($categoryCases as $category)
                            {{-- Une case a cocher reelle sous une pastille :
                                 l'apparence change, le controle reste natif,
                                 donc atteignable au clavier et annonce comme
                                 une case a cocher. --}}
                            <label
                                class="flex min-h-11 cursor-pointer items-center gap-2 rounded-full border px-3.5 py-2 text-sm transition-colors"
                                :class="selected.includes('{{ $category->value }}')
                                    ? 'border-azure-500 bg-azure-50 font-semibold text-azure-700'
                                    : 'border-grey-200 bg-surface text-grey-500 hover:border-azure-300'">
                                <input type="checkbox" value="{{ $category->value }}"
                                       wire:model="categories"
                                       x-model="selected"
                                       class="size-4 rounded border-grey-200 text-azure-600" @disabled($locked)>
                                {{ $category->label() }}
                            </label>
                        @endforeach
                    </div>

                    <div class="mt-1.5 min-h-5">
                        @error('categories')
                            <p class="text-xs font-medium text-danger">{{ $message }}</p>
                        @enderror
                        @error('categories.*')
                            <p class="text-xs font-medium text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                </fieldset>
            </div>
        </x-ui.panel>

        {{-- ------------------------------------------------------------
             Quand et ou
        ------------------------------------------------------------- --}}
        <x-ui.panel title="Quand et ou" description="Heures locales de Libreville.">
            <div class="space-y-1">
                <div class="grid gap-x-4 sm:grid-cols-2">
                    <x-ui.field label="Debut" for="startsAt" required :error="$errors->first('startsAt')">
                        <input id="startsAt" type="datetime-local" wire:model="startsAt" class="field"
                               @disabled($locked)
                               @error('startsAt') aria-invalid="true" aria-describedby="startsAt-error" @enderror
                               required>
                    </x-ui.field>

                    <x-ui.field label="Fin" for="endsAt" hint="Facultatif." :error="$errors->first('endsAt')">
                        <input id="endsAt" type="datetime-local" wire:model="endsAt" class="field"
                               @disabled($locked)
                               @error('endsAt') aria-invalid="true" aria-describedby="endsAt-error" @enderror>
                    </x-ui.field>
                </div>

                <x-ui.field label="Lieu" for="locationName" required :error="$errors->first('locationName')">
                    <input id="locationName" type="text" wire:model="locationName" class="field"
                           placeholder="Institut francais du Gabon" @disabled($locked)
                           @error('locationName') aria-invalid="true" aria-describedby="locationName-error" @enderror
                           required>
                </x-ui.field>

                <div class="grid gap-x-4 sm:grid-cols-[1.5fr_1fr]">
                    <x-ui.field label="Adresse" for="locationAddress" hint="Facultatif."
                                :error="$errors->first('locationAddress')">
                        <input id="locationAddress" type="text" wire:model="locationAddress" class="field"
                               placeholder="Boulevard Triomphal" @disabled($locked)
                               @error('locationAddress') aria-invalid="true" aria-describedby="locationAddress-error" @enderror>
                    </x-ui.field>

                    <x-ui.field label="Ville" for="locationCity" required :error="$errors->first('locationCity')">
                        <input id="locationCity" type="text" wire:model="locationCity" class="field"
                               @disabled($locked)
                               @error('locationCity') aria-invalid="true" aria-describedby="locationCity-error" @enderror
                               required>
                    </x-ui.field>
                </div>

                <div class="sm:max-w-xs">
                    <x-ui.field label="Capacite maximale" for="maxCapacity"
                                hint="Facultatif. La limite physique du lieu, distincte du nombre de billets mis en vente."
                                :error="$errors->first('maxCapacity')">
                        <input id="maxCapacity" type="number" min="1" wire:model="maxCapacity" class="field tabular"
                               placeholder="500" @disabled($locked)
                               @error('maxCapacity') aria-invalid="true" aria-describedby="maxCapacity-error" @enderror>
                    </x-ui.field>
                </div>
            </div>
        </x-ui.panel>

        {{-- ------------------------------------------------------------
             Images
        ------------------------------------------------------------- --}}
        <x-ui.panel
            title="Images"
            description="L'affiche sert d'apercu quand votre lien est partage sur WhatsApp. La banniere occupe tout le haut de votre page publique.">

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-ui.field label="Affiche" for="poster" hint="Portrait. JPEG, PNG ou WebP."
                                :error="$errors->first('poster')">
                        @if ($isEditing && $event->posterUrl())
                            <img src="{{ $event->posterUrl() }}" alt="Affiche actuelle"
                                 class="mb-3 aspect-[4/5] w-28 rounded-[var(--radius-sm)] border border-grey-200 object-cover">
                        @endif

                        <input id="poster" type="file" accept="image/jpeg,image/png,image/webp"
                               wire:model="poster"
                               class="field file:mr-3 file:rounded-[var(--radius-xs)] file:border-0 file:bg-azure-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-azure-700"
                               @disabled($locked)
                               @error('poster') aria-invalid="true" aria-describedby="poster-error" @enderror>

                        <p wire:loading wire:target="poster" role="status"
                           class="mt-1.5 flex items-center gap-1.5 text-sm text-grey-500">
                            <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" aria-hidden="true" />
                            Televersement…
                        </p>
                    </x-ui.field>
                </div>

                <div>
                    <x-ui.field label="Banniere" for="cover"
                                hint="Paysage, facultatif. Sans banniere, l'affiche est utilisee — mais elle sera recadree en largeur."
                                :error="$errors->first('cover')">
                        @if ($isEditing && $event->cover_path)
                            <img src="{{ $event->coverUrl() }}" alt="Banniere actuelle"
                                 class="mb-3 aspect-video w-40 rounded-[var(--radius-sm)] border border-grey-200 object-cover">
                        @endif

                        <input id="cover" type="file" accept="image/jpeg,image/png,image/webp"
                               wire:model="cover"
                               class="field file:mr-3 file:rounded-[var(--radius-xs)] file:border-0 file:bg-azure-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-azure-700"
                               @disabled($locked)
                               @error('cover') aria-invalid="true" aria-describedby="cover-error" @enderror>

                        <p wire:loading wire:target="cover" role="status"
                           class="mt-1.5 flex items-center gap-1.5 text-sm text-grey-500">
                            <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" aria-hidden="true" />
                            Televersement…
                        </p>
                    </x-ui.field>
                </div>
            </div>
        </x-ui.panel>

        {{-- ------------------------------------------------------------
             Details pratiques
        ------------------------------------------------------------- --}}
        <x-ui.panel title="Details">
            <div class="space-y-1">
                <x-ui.field label="Programme" for="programme" hint="Facultatif."
                            :error="$errors->first('programme')">
                    <textarea id="programme" wire:model="programme" rows="4" class="field"
                              placeholder="20h00 Ouverture des portes&#10;21h00 Premiere partie"
                              @disabled($locked)
                              @error('programme') aria-invalid="true" aria-describedby="programme-error" @enderror></textarea>
                </x-ui.field>

                <x-ui.field label="Informations pratiques" for="practicalInfo" hint="Facultatif."
                            :error="$errors->first('practicalInfo')">
                    <textarea id="practicalInfo" wire:model="practicalInfo" rows="3" class="field"
                              placeholder="Parking disponible. Tenue correcte exigee."
                              @disabled($locked)
                              @error('practicalInfo') aria-invalid="true" aria-describedby="practicalInfo-error" @enderror></textarea>
                </x-ui.field>

                <fieldset x-data="{ visibility: $wire.visibility }">
                    <legend class="mb-1.5 text-sm font-semibold text-ink">Referencement</legend>

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($visibilityCases as $case)
                            <label
                                class="flex cursor-pointer gap-3 rounded-[var(--radius-sm)] border p-3.5 transition-colors"
                                :class="visibility === '{{ $case->value }}'
                                    ? 'border-azure-500 bg-azure-50'
                                    : 'border-grey-200 bg-surface hover:border-azure-300'">
                                <input type="radio" name="visibility" value="{{ $case->value }}"
                                       wire:model="visibility"
                                       @change="visibility = '{{ $case->value }}'"
                                       class="mt-0.5 size-4 shrink-0 border-grey-200 text-azure-600" @disabled($locked)>
                                <span>
                                    <span class="block text-sm font-semibold text-ink">{{ $case->label() }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-grey-500">{{ $case->hint() }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="mt-1.5 min-h-5">
                        @error('visibility')
                            <p class="text-xs font-medium text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                </fieldset>
            </div>
        </x-ui.panel>

        {{-- Ce qui manque avant publication. Annonce AVANT le bouton plutot
             que decouvert apres un clic refuse. --}}
        @if ($isEditing && ! $isPublished && $blockers !== [])
            <div class="rounded-[var(--radius-md)] border border-warning/30 bg-warning/5 p-4 sm:p-5">
                <p class="flex items-center gap-2 text-sm font-semibold text-ink">
                    <x-lucide-circle-alert class="size-4 shrink-0 text-warning" stroke-width="2" aria-hidden="true" />
                    A completer avant de publier
                </p>
                <ul class="mt-2.5 space-y-1.5">
                    @foreach ($blockers as $blocker)
                        <li class="flex gap-2 text-sm text-grey-500">
                            <span class="mt-2 size-1 shrink-0 rounded-full bg-grey-400" aria-hidden="true"></span>
                            {{ $blocker }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @unless ($locked)
            {{-- Sur telephone, l'action principale est EN HAUT de la pile, donc
                 la plus proche du pouce. A partir de `sm`, l'ordre visuel
                 conventionnel revient : secondaire a gauche, principal a droite. --}}
            <div class="flex flex-col-reverse gap-2 sm:flex-row-reverse">
                @unless ($isPublished)
                    <button type="button" wire:click="saveAndPublish" class="btn btn-primary"
                            wire:loading.attr="disabled" wire:target="saveAndPublish">
                        <span wire:loading.remove wire:target="saveAndPublish">
                            {{ $isEditing ? 'Publier' : 'Creer et publier' }}
                        </span>
                        <span wire:loading wire:target="saveAndPublish" class="flex items-center gap-2">
                            <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" aria-hidden="true" />
                            Publication…
                        </span>
                    </button>
                @endunless

                <button type="submit" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">
                        {{ $isPublished ? 'Enregistrer' : 'Enregistrer le brouillon' }}
                    </span>
                    <span wire:loading wire:target="save">Enregistrement…</span>
                </button>
            </div>
        @endunless
    </form>
</div>
