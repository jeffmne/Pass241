@php
    use App\Enums\KycStatus;
    use App\Support\Money;

    $tz = config('app.display_timezone');
    $suspended = $organization->isSuspended();
@endphp

{{--
    Fiche d'une organisation (section 58).

    Tout ce qu'il faut pour repondre a un appel : qui est ce compte, ou en est
    sa verification, ce qu'il a vendu, ce qu'on lui a verse, ce qu'il a fait.

    UNE SEULE ACTION : suspendre, ou lever la suspension. Aucune donnee
    financiere n'est modifiable ici — les declencheurs en base l'interdisent,
    y compris au Super Admin.
--}}
<div>
    <header class="mb-6">
        <a href="{{ route('admin.organizations') }}" wire:navigate
           class="inline-flex items-center gap-1.5 text-sm font-medium text-grey-500 transition-colors hover:text-azure-700">
            <x-lucide-arrow-left class="size-4" stroke-width="1.75" />
            Organisations
        </a>

        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="font-display text-2xl font-extrabold text-ink sm:text-3xl">{{ $organization->name }}</h1>

            @if ($suspended)
                <span class="rounded-full bg-danger/10 px-3 py-1 text-xs font-semibold text-danger">Suspendue</span>
            @else
                <span class="rounded-full bg-success/10 px-3 py-1 text-xs font-semibold text-success">Active</span>
            @endif

            <span @class([
                'rounded-full px-3 py-1 text-xs font-semibold',
                'bg-success/10 text-success' => $organization->kyc_status === KycStatus::Verified,
                'bg-danger/10 text-danger' => $organization->kyc_status === KycStatus::Rejected,
                'bg-grey-200/70 text-grey-500' => ! in_array($organization->kyc_status, [KycStatus::Verified, KycStatus::Rejected], true),
            ])>
                KYC : {{ $organization->kyc_status->label() }}
            </span>
        </div>

        <p class="mt-1 text-sm text-grey-500">
            {{ $organization->type?->label() }} · creee le
            {{ $organization->created_at?->timezone($tz)->translatedFormat('j F Y') }}
        </p>
    </header>

    {{-- Suspension en cours : le motif passe AVANT tout le reste. C'est la
         premiere chose qu'on cherche quand l'organisateur telephone. --}}
    @if ($suspended && $organization->suspension_reason)
        <div class="mb-6 flex items-start gap-3 rounded-2xl border border-danger/30 bg-danger/5 p-4">
            <x-lucide-circle-alert class="mt-0.5 size-5 shrink-0 text-danger" stroke-width="1.75" />
            <div class="text-sm">
                <p class="font-semibold text-ink">
                    Compte suspendu le
                    {{ $organization->suspended_at?->timezone($tz)->translatedFormat('j F Y \a\ H\hi') }}
                </p>
                <p class="mt-1 leading-relaxed text-grey-500">{{ $organization->suspension_reason }}</p>
            </div>
        </div>
    @endif

    {{-- ----------------------------------------------------------------
         Chiffres
    ----------------------------------------------------------------- --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="glass-stat glass-specular p-4" data-reveal data-reveal-step="0">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-ticket class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Billets vendus</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">{{ $sales['tickets'] }}</p>
            <p class="mt-0.5 text-sm text-grey-500">{{ $eventCount }} evenement{{ $eventCount > 1 ? 's' : '' }}</p>
        </div>

        <div class="glass-stat glass-specular p-4" data-reveal data-reveal-step="1">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-banknote class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Volume brut</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">{{ Money::format($sales['gross']) }}</p>
            <p class="mt-0.5 text-sm text-grey-500">Transite, sans appartenir a Pass241</p>
        </div>

        <div class="glass-stat glass-specular p-4" data-reveal data-reveal-step="2">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-coins class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Commission Pass241</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-azure-700 tabular">
                {{ Money::format($sales['commission']) }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500">Nette des remboursements</p>
        </div>

        <div class="glass-stat glass-specular p-4" data-reveal data-reveal-step="3">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-wallet class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Deja reverse</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">{{ Money::format($settledTotal) }}</p>
            <p class="mt-0.5 text-sm text-grey-500">
                Rembourse : {{ Money::format($sales['refunded']) }}
            </p>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        {{-- ------------------------------------------------------------
             Identite et reversement
        ------------------------------------------------------------- --}}
        <section class="surface p-4 sm:p-6" data-reveal>
            <h2 class="font-display text-lg font-bold text-ink">Le compte</h2>

            <dl class="mt-4 space-y-2.5 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-grey-500">Proprietaire</dt>
                    <dd class="text-right font-medium text-ink">{{ $organization->owner?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-grey-500">Email de contact</dt>
                    <dd class="text-right font-medium text-ink">{{ $organization->contact_email ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-grey-500">Telephone</dt>
                    <dd class="text-right font-medium text-ink">{{ $organization->contact_phone ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-grey-500">Membres</dt>
                    <dd class="text-right font-medium text-ink">{{ $members->count() }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-grey-500">Lien public</dt>
                    <dd class="text-right font-medium text-ink">/{{ $organization->slug }}</dd>
                </div>
            </dl>

            <div class="mt-5 rounded-xl bg-canvas px-4 py-3 text-sm">
                <p class="font-semibold text-ink">Destination de reversement</p>
                @if ($organization->hasPayoutDestination())
                    {{-- Numero masque (section 52) : le Super Admin doit pouvoir
                         verifier qu'une destination existe, pas la recopier. --}}
                    <p class="mt-1 text-grey-500">
                        {{ $organization->payout_operator?->label() }} —
                        {{ str_repeat('*', max(0, mb_strlen((string) $organization->payout_phone) - 4)) }}{{ mb_substr((string) $organization->payout_phone, -4) }}
                    </p>
                    @if ($organization->payout_name)
                        <p class="text-grey-500">{{ $organization->payout_name }}</p>
                    @endif
                @else
                    <p class="mt-1 text-warning">
                        Aucune : aucun reversement ne peut partir.
                    </p>
                @endif
            </div>
        </section>

        {{-- ------------------------------------------------------------
             Decision (sections 10, 58)
        ------------------------------------------------------------- --}}
        <section class="surface p-4 sm:p-6" data-reveal data-reveal-step="1">
            <h2 class="font-display text-lg font-bold text-ink">
                {{ $suspended ? 'Reactiver le compte' : 'Suspendre le compte' }}
            </h2>

            <p class="mt-2 text-sm leading-relaxed text-grey-500">
                @if ($suspended)
                    La reactivation rend l'acces a tous les membres et rouvre les ventes
                    des evenements publies.
                @else
                    La suspension coupe l'acces de tous les membres et ferme les ventes en
                    cours. Elle ne touche a aucune donnee financiere : billets vendus,
                    commandes payees et dons confirmes restent intacts, et les reversements
                    deja programmes partiront.
                @endif
            </p>

            @unless ($confirming)
                <button type="button" wire:click="startDecision" @class([
                    'btn btn-pill mt-4',
                    'btn-primary' => $suspended,
                    'bg-danger text-white hover:opacity-90' => ! $suspended,
                ])>
                    {{ $suspended ? 'Reactiver' : 'Suspendre' }}
                </button>
            @else
                <div class="mt-4 rounded-2xl border border-grey-200 bg-canvas p-4">
                    <label for="reason" class="mb-1.5 block text-sm font-medium text-ink">
                        Motif
                    </label>
                    <textarea id="reason" wire:model="reason" rows="3" class="field"
                              placeholder="{{ $suspended ? 'Verification terminee, compte conforme.' : 'Signalements repetes, compte gele le temps de la verification.' }}"></textarea>
                    @error('reason') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror

                    <p class="mt-2 text-xs text-grey-500">
                        Le motif est conserve et journalise avec votre nom. Il reste lisible
                        apres reactivation.
                    </p>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="decide" @class([
                            'btn btn-pill text-sm',
                            'btn-primary' => $suspended,
                            'bg-danger text-white hover:opacity-90' => ! $suspended,
                        ])>
                            Confirmer
                        </button>
                        <button type="button" wire:click="cancelDecision" class="btn btn-ghost btn-pill text-sm">
                            Revenir
                        </button>
                    </div>
                </div>
            @endunless
        </section>
    </div>

    {{-- ----------------------------------------------------------------
         Evenements et collectes
    ----------------------------------------------------------------- --}}
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="surface p-4 sm:p-6" data-reveal>
            <h2 class="font-display text-lg font-bold text-ink">Evenements</h2>

            <div class="mt-4 space-y-2">
                @forelse ($events as $event)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-grey-200 bg-white p-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-ink">{{ $event->name }}</p>
                            <p class="text-xs text-grey-400 tabular">
                                {{ $event->starts_at?->timezone($tz)->translatedFormat('j F Y') }}
                            </p>
                        </div>
                        <x-status-badge :status="$event->status" />
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-grey-500">Aucun evenement.</p>
                @endforelse
            </div>
        </section>

        <section class="surface p-4 sm:p-6" data-reveal data-reveal-step="1">
            <h2 class="font-display text-lg font-bold text-ink">Collectes</h2>
            <p class="mt-1 text-sm text-grey-500 tabular">
                {{ $fundraising['donations'] }} don{{ $fundraising['donations'] > 1 ? 's' : '' }}
                · {{ Money::format($fundraising['collected']) }} collectes
            </p>

            <div class="mt-4 space-y-2">
                @forelse ($campaigns as $campaign)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-grey-200 bg-white p-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-ink">{{ $campaign->name }}</p>
                            <p class="text-xs text-grey-400">{{ $campaign->beneficiary_label }}</p>
                        </div>
                        <x-status-badge :status="$campaign->displayStatus()" />
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-grey-500">Aucune collecte.</p>
                @endforelse
            </div>
        </section>
    </div>

    {{-- ----------------------------------------------------------------
         Reversements et activite
    ----------------------------------------------------------------- --}}
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="surface p-4 sm:p-6" data-reveal>
            <h2 class="font-display text-lg font-bold text-ink">Reversements</h2>

            <div class="mt-4 space-y-2">
                @forelse ($settlements as $settlement)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-grey-200 bg-white p-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-ink tabular">{{ $settlement->reference }}</p>
                            <p class="truncate text-xs text-grey-400">
                                {{ $settlement->settlableName() ?? '—' }}
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="font-semibold text-ink tabular">{{ $settlement->formattedNet() }}</p>
                            <x-settlement-state :status="$settlement->status" />
                        </div>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-grey-500">Aucun reversement.</p>
                @endforelse
            </div>
        </section>

        <section class="surface p-4 sm:p-6" data-reveal data-reveal-step="1">
            <h2 class="font-display text-lg font-bold text-ink">Activite recente</h2>

            <div class="mt-4 space-y-2">
                @forelse ($activity as $entry)
                    <div class="rounded-xl border border-grey-200 bg-white p-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="truncate text-sm font-medium text-ink">{{ $entry->action->label() }}</p>
                            <p class="shrink-0 text-xs text-grey-400 tabular">
                                {{ $entry->created_at?->timezone($tz)->translatedFormat('j F H\hi') }}
                            </p>
                        </div>
                        <p class="mt-0.5 truncate text-xs text-grey-400">
                            {{ $entry->user?->name ?? 'Systeme' }}
                        </p>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-grey-500">Aucune activite.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
