@php
    $isPublished = $event?->isPublished() ?? false;
    $locked = $isEditing && ! $event->status->isEditable();
@endphp

<div>
    <div class="mb-6">
        <a href="{{ route('events.index') }}" wire:navigate
           class="inline-flex items-center gap-1.5 text-sm font-medium text-grey-500 transition-colors hover:text-azure-700">
            <x-lucide-arrow-left class="size-4" stroke-width="1.75" />
            Evenements
        </a>

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold text-ink sm:text-3xl">
                {{ $isEditing ? $event->name : 'Nouvel evenement' }}
            </h1>
            @if ($isEditing)
                <x-status-badge :status="$event->status" />
            @endif
        </div>
    </div>

    @if (session('notice'))
        <div class="mb-5 flex items-center gap-2.5 rounded-xl border border-success/30 bg-success/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-check class="size-4 shrink-0 text-success" stroke-width="1.75" />
            {{ session('notice') }}
        </div>
    @endif

    @error('publication')
        <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-warning/30 bg-warning/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-warning" stroke-width="1.75" />
            <span>{{ $message }}</span>
        </div>
    @enderror

    {{-- Le lien public, des qu'il existe. C'est le produit du travail de
         l'organisateur : il doit etre trouvable en une seconde, pas enfoui
         dans un menu (sections 4, 6). --}}
    @if ($isPublished)
        <div class="glass-inset mb-5 flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5"
             x-data="{ copied: false }">
            <div class="min-w-0">
                <p class="text-xs font-semibold tracking-wide text-grey-500 uppercase">Lien public</p>
                <p class="mt-1 truncate font-medium text-azure-700">{{ $event->publicUrl() }}</p>
            </div>

            <div class="flex shrink-0 gap-1.5">
                <button type="button"
                        @click="navigator.clipboard.writeText('{{ $event->publicUrl() }}'); copied = true; setTimeout(() => copied = false, 2000)"
                        class="btn btn-ghost">
                    <x-lucide-copy class="size-4" stroke-width="1.75" x-show="!copied" />
                    <x-lucide-check class="size-4 text-success" stroke-width="2" x-show="copied" x-cloak />
                    <span x-text="copied ? 'Copie' : 'Copier'">Copier</span>
                </button>

                <a href="{{ $event->publicUrl() }}" target="_blank" rel="noopener" class="btn btn-primary">
                    <x-lucide-external-link class="size-4" stroke-width="1.75" />
                    Ouvrir
                </a>
            </div>
        </div>
    @endif

    {{-- Les billets ont leur propre ecran : ce formulaire porte deja
         l'evenement, y ajouter la gestion des tarifs et du stock en ferait une
         page interminable. --}}
    @if ($isEditing)
        <a href="{{ route('events.ticket-types', $event) }}" wire:navigate
           class="surface mb-5 flex items-center justify-between gap-4 p-4 transition-colors hover:border-azure-300 sm:p-5">
            <span class="flex min-w-0 items-center gap-3.5">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-azure-100 text-azure-700">
                    <x-lucide-ticket class="size-5" stroke-width="1.5" />
                </span>
                <span class="min-w-0">
                    <span class="block font-display text-base font-bold text-ink">Billets</span>
                    <span class="mt-0.5 block text-sm text-grey-500">
                        @php $ticketTypeCount = $event->ticketTypes()->count(); @endphp
                        @if ($ticketTypeCount === 0)
                            Aucun billet configure — indispensable pour vendre
                        @else
                            {{ $ticketTypeCount }} type{{ $ticketTypeCount > 1 ? 's' : '' }} de billet configure{{ $ticketTypeCount > 1 ? 's' : '' }}
                        @endif
                    </span>
                </span>
            </span>
            <x-lucide-chevron-right class="size-5 shrink-0 text-grey-400" stroke-width="1.75" />
        </a>
    @endif

    {{-- Le controle d'acces n'apparait qu'a qui peut le gerer : un membre sans
         cette permission verrait un lien menant a une page fermee. --}}
    @if ($isEditing && auth()->user()->can(App\Enums\PermissionName::AccessControlManage->value))
        <a href="{{ route('events.access-control', $event) }}" wire:navigate
           class="surface mb-5 flex items-center justify-between gap-4 p-4 transition-colors hover:border-azure-300 sm:p-5">
            <span class="flex min-w-0 items-center gap-3.5">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-azure-100 text-azure-700">
                    <x-lucide-scan-line class="size-5" stroke-width="1.5" />
                </span>
                <span class="min-w-0">
                    <span class="block font-display text-base font-bold text-ink">Controle d'acces</span>
                    <span class="mt-0.5 block text-sm text-grey-500">
                        @php $agentCount = $event->agents()->where('is_active', true)->count(); @endphp
                        @if ($agentCount === 0)
                            Aucun agent affecte — a preparer avant l'ouverture des portes
                        @else
                            {{ $agentCount }} agent{{ $agentCount > 1 ? 's' : '' }} affecte{{ $agentCount > 1 ? 's' : '' }}
                        @endif
                    </span>
                </span>
            </span>
            <x-lucide-chevron-right class="size-5 shrink-0 text-grey-400" stroke-width="1.75" />
        </a>
    @endif

    @if ($locked)
        <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-grey-200 bg-white px-4 py-3 text-sm text-grey-500">
            <x-lucide-lock class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
            <span>
                Cet evenement n'est plus librement modifiable : changer sa date ou son
                lieu engage des participants qui ont deja leur billet.
            </span>
        </div>
    @endif

    <form wire:submit="save" class="space-y-5">
        {{-- L'essentiel --}}
        <section class="surface p-4 sm:p-6">
            <h2 class="font-display text-lg font-bold text-ink">L'essentiel</h2>

            <div class="mt-5 space-y-4">
                <div>
                    <label for="name" class="mb-1.5 block text-sm font-medium text-ink">Nom de l'evenement</label>
                    <input id="name" type="text" wire:model="name" class="field"
                           placeholder="Concert Afro 2026" @disabled($locked)
                           aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" required>
                    @error('name') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror

                    @if ($isPublished)
                        <p class="mt-1.5 text-xs text-grey-400">
                            Le lien public reste inchange : il a pu etre partage ou imprime.
                        </p>
                    @endif
                </div>

                <div>
                    <label for="description" class="mb-1.5 block text-sm font-medium text-ink">Description</label>
                    <textarea id="description" wire:model="description" rows="5" class="field"
                              placeholder="Ce que vos participants doivent savoir avant de reserver."
                              @disabled($locked)></textarea>
                    @error('description') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                {{-- Selection locale par Alpine : aucun aller-retour reseau
                     pour cocher une categorie. --}}
                <div x-data="{ selected: $wire.categories }">
                    <span class="mb-1.5 block text-sm font-medium text-ink">
                        Categories <span class="font-normal text-grey-400">(trois au maximum)</span>
                    </span>

                    <div class="flex flex-wrap gap-2">
                        @foreach ($categoryCases as $category)
                            <label
                                class="flex min-h-11 cursor-pointer items-center gap-2 rounded-full border px-3.5 py-2 text-sm transition-colors"
                                :class="selected.includes('{{ $category->value }}')
                                    ? 'border-azure-500 bg-azure-50 font-semibold text-azure-700'
                                    : 'border-grey-200 bg-white text-grey-500 hover:border-azure-300'">
                                <input type="checkbox" value="{{ $category->value }}"
                                       wire:model="categories"
                                       x-model="selected"
                                       class="size-4" @disabled($locked)>
                                {{ $category->label() }}
                            </label>
                        @endforeach
                    </div>

                    @error('categories') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    @error('categories.*') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- Quand et ou --}}
        <section class="surface p-4 sm:p-6">
            <h2 class="font-display text-lg font-bold text-ink">Quand et ou</h2>

            <div class="mt-5 space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="startsAt" class="mb-1.5 block text-sm font-medium text-ink">Debut</label>
                        <input id="startsAt" type="datetime-local" wire:model="startsAt" class="field"
                               @disabled($locked)
                               aria-invalid="{{ $errors->has('startsAt') ? 'true' : 'false' }}" required>
                        @error('startsAt') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="endsAt" class="mb-1.5 block text-sm font-medium text-ink">
                            Fin <span class="font-normal text-grey-400">(facultatif)</span>
                        </label>
                        <input id="endsAt" type="datetime-local" wire:model="endsAt" class="field"
                               @disabled($locked)
                               aria-invalid="{{ $errors->has('endsAt') ? 'true' : 'false' }}">
                        @error('endsAt') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>

                <p class="text-xs text-grey-400">
                    Heures locales de Libreville.
                </p>

                <div>
                    <label for="locationName" class="mb-1.5 block text-sm font-medium text-ink">Lieu</label>
                    <input id="locationName" type="text" wire:model="locationName" class="field"
                           placeholder="Institut francais du Gabon" @disabled($locked)
                           aria-invalid="{{ $errors->has('locationName') ? 'true' : 'false' }}" required>
                    @error('locationName') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-[1.5fr_1fr]">
                    <div>
                        <label for="locationAddress" class="mb-1.5 block text-sm font-medium text-ink">
                            Adresse <span class="font-normal text-grey-400">(facultatif)</span>
                        </label>
                        <input id="locationAddress" type="text" wire:model="locationAddress" class="field"
                               placeholder="Boulevard Triomphal" @disabled($locked)>
                        @error('locationAddress') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="locationCity" class="mb-1.5 block text-sm font-medium text-ink">Ville</label>
                        <input id="locationCity" type="text" wire:model="locationCity" class="field"
                               @disabled($locked) required>
                        @error('locationCity') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="sm:max-w-xs">
                    <label for="maxCapacity" class="mb-1.5 block text-sm font-medium text-ink">
                        Capacite maximale <span class="font-normal text-grey-400">(facultatif)</span>
                    </label>
                    <input id="maxCapacity" type="number" min="1" wire:model="maxCapacity" class="field tabular"
                           placeholder="500" @disabled($locked)>
                    @error('maxCapacity') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-xs text-grey-400">
                        La limite physique du lieu, distincte du nombre de billets mis en vente.
                    </p>
                </div>
            </div>
        </section>

        {{-- Images --}}
        <section class="surface p-4 sm:p-6">
            <h2 class="font-display text-lg font-bold text-ink">Images</h2>
            <p class="mt-1.5 text-sm text-grey-500">
                L'affiche sert d'apercu quand votre lien est partage sur WhatsApp.
                La banniere occupe tout le haut de votre page publique.
            </p>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="poster" class="mb-1.5 block text-sm font-medium text-ink">
                        Affiche <span class="font-normal text-grey-400">(portrait)</span>
                    </label>

                    @if ($isEditing && $event->posterUrl())
                        <img src="{{ $event->posterUrl() }}" alt="Affiche actuelle"
                             class="mb-3 aspect-[4/5] w-28 rounded-xl border border-grey-200 object-cover">
                    @endif

                    <input id="poster" type="file" accept="image/jpeg,image/png,image/webp"
                           wire:model="poster" class="field file:mr-3 file:rounded-lg file:border-0 file:bg-azure-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-azure-700"
                           @disabled($locked)>

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

                    @if ($isEditing && $event->cover_path)
                        <img src="{{ $event->coverUrl() }}" alt="Banniere actuelle"
                             class="mb-3 aspect-video w-40 rounded-xl border border-grey-200 object-cover">
                    @endif

                    <input id="cover" type="file" accept="image/jpeg,image/png,image/webp"
                           wire:model="cover" class="field file:mr-3 file:rounded-lg file:border-0 file:bg-azure-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-azure-700"
                           @disabled($locked)>

                    <p wire:loading wire:target="cover" class="mt-1.5 flex items-center gap-1.5 text-sm text-grey-500">
                        <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" />
                        Televersement
                    </p>
                    @error('cover') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-xs text-grey-400">
                        Sans banniere, l'affiche est utilisee — mais elle sera recadree en largeur.
                    </p>
                </div>
            </div>
        </section>

        {{-- Details pratiques --}}
        <section class="surface p-4 sm:p-6">
            <h2 class="font-display text-lg font-bold text-ink">Details</h2>

            <div class="mt-5 space-y-4">
                <div>
                    <label for="programme" class="mb-1.5 block text-sm font-medium text-ink">
                        Programme <span class="font-normal text-grey-400">(facultatif)</span>
                    </label>
                    <textarea id="programme" wire:model="programme" rows="4" class="field"
                              placeholder="20h00 Ouverture des portes&#10;21h00 Premiere partie"
                              @disabled($locked)></textarea>
                    @error('programme') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="practicalInfo" class="mb-1.5 block text-sm font-medium text-ink">
                        Informations pratiques <span class="font-normal text-grey-400">(facultatif)</span>
                    </label>
                    <textarea id="practicalInfo" wire:model="practicalInfo" rows="3" class="field"
                              placeholder="Parking disponible. Tenue correcte exigee."
                              @disabled($locked)></textarea>
                    @error('practicalInfo') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                <fieldset x-data="{ visibility: $wire.visibility }">
                    <legend class="mb-1.5 block text-sm font-medium text-ink">Referencement</legend>

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($visibilityCases as $case)
                            <label
                                class="flex cursor-pointer gap-3 rounded-xl border p-3.5 transition-colors"
                                :class="visibility === '{{ $case->value }}'
                                    ? 'border-azure-500 bg-azure-50'
                                    : 'border-grey-200 bg-white hover:border-azure-300'">
                                <input type="radio" name="visibility" value="{{ $case->value }}"
                                       wire:model="visibility"
                                       @change="visibility = '{{ $case->value }}'"
                                       class="mt-0.5 size-4 shrink-0" @disabled($locked)>
                                <span>
                                    <span class="block text-sm font-semibold text-ink">{{ $case->label() }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-grey-500">{{ $case->hint() }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @error('visibility') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </fieldset>
            </div>
        </section>

        {{-- Ce qui manque avant publication. Annonce avant le bouton plutot
             que decouvert apres un clic refuse. --}}
        @if ($isEditing && ! $isPublished && $blockers !== [])
            <div class="rounded-xl border border-warning/30 bg-warning/5 p-4 sm:p-5">
                <p class="flex items-center gap-2 text-sm font-semibold text-ink">
                    <x-lucide-circle-alert class="size-4 shrink-0 text-warning" stroke-width="1.75" />
                    A completer avant de publier
                </p>
                <ul class="mt-2.5 space-y-1.5">
                    @foreach ($blockers as $blocker)
                        <li class="flex gap-2 text-sm text-grey-500">
                            <span class="mt-2 size-1 shrink-0 rounded-full bg-grey-400"></span>
                            {{ $blocker }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @unless ($locked)
            <div class="flex flex-col gap-3 sm:flex-row-reverse">
                @unless ($isPublished)
                    <button type="button" wire:click="saveAndPublish" class="btn btn-primary"
                            wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveAndPublish">
                            {{ $isEditing ? 'Publier' : 'Creer et publier' }}
                        </span>
                        <span wire:loading wire:target="saveAndPublish" class="flex items-center gap-2">
                            <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" />
                            Publication
                        </span>
                    </button>
                @endunless

                <button type="submit" class="btn btn-ghost" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">
                        {{ $isPublished ? 'Enregistrer' : 'Enregistrer le brouillon' }}
                    </span>
                    <span wire:loading wire:target="save">Enregistrement</span>
                </button>
            </div>
        @endunless
    </form>
</div>
