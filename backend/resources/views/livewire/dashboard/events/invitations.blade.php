@php
    use App\Enums\InvitationStatus;
    use App\Support\Money;

    $tz = config('app.display_timezone');
@endphp

{{--
    Invitations d'un evenement (section 25).

    Une invitation RETIENT UNE PLACE des sa creation. C'est la seule chose que
    l'organisateur doit comprendre avant d'inviter cinquante personnes sur un
    evenement qui en contient deux cents — l'ecran le dit donc en chiffres, pas
    en avertissement.
--}}
<div class="space-y-5">

    <x-ui.page-header
        title="Invitations"
        description="Offrez une place. Chaque invitation en retient une jusqu'a sa date limite."
        :eyebrow="$event->name"
        :back="route('events.edit', $event)"
        :back-label="$event->name">
        @if (! $showForm && $ticketTypes->isNotEmpty())
            <x-slot:actions>
                <button type="button" wire:click="$set('showForm', true)" class="btn btn-primary">
                    <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                    Nouvelles invitations
                </button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    {{-- Liens crees a l'instant : affiches une fois, a copier et transmettre.
         L'email part pour ceux qui ont une adresse, mais le lien reste visible
         pour tous : ici, une invitation circule le plus souvent dans un
         message, et l'organisateur doit pouvoir le prendre a la main. --}}
    @if ($lastCreated)
        <div role="status"
             class="rounded-[var(--radius-lg)] border border-success/30 bg-success/5 p-4">
            <div class="flex items-start gap-3">
                <x-lucide-link class="mt-0.5 size-5 shrink-0 text-success" stroke-width="2" aria-hidden="true" />

                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-ink">
                        {{ count($lastCreated) }} lien{{ count($lastCreated) > 1 ? 's' : '' }} a transmettre
                    </p>
                    <p class="mt-1 text-sm text-grey-500">
                        Envoyez chaque lien a son invite. Ils restent disponibles dans la
                        liste ci-dessous.
                    </p>

                    <ul class="mt-3 space-y-2">
                        @foreach ($lastCreated as $created)
                            <li class="rounded-[var(--radius-sm)] bg-surface px-3 py-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm font-semibold text-ink">{{ $created['name'] }}</p>
                                    @if ($created['emailed'])
                                        <span class="inline-flex items-center gap-1 text-xs font-semibold text-success">
                                            <x-lucide-send class="size-3.5 shrink-0" stroke-width="2" aria-hidden="true" />
                                            Envoye par email
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-0.5 truncate text-xs text-grey-500">{{ $created['url'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <button type="button" wire:click="dismissCreated"
                        class="btn btn-icon btn-icon-sm -mt-1 -mr-1 shrink-0">
                    <x-lucide-x class="size-4" stroke-width="2" aria-hidden="true" />
                    <span class="sr-only">Masquer les liens</span>
                </button>
            </div>
        </div>
    @endif

    <section class="grid gap-3 sm:grid-cols-3" aria-label="Etat des invitations">
        <x-ui.stat-card icon="armchair" label="Places retenues" tone="warning"
                        :value="Money::number($counts['held'])"
                        hint="Indisponibles a la vente" />
        <x-ui.stat-card icon="check" label="Acceptees" tone="success"
                        :value="Money::number($counts['accepted'])"
                        hint="Billet emis" />
        <x-ui.stat-card icon="hourglass" label="En attente"
                        :value="Money::number($counts['open'])"
                        hint="Sans reponse pour l'instant" />
    </section>

    {{-- ----------------------------------------------------------------
         Creation
    ----------------------------------------------------------------- --}}
    @if ($ticketTypes->isEmpty())
        <x-ui.panel>
            <x-ui.empty-state
                icon="ticket"
                title="Aucun type de billet"
                description="Creez d'abord un type de billet : une invitation offre une place sur un tarif precis.">
                <x-slot:action>
                    <a href="{{ route('events.ticket-types', $event) }}" wire:navigate class="btn btn-primary">
                        Creer un type de billet
                    </a>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @elseif ($showForm)
        <x-ui.panel title="Inviter"
                    description="Un invite par ligne. « Nom », ou « Nom, adresse email » pour lui envoyer son lien puis son billet.">
            <form wire:submit="create" class="space-y-1">
                <div class="grid gap-x-4 sm:grid-cols-2">
                    <x-ui.field label="Type de billet" for="ticketTypeId" required
                                :error="$errors->first('ticketTypeId')">
                        <select id="ticketTypeId" wire:model="ticketTypeId" class="field"
                                @error('ticketTypeId') aria-invalid="true" aria-describedby="ticketTypeId-error" @enderror>
                            <option value="">Choisir</option>
                            @foreach ($ticketTypes as $type)
                                <option value="{{ $type->id }}">
                                    {{ $type->name }} — {{ $available[$type->id] }} place{{ $available[$type->id] > 1 ? 's' : '' }} disponible{{ $available[$type->id] > 1 ? 's' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    <x-ui.field label="A confirmer avant" for="expiresAt"
                                hint="Passe cette date, la place repart a la vente."
                                :error="$errors->first('expiresAt')">
                        <input id="expiresAt" type="datetime-local" wire:model="expiresAt" class="field"
                               @error('expiresAt') aria-invalid="true" aria-describedby="expiresAt-error" @enderror>
                    </x-ui.field>
                </div>

                <x-ui.field label="Invites" for="guests" required :error="$errors->first('guests')">
                    <textarea id="guests" wire:model="guests" rows="5" class="field"
                              placeholder="Marie Nzeng&#10;Paul Obame, paul@exemple.ga"
                              @error('guests') aria-invalid="true" aria-describedby="guests-error" @enderror></textarea>
                </x-ui.field>

                {{-- Les invites sans adresse restent a transmettre a la main :
                     le dire ici evite de croire que tout le monde a recu
                     quelque chose. --}}
                <label class="glass-inset flex min-h-11 cursor-pointer items-start gap-2.5 p-3 text-sm text-ink">
                    <input type="checkbox" wire:model="notifyByEmail"
                           class="mt-0.5 size-4 rounded border-grey-200 text-azure-600">
                    <span>
                        Envoyer le lien par email
                        <span class="mt-0.5 block text-xs text-grey-500">
                            Uniquement aux invites dont vous avez indique l'adresse.
                            Les autres gardent leur lien a copier.
                        </span>
                    </span>
                </label>

                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row-reverse sm:justify-start">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">
                        <span wire:loading.remove wire:target="create">Creer les invitations</span>
                        <span wire:loading wire:target="create">Creation…</span>
                    </button>
                    <button type="button" wire:click="$set('showForm', false)" class="btn btn-secondary">
                        Annuler
                    </button>
                </div>
            </form>
        </x-ui.panel>
    @endif

    {{-- ----------------------------------------------------------------
         Liste
    ----------------------------------------------------------------- --}}
    <x-ui.panel :title="$invitations->count() . ' invitation' . ($invitations->count() > 1 ? 's' : '')">
        <div class="space-y-2">
            @forelse ($invitations as $invitation)
                @php $state = $invitation->displayStatus(); @endphp

                <div wire:key="inv-{{ $invitation->id }}"
                     class="rounded-[var(--radius-md)] border border-grey-200 bg-surface p-3.5 sm:p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">{{ $invitation->guest_name }}</p>
                            <p class="mt-0.5 text-sm text-grey-500">
                                {{ $invitation->order?->items->first()?->ticket_type_name }}
                                @if ($invitation->guest_email)
                                    · {{ $invitation->guest_email }}
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-grey-400">
                                @if ($state === 'USED')
                                    Entree effectuee
                                @elseif ($invitation->status === InvitationStatus::Accepted)
                                    Acceptee le {{ $invitation->accepted_at?->timezone($tz)->translatedFormat('j F \a\ H\hi') }}
                                @else
                                    Limite : {{ $invitation->expires_at?->timezone($tz)->translatedFormat('j F \a\ H\hi') }}
                                    @if ($invitation->sent_at)
                                        · Envoyee le {{ $invitation->sent_at->timezone($tz)->translatedFormat('j F \a\ H\hi') }}
                                    @endif
                                @endif
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :tone="match (true) {
                                in_array($state, ['ACCEPTED', 'USED'], true) => 'success',
                                in_array($state, ['CREATED', 'SENT'], true) => 'info',
                                default => 'neutral',
                            }">{{ $invitation->displayLabel() }}</x-ui.badge>

                            @if ($invitation->isOpen())
                                @if ($invitation->guest_email)
                                    {{-- « Je n'ai rien recu » est la phrase que tout
                                         organisateur entend : le renvoi y repond sans
                                         rien recreer. --}}
                                    <button type="button" wire:click="sendEmail({{ $invitation->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="sendEmail({{ $invitation->id }})"
                                            class="btn btn-secondary btn-sm">
                                        <x-lucide-send class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                                        {{ $invitation->sent_at ? 'Renvoyer' : 'Envoyer par email' }}
                                    </button>
                                @elseif ($invitation->status === InvitationStatus::Created)
                                    <button type="button" wire:click="markSent({{ $invitation->id }})"
                                            class="btn btn-secondary btn-sm">
                                        Marquer envoyee
                                    </button>
                                @endif

                                <button type="button" wire:click="cancel({{ $invitation->id }})"
                                        wire:confirm="Annuler cette invitation ? La place repart a la vente."
                                        class="btn btn-danger btn-sm">
                                    Annuler
                                </button>
                            @endif
                        </div>
                    </div>

                    @if ($invitation->isOpen())
                        {{-- Le lien reste lisible tant que l'invitation est ouverte :
                             c'est ce que l'organisateur vient rechercher quand un
                             invite dit ne pas l'avoir recu. --}}
                        <p class="mt-3 truncate rounded-[var(--radius-xs)] bg-surface-muted px-3 py-2 text-xs text-grey-500">
                            {{ $invitation->publicUrl() }}
                        </p>
                    @endif
                </div>
            @empty
                <x-ui.empty-state
                    icon="mail"
                    title="Aucune invitation"
                    description="Les places offertes apparaitront ici, avec leur lien a transmettre." />
            @endforelse
        </div>
    </x-ui.panel>
</div>
