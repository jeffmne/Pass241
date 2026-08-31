@php
    use App\Support\Money;

    $tz = config('app.display_timezone');
    $open = $poll->acceptsBallots();
@endphp

{{--
    Page publique d'un scrutin (section 86.4).

    Mobile d'abord : c'est un lien partage sur WhatsApp, ouvert au doigt, le
    plus souvent sur une connexion mesuree.
--}}
<div class="mx-auto w-full max-w-3xl px-5 py-8 sm:px-6 sm:py-12">

    {{-- En-tete --}}
    <header class="text-center">
        @if ($poll->bannerUrl())
            <img src="{{ $poll->bannerUrl() }}" alt=""
                 class="mb-6 aspect-[16/9] w-full rounded-[var(--radius-xl)] object-cover">
        @endif

        <h1 class="font-display text-3xl leading-tight font-extrabold text-ink sm:text-4xl">
            {{ $poll->name }}
        </h1>

        <p class="mt-3 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-sm text-grey-500">
            <span>{{ $poll->organization->name }}</span>
            <span aria-hidden="true">·</span>
            <span>
                Jusqu'au {{ $poll->ends_at->timezone($tz)->translatedFormat('j F Y \a H\hi') }}
            </span>
        </p>

        @if ($poll->description)
            <p class="mx-auto mt-5 max-w-xl leading-relaxed text-grey-500">{{ $poll->description }}</p>
        @endif
    </header>

    {{-- Etat du scrutin, quand il n'est pas simplement ouvert --}}
    @unless ($open)
        <div role="status"
             class="mt-8 flex items-start gap-2.5 rounded-[var(--radius-md)] border border-grey-200 bg-surface-muted px-4 py-3 text-sm text-ink">
            <x-lucide-info class="mt-0.5 size-4 shrink-0 text-grey-400" stroke-width="2" aria-hidden="true" />
            <span>
                @if ($poll->displayStatus() === App\Enums\PollStatus::Cancelled)
                    Ce vote a ete annule.
                    @if ($poll->cancellation_reason)
                        Motif : {{ $poll->cancellation_reason }}
                    @endif
                @elseif ($poll->hasLapsed())
                    Les votes sont termines.
                @else
                    Les votes ouvrent le
                    {{ $poll->starts_at->timezone($tz)->translatedFormat('j F Y \a H\hi') }}.
                @endif
            </span>
        </div>
    @endunless

    @if ($confirmation)
        <div role="status"
             class="mt-8 flex items-start gap-2.5 rounded-[var(--radius-md)] border border-success/30 bg-success/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-check class="mt-0.5 size-4 shrink-0 text-success" stroke-width="2" aria-hidden="true" />
            <span>{{ $confirmation }}</span>
        </div>
    @endif

    @error('vote')
        <div role="alert"
             class="mt-8 flex items-start gap-2.5 rounded-[var(--radius-md)] border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-ink">
            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-danger" stroke-width="2" aria-hidden="true" />
            <span>{{ $message }}</span>
        </div>
    @enderror

    {{-- ---------------------------------------------------------------
         Les categories
    ---------------------------------------------------------------- --}}
    <div class="mt-10 space-y-10">
        @forelse ($categories as $category)
            @php
                /*
                 * `$categoryTotals` et NON `$totals` : reaffecter `$totals` ici
                 * ecrasait, des la premiere categorie, la collection indexee que
                 * la boucle doit relire a chaque tour. Le second passage lisait
                 * alors une cle de categorie dans un tableau de compteurs, et la
                 * page entiere repondait 500.
                 *
                 * Le defaut ne se voyait qu'a partir de DEUX categories — un
                 * scrutin en a presque toujours plusieurs.
                 */
                $categoryTotals = $totals[$category->id];
                $ranking = $standings[$category->id] ?? null;
                $closed = $category->closureReason();
            @endphp

            <section wire:key="category-{{ $category->id }}" class="glass glass-panel p-5 sm:p-7">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 class="font-display text-xl font-extrabold text-ink sm:text-2xl">{{ $category->name }}</h2>

                    <p class="text-sm text-grey-500 tabular">
                        {{ $categoryTotals['votes'] }} {{ $categoryTotals['votes'] > 1 ? 'votes' : 'vote' }}
                    </p>
                </div>

                @if ($category->description)
                    <p class="mt-2 text-sm leading-relaxed text-grey-500">{{ $category->description }}</p>
                @endif

                <p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-grey-500">
                    @if ($category->is_paid)
                        <span class="font-semibold text-azure-700 tabular">
                            {{ Money::formatShort($category->unit_price) }} le vote
                        </span>
                    @else
                        <span class="font-semibold text-azure-700">Vote gratuit</span>
                    @endif

                    @if ($category->voteLimit())
                        <span aria-hidden="true">·</span>
                        <span>
                            {{ $category->voteLimit() }}
                            vote{{ $category->voteLimit() > 1 ? 's' : '' }} par personne
                        </span>
                    @endif
                </p>

                {{--
                    LE CARACTERE INDICATIF DU VOTE GRATUIT, dit en clair
                    (decision D-047).

                    Sans compte utilisateur ni verification par SMS, rien
                    n'empeche quelqu'un de saisir plusieurs numeros. Le taire
                    laisserait croire a une garantie que le produit ne tient
                    pas — et un concours dont le resultat est conteste apres
                    coup se retourne contre l'organisateur autant que contre
                    nous.
                --}}
                @unless ($category->is_paid)
                    <p class="mt-3 flex items-start gap-2 rounded-[var(--radius-sm)] bg-surface-muted px-3 py-2 text-xs leading-relaxed text-grey-500">
                        <x-lucide-info class="mt-0.5 size-3.5 shrink-0 text-grey-400" stroke-width="2" aria-hidden="true" />
                        <span>
                            Vote gratuit : le resultat de cette categorie est indicatif. Nous limitons
                            les votes multiples, sans pouvoir les empecher totalement.
                        </span>
                    </p>
                @endunless

                @if ($closed && $open)
                    <p class="mt-4 text-sm text-grey-500">{{ $closed }}</p>
                @endif

                {{-- Les candidats --}}
                <ul class="mt-6 space-y-3">
                    @foreach ($category->candidates as $candidate)
                        @php
                            $row = $ranking?->firstWhere('candidate_id', $candidate->id);
                        @endphp

                        <li wire:key="candidate-{{ $candidate->id }}"
                            class="glass-inset flex flex-wrap items-center gap-4 p-4">

                            @if ($candidate->photoUrl())
                                <img src="{{ $candidate->photoUrl() }}" alt=""
                                     class="size-14 shrink-0 rounded-full object-cover">
                            @else
                                <span class="flex size-14 shrink-0 items-center justify-center rounded-full bg-azure-100 text-azure-700">
                                    <x-lucide-user class="size-6" stroke-width="1.75" aria-hidden="true" />
                                </span>
                            @endif

                            <div class="min-w-0 flex-1">
                                <p class="font-display text-base font-bold text-ink">
                                    {{ $candidate->displayName() }}
                                </p>

                                @if ($candidate->biography)
                                    <p class="mt-0.5 text-sm leading-relaxed text-grey-500">
                                        {{ $candidate->biography }}
                                    </p>
                                @endif

                                {{-- Le classement, seulement si l'organisateur
                                     l'a ouvert au public. --}}
                                @if ($row)
                                    <div class="mt-2.5">
                                        <div class="progress-track">
                                            <div class="progress-fill" style="--progress: {{ $row['share'] }}%"></div>
                                        </div>
                                        <p class="mt-1.5 text-sm text-grey-500 tabular">
                                            <span class="font-display font-bold text-ink">{{ $row['votes'] }}</span>
                                            {{ $row['votes'] > 1 ? 'votes' : 'vote' }}
                                            · {{ Money::number($row['share']) }} %
                                        </p>
                                    </div>
                                @endif

                                @unless ($candidate->is_active)
                                    <p class="mt-1.5 text-xs text-grey-400">
                                        Ce candidat ne recoit plus de vote. Ses voix restent comptees.
                                    </p>
                                @endunless
                            </div>

                            @if ($candidate->acceptsBallots())
                                <button type="button"
                                        wire:click="choose({{ $category->id }}, {{ $candidate->id }})"
                                        class="btn btn-primary shrink-0">
                                    Voter
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>

                {{-- Formulaire de vote, ouvert sous SA categorie --}}
                @if ($votingCategory === $category->id && $selectedCandidate)
                    @php
                        $chosen = $category->candidates->firstWhere('id', $selectedCandidate);
                    @endphp

                    <form wire:submit="vote"
                          class="mt-5 space-y-4 rounded-[var(--radius-md)] border border-azure-300 p-4 sm:p-5">
                        <p class="text-sm text-grey-500">
                            Vous votez pour
                            <span class="font-semibold text-ink">{{ $chosen?->name }}</span>.
                        </p>

                        @if ($requiresPhone[$category->id])
                            <x-ui.field label="Votre numero" for="vote-phone-{{ $category->id }}"
                                        :error="$errors->first('phone')"
                                        hint="Il sert uniquement a compter votre vote une seule fois. Il n'est pas conserve en clair."
                                        required>
                                <input id="vote-phone-{{ $category->id }}" type="tel" inputmode="tel"
                                       wire:model="phone" class="field" placeholder="077 12 34 56"
                                       maxlength="20" required>
                            </x-ui.field>
                        @endif

                        @if (($category->voteLimit() ?? 1) > 1)
                            <x-ui.field label="Nombre de votes" for="vote-qty-{{ $category->id }}"
                                        :error="$errors->first('quantity')">
                                <input id="vote-qty-{{ $category->id }}" type="number" wire:model="quantity"
                                       class="field" min="1" max="{{ $category->voteLimit() }}" step="1">
                            </x-ui.field>
                        @endif

                        <div class="flex flex-wrap gap-2.5">
                            <button type="submit" class="btn btn-primary"
                                    wire:loading.attr="disabled" wire:target="vote">
                                Confirmer mon vote
                            </button>
                            <button type="button" wire:click="cancelVote" class="btn btn-secondary">
                                Annuler
                            </button>
                        </div>
                    </form>
                @endif
            </section>
        @empty
            <p class="text-center text-grey-500">
                Ce vote n'a pas encore de categorie ouverte.
            </p>
        @endforelse
    </div>

    <p class="mt-12 text-center text-sm text-grey-400">
        Vote organise par {{ $poll->organization->name }} sur Pass241.
    </p>
</div>
