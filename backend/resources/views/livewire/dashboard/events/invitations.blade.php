@php
    use App\Enums\InvitationStatus;

    $tz = config('app.display_timezone');
    $nbsp = "\u{202F}";
@endphp

{{--
    Invitations d'un evenement (section 25).

    Une invitation RETIENT UNE PLACE des sa creation. C'est la seule chose que
    l'organisateur doit comprendre avant d'inviter cinquante personnes sur un
    evenement qui en contient deux cents — l'ecran le dit donc en chiffres, pas
    en avertissement.

    Surfaces pleines : liste dense, pas de verre (regle 70.4).
--}}
<div>
    <header class="mb-6">
        <a href="{{ route('events.edit', $event) }}" wire:navigate
           class="inline-flex items-center gap-1.5 text-sm font-medium text-grey-500 transition-colors hover:text-azure-700">
            <x-lucide-arrow-left class="size-4" stroke-width="1.75" />
            {{ $event->name }}
        </a>

        <h1 class="mt-2 font-display text-2xl font-extrabold text-ink sm:text-3xl">Invitations</h1>
        <p class="mt-1 text-sm text-grey-500">
            Offrez une place. Chaque invitation en retient une jusqu'a sa date limite.
        </p>
    </header>

    {{-- Liens crees a l'instant : affiches une fois, a copier et transmettre.
         L'email part pour ceux qui ont une adresse, mais le lien reste visible
         pour tous : ici, une invitation circule le plus souvent dans un
         message, et l'organisateur doit pouvoir le prendre a la main. --}}
    @if ($lastCreated)
        <div class="mb-6 rounded-2xl border border-success/30 bg-success/5 p-4">
            <div class="flex items-start gap-3">
                <x-lucide-link class="mt-0.5 size-5 shrink-0 text-success" stroke-width="1.75" />
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
                            <li class="rounded-xl bg-white px-3 py-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm font-semibold text-ink">{{ $created['name'] }}</p>
                                    @if ($created['emailed'])
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-success">
                                            <x-lucide-send class="size-3.5" stroke-width="1.75" />
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
                        class="shrink-0 text-grey-400 transition-colors hover:text-ink"
                        aria-label="Masquer les liens">
                    <x-lucide-x class="size-4" stroke-width="2" />
                </button>
            </div>
        </div>
    @endif

    {{-- ----------------------------------------------------------------
         Compteurs
    ----------------------------------------------------------------- --}}
    <div class="grid gap-3 sm:grid-cols-3">
        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-armchair class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Places retenues</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                {{ number_format($counts['held'], 0, ',', $nbsp) }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500">Indisponibles a la vente</p>
        </div>

        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-check class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Acceptees</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                {{ number_format($counts['accepted'], 0, ',', $nbsp) }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500">Billet emis</p>
        </div>

        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-hourglass class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">En attente</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                {{ number_format($counts['open'], 0, ',', $nbsp) }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500">Sans reponse pour l'instant</p>
        </div>
    </div>

    {{-- ----------------------------------------------------------------
         Creation
    ----------------------------------------------------------------- --}}
    <section class="surface mt-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Inviter</h2>
                <p class="mt-1 text-sm text-grey-500">
                    Un invite par ligne. « Nom », ou « Nom, adresse email » pour
                    lui envoyer son lien puis son billet.
                </p>
            </div>

            @unless ($showForm)
                <button type="button" wire:click="$set('showForm', true)" class="btn btn-ghost btn-pill"
                        @disabled($ticketTypes->isEmpty())>
                    <x-lucide-plus class="size-4" stroke-width="2" />
                    Nouvelles invitations
                </button>
            @endunless
        </div>

        @if ($ticketTypes->isEmpty())
            <p class="mt-4 text-sm text-grey-500">
                Creez d'abord un type de billet : une invitation offre une place sur
                un tarif precis.
            </p>
        @elseif ($showForm)
            <form wire:submit="create" class="mt-5 rounded-2xl border border-azure-100 bg-azure-50 p-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="ticketTypeId" class="mb-1.5 block text-sm font-medium text-ink">
                            Type de billet
                        </label>
                        <select id="ticketTypeId" wire:model="ticketTypeId" class="field">
                            <option value="">Choisir</option>
                            @foreach ($ticketTypes as $type)
                                <option value="{{ $type->id }}">
                                    {{ $type->name }} — {{ $available[$type->id] }} place{{ $available[$type->id] > 1 ? 's' : '' }} disponible{{ $available[$type->id] > 1 ? 's' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('ticketTypeId')
                            <p class="mt-1.5 text-sm text-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="expiresAt" class="mb-1.5 block text-sm font-medium text-ink">
                            A confirmer avant
                        </label>
                        <input id="expiresAt" type="datetime-local" wire:model="expiresAt" class="field">
                        <p class="mt-1.5 text-xs text-grey-500">
                            Passe cette date, la place repart a la vente.
                        </p>
                        @error('expiresAt')
                            <p class="mt-1.5 text-sm text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mt-4">
                    <label for="guests" class="mb-1.5 block text-sm font-medium text-ink">Invites</label>
                    <textarea id="guests" wire:model="guests" rows="5" class="field"
                              placeholder="Marie Nzeng&#10;Paul Obame, paul@exemple.ga"></textarea>
                    @error('guests')
                        <p class="mt-1.5 text-sm text-danger">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Les invites sans adresse restent a transmettre a la main :
                     le dire ici evite de croire que tout le monde a recu
                     quelque chose. --}}
                <label class="mt-4 flex min-h-11 items-start gap-2.5 text-sm text-ink">
                    <input type="checkbox" wire:model="notifyByEmail"
                           class="mt-0.5 size-4 rounded border-grey-200">
                    <span>
                        Envoyer le lien par email
                        <span class="mt-0.5 block text-xs text-grey-500">
                            Uniquement aux invites dont vous avez indique l'adresse.
                            Les autres gardent leur lien a copier.
                        </span>
                    </span>
                </label>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-pill">Creer les invitations</button>
                    <button type="button" wire:click="$set('showForm', false)" class="btn btn-ghost btn-pill">
                        Annuler
                    </button>
                </div>
            </form>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Liste
    ----------------------------------------------------------------- --}}
    <section class="surface mt-6 p-4 sm:p-6">
        <h2 class="font-display text-lg font-bold text-ink">
            {{ $invitations->count() }} invitation{{ $invitations->count() > 1 ? 's' : '' }}
        </h2>

        <div class="mt-4 space-y-2">
            @forelse ($invitations as $invitation)
                @php
                    $state = $invitation->displayStatus();
                @endphp

                <div class="rounded-2xl border border-grey-200 bg-white p-3.5 sm:p-4">
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
                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                'bg-success/10 text-success' => in_array($state, ['ACCEPTED', 'USED'], true),
                                'bg-azure-100 text-azure-700' => in_array($state, ['CREATED', 'SENT'], true),
                                'bg-grey-200 text-grey-500' => in_array($state, ['EXPIRED', 'CANCELLED'], true),
                            ])>
                                {{ $invitation->displayLabel() }}
                            </span>

                            @if ($invitation->isOpen())
                                @if ($invitation->guest_email)
                                    {{-- « Je n'ai rien recu » est la phrase que tout
                                         organisateur entend : le renvoi y repond sans
                                         rien recreer. --}}
                                    <button type="button" wire:click="sendEmail({{ $invitation->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="sendEmail({{ $invitation->id }})"
                                            class="btn btn-ghost btn-pill text-sm">
                                        <x-lucide-send class="size-4" stroke-width="1.75" />
                                        {{ $invitation->sent_at ? 'Renvoyer' : 'Envoyer par email' }}
                                    </button>
                                @elseif ($invitation->status === InvitationStatus::Created)
                                    <button type="button" wire:click="markSent({{ $invitation->id }})"
                                            class="btn btn-ghost btn-pill text-sm">
                                        Marquer envoyee
                                    </button>
                                @endif

                                <button type="button" wire:click="cancel({{ $invitation->id }})"
                                        class="text-sm font-medium text-danger transition-opacity hover:opacity-70">
                                    Annuler
                                </button>
                            @endif
                        </div>
                    </div>

                    @if ($invitation->isOpen())
                        {{-- Le lien reste lisible tant que l'invitation est ouverte :
                             c'est ce que l'organisateur vient rechercher quand un
                             invite dit ne pas l'avoir recu. --}}
                        <p class="mt-3 truncate rounded-xl bg-canvas px-3 py-2 text-xs text-grey-500">
                            {{ $invitation->publicUrl() }}
                        </p>
                    @endif
                </div>
            @empty
                <div class="py-10 text-center">
                    <x-lucide-mail class="mx-auto size-8 text-grey-300" stroke-width="1.5" />
                    <p class="mt-3 font-semibold text-ink">Aucune invitation</p>
                    <p class="mx-auto mt-1 max-w-sm text-sm text-grey-500">
                        Les places offertes apparaitront ici, avec leur lien a transmettre.
                    </p>
                </div>
            @endforelse
        </div>
    </section>
</div>
