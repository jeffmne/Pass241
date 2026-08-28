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
<div class="space-y-5">

    <x-ui.page-header
        :title="$organization->name"
        :description="$organization->type?->label() . ' · creee le '
            . $organization->created_at?->timezone($tz)->translatedFormat('j F Y')"
        :back="route('admin.organizations')"
        back-label="Organisations">

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                @if ($suspended)
                    <x-ui.badge tone="danger">Suspendue</x-ui.badge>
                @else
                    <x-ui.badge tone="success">Active</x-ui.badge>
                @endif

                <x-ui.badge :tone="match ($organization->kyc_status) {
                    KycStatus::Verified => 'success',
                    KycStatus::Rejected => 'danger',
                    default => 'neutral',
                }">KYC : {{ $organization->kyc_status->label() }}</x-ui.badge>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Suspension en cours : le motif passe AVANT tout le reste. C'est la
         premiere chose qu'on cherche quand l'organisateur telephone. --}}
    @if ($suspended && $organization->suspension_reason)
        <div role="alert"
             class="flex items-start gap-3 rounded-[var(--radius-lg)] border border-danger/30 bg-danger/5 p-4">
            <x-lucide-circle-alert class="mt-0.5 size-5 shrink-0 text-danger" stroke-width="2" aria-hidden="true" />
            <div class="text-sm">
                <p class="font-semibold text-ink">
                    Compte suspendu le
                    {{ $organization->suspended_at?->timezone($tz)->translatedFormat('j F Y \a\ H\hi') }}
                </p>
                <p class="mt-1 leading-relaxed text-grey-500">{{ $organization->suspension_reason }}</p>
            </div>
        </div>
    @endif

    {{-- Chiffres --}}
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Chiffres de l'organisation">
        <x-ui.stat-card icon="ticket" label="Billets vendus"
                        data-reveal data-reveal-step="0"
                        :value="Money::number($sales['tickets'])"
                        :hint="$eventCount . ' evenement' . ($eventCount > 1 ? 's' : '')" />

        <x-ui.stat-card icon="banknote" label="Volume brut" tone="neutral"
                        data-reveal data-reveal-step="1"
                        :value="Money::format($sales['gross'])"
                        hint="Transite, sans appartenir a Pass241" />

        <x-ui.stat-card icon="coins" label="Commission Pass241"
                        data-reveal data-reveal-step="2"
                        :value="Money::format($sales['commission'])"
                        hint="Nette des remboursements" />

        <x-ui.stat-card icon="wallet" label="Deja reverse" tone="success"
                        data-reveal data-reveal-step="3"
                        :value="Money::format($settledTotal)"
                        :hint="'Rembourse : ' . Money::format($sales['refunded'])" />
    </section>

    <div class="grid gap-3 lg:grid-cols-2">
        {{-- ------------------------------------------------------------
             Identite et reversement
        ------------------------------------------------------------- --}}
        <x-ui.panel title="Le compte" data-reveal>
            <dl class="space-y-2.5 text-sm">
                @foreach ([
                    'Proprietaire' => $organization->owner?->name ?? '—',
                    'Email de contact' => $organization->contact_email ?? '—',
                    'Telephone' => $organization->contact_phone ?? '—',
                    'Membres' => $members->count(),
                    'Lien public' => '/' . $organization->slug,
                ] as $label => $value)
                    <div class="flex justify-between gap-4">
                        <dt class="text-grey-500">{{ $label }}</dt>
                        <dd class="text-right font-semibold break-all text-ink">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="glass-inset mt-5 px-4 py-3 text-sm">
                <p class="font-semibold text-ink">Destination de reversement</p>
                @if ($organization->hasPayoutDestination())
                    {{-- Numero masque (section 52) : le Super Admin doit pouvoir
                         verifier qu'une destination existe, pas la recopier. --}}
                    <p class="mt-1 text-grey-500 tabular">
                        {{ $organization->payout_operator?->label() }} —
                        {{ str_repeat('*', max(0, mb_strlen((string) $organization->payout_phone) - 4)) }}{{ mb_substr((string) $organization->payout_phone, -4) }}
                    </p>
                    @if ($organization->payout_name)
                        <p class="text-grey-500">{{ $organization->payout_name }}</p>
                    @endif
                @else
                    <p class="mt-1 font-medium text-warning">
                        Aucune : aucun reversement ne peut partir.
                    </p>
                @endif
            </div>
        </x-ui.panel>

        {{-- ------------------------------------------------------------
             Decision (sections 10, 58)
        ------------------------------------------------------------- --}}
        <x-ui.panel
            :title="$suspended ? 'Reactiver le compte' : 'Suspendre le compte'"
            :description="$suspended
                ? 'La reactivation rend l\'acces a tous les membres et rouvre les ventes des evenements publies.'
                : 'La suspension coupe l\'acces de tous les membres et ferme les ventes en cours. Elle ne touche a aucune donnee financiere : billets vendus, commandes payees et dons confirmes restent intacts, et les reversements deja programmes partiront.'"
            data-reveal data-reveal-step="1">

            @unless ($confirming)
                <button type="button" wire:click="startDecision"
                        @class(['btn', 'btn-primary' => $suspended, 'btn-danger' => ! $suspended])>
                    {{ $suspended ? 'Reactiver' : 'Suspendre' }}
                </button>
            @else
                <div class="glass-inset p-4">
                    <x-ui.field label="Motif" for="reason" required
                                hint="Conserve et journalise avec votre nom. Il reste lisible apres reactivation."
                                :error="$errors->first('reason')">
                        <textarea id="reason" wire:model="reason" rows="3" class="field"
                                  placeholder="{{ $suspended ? 'Verification terminee, compte conforme.' : 'Signalements repetes, compte gele le temps de la verification.' }}"
                                  @error('reason') aria-invalid="true" aria-describedby="reason-error" @enderror></textarea>
                    </x-ui.field>

                    {{-- Rouge PLEIN a la confirmation : il n'y a plus d'autre
                         choix a faire, c'est le point de non-retour. --}}
                    <div class="mt-2 flex flex-col-reverse gap-2 sm:flex-row-reverse sm:justify-start">
                        <button type="button" wire:click="decide"
                                @class(['btn', 'btn-primary' => $suspended, 'btn-danger-solid' => ! $suspended])>
                            Confirmer
                        </button>
                        <button type="button" wire:click="cancelDecision" class="btn btn-secondary">
                            Revenir
                        </button>
                    </div>
                </div>
            @endunless
        </x-ui.panel>
    </div>

    {{-- ----------------------------------------------------------------
         Evenements et collectes
    ----------------------------------------------------------------- --}}
    <div class="grid gap-3 lg:grid-cols-2">
        <x-ui.panel title="Evenements" data-reveal>
            <div class="space-y-2">
                @forelse ($events as $event)
                    <div class="flex items-center justify-between gap-3 rounded-[var(--radius-sm)] border border-grey-200 bg-surface p-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-ink">{{ $event->name }}</p>
                            <p class="text-xs text-grey-400 tabular">
                                {{ $event->starts_at?->timezone($tz)->translatedFormat('j F Y') }}
                            </p>
                        </div>
                        <x-status-badge :status="$event->status" />
                    </div>
                @empty
                    <x-ui.empty-state icon="calendar-days" title="Aucun evenement"
                                      description="Cette organisation n'a encore rien cree." />
                @endforelse
            </div>
        </x-ui.panel>

        <x-ui.panel
            title="Collectes"
            :description="$fundraising['donations'] . ' don' . ($fundraising['donations'] > 1 ? 's' : '')
                . ' · ' . Money::format($fundraising['collected']) . ' collectes'"
            data-reveal data-reveal-step="1">
            <div class="space-y-2">
                @forelse ($campaigns as $campaign)
                    <div class="flex items-center justify-between gap-3 rounded-[var(--radius-sm)] border border-grey-200 bg-surface p-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-ink">{{ $campaign->name }}</p>
                            <p class="truncate text-xs text-grey-400">{{ $campaign->beneficiary_label }}</p>
                        </div>
                        <x-status-badge :status="$campaign->displayStatus()" />
                    </div>
                @empty
                    <x-ui.empty-state icon="hand-coins" title="Aucune collecte"
                                      description="Cette organisation n'a encore lance aucune collecte." />
                @endforelse
            </div>
        </x-ui.panel>
    </div>

    {{-- ----------------------------------------------------------------
         Reversements et activite
    ----------------------------------------------------------------- --}}
    <div class="grid gap-3 lg:grid-cols-2">
        <x-ui.panel title="Reversements" data-reveal>
            <div class="space-y-2">
                @forelse ($settlements as $settlement)
                    <div class="flex items-center justify-between gap-3 rounded-[var(--radius-sm)] border border-grey-200 bg-surface p-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-ink tabular">{{ $settlement->reference }}</p>
                            <p class="truncate text-xs text-grey-400">
                                {{ $settlement->settlableName() ?? '—' }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <p class="font-display font-bold text-ink tabular">{{ $settlement->formattedNet() }}</p>
                            <x-settlement-state :status="$settlement->status" />
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state icon="wallet" title="Aucun reversement"
                                      description="Les reversements apparaissent apres chaque evenement termine." />
                @endforelse
            </div>
        </x-ui.panel>

        <x-ui.panel title="Activite recente" data-reveal data-reveal-step="1">
            <div class="space-y-2">
                @forelse ($activity as $entry)
                    <div class="rounded-[var(--radius-sm)] border border-grey-200 bg-surface p-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="truncate text-sm font-semibold text-ink">{{ $entry->action->label() }}</p>
                            <p class="shrink-0 text-xs text-grey-400 tabular">
                                {{ $entry->created_at?->timezone($tz)->translatedFormat('j F H\hi') }}
                            </p>
                        </div>
                        <p class="mt-0.5 truncate text-xs text-grey-400">
                            {{ $entry->user?->name ?? 'Systeme' }}
                        </p>
                    </div>
                @empty
                    <x-ui.empty-state icon="scroll-text" title="Aucune activite"
                                      description="Le journal se remplit des la premiere action sur ce compte." />
                @endforelse
            </div>
        </x-ui.panel>
    </div>
</div>
