@php
    use App\Enums\PermissionName;
    use App\Support\Money;

    $tz = config('app.display_timezone');
    $money = fn (int $amount) => Money::formatShort($amount);

    /*
     * La serie d'evolution est transposee au format attendu par le composant de
     * graphique, le meme que celui du tableau de bord de billetterie : deux
     * ecrans qui montrent une evolution doivent la montrer de la meme facon
     * (regle 34).
     *
     * Seul le premier et le dernier jour sont etiquetes : trente libelles sous
     * un graphique large de 400px se chevauchent et ne se lisent plus.
     */
    $series = $evolution->values()->map(fn (array $day, int $index) => [
        'label' => $index === 0 || $index === $evolution->count() - 1
            ? $day['date']->translatedFormat('j M')
            : '',
        'value' => $day['amount'],
        'display' => $day['date']->translatedFormat('j F').' — '.$money($day['amount']),
    ])->all();
@endphp

{{--
    Dons recus par une collecte (sections 44 a 48, 55).

    EN LECTURE SEULE pour l'essentiel. Aucun bouton ne modifie un don, et c'est
    volontaire : un don confirme ne se corrige pas, il se rembourse
    (section 47). Le remboursement AJOUTE une operation, il n'en efface aucune.
--}}
<div class="space-y-5">

    <x-ui.page-header
        title="Dons recus"
        description="Calcules a partir des dons confirmes. Rien ici ne se modifie a la main."
        :eyebrow="$campaign->name"
        :back="route('campaigns.edit', $campaign)"
        :back-label="$campaign->name" />

    {{-- Compteurs (section 55) --}}
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Chiffres de la collecte">
        <x-ui.stat-card
            icon="hand-coins"
            label="Collecte"
            tone="success"
            data-reveal data-reveal-step="0"
            :value="$money($stats['collected'])"
            :hint="Money::number($progress['displayPercentage']) . ' % de l\'objectif'" />

        <x-ui.stat-card
            icon="users"
            label="Donateurs"
            data-reveal data-reveal-step="1"
            :value="Money::number($stats['donors'])"
            :hint="Money::number($stats['donations']) . ' don' . ($stats['donations'] > 1 ? 's' : '')" />

        <x-ui.stat-card
            icon="chart-no-axes-column"
            label="Don moyen"
            data-reveal data-reveal-step="2"
            :value="$money($stats['average'])"
            :hint="'Plus gros don : ' . $money($stats['largest'])" />

        @if ($canSeeNet)
            <x-ui.stat-card
                icon="wallet"
                label="Net a reverser"
                tone="warning"
                data-reveal data-reveal-step="3"
                :value="$money($stats['net'])"
                hint="Apres commission de 2,5 %" />
        @endif
    </section>

    {{-- Evolution (section 55) --}}
    @if ($stats['donations'] > 0)
        <x-ui.panel
            variant="glass"
            title="Evolution sur 30 jours"
            description="Les jours sans don sont affiches a zero : les masquer ferait lire une progression reguliere la ou il y a eu un pic puis rien."
            data-reveal>
            <x-ui.chart.bars
                :series="$series"
                :height="140"
                aria-label="Montant collecte jour par jour sur les trente derniers jours"
                empty-label="Aucun don sur cette periode" />
        </x-ui.panel>
    @endif

    {{-- Export (sections 41, 59).

         Le fichier ne nomme PAS les donateurs anonymes : l'anonymat tient dans
         le fichier comme a l'ecran, sans quoi il suffirait d'exporter pour le
         contourner. --}}
    @can(PermissionName::ExportsGenerate->value)
        <x-ui.panel>
            <x-export-buttons
                label="Exporter les dons"
                hint="Les donateurs anonymes y restent anonymes."
                :csv="route('exports.donations', [$campaign, 'csv'])"
                :xlsx="route('exports.donations', [$campaign, 'xlsx'])" />
        </x-ui.panel>
    @endcan

    {{-- ----------------------------------------------------------------
         Historique
    ----------------------------------------------------------------- --}}
    <x-ui.panel
        title="Historique"
        description="Un don confirme ne peut etre ni supprime, ni modifie, ni retire de cette liste.">

        <div class="space-y-2">
            @forelse ($donations as $donation)
                <div wire:key="donation-{{ $donation->id }}"
                     class="rounded-[var(--radius-md)] border border-grey-200 bg-surface p-3.5">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-azure-100">
                                <x-lucide-user class="size-4 text-azure-700" stroke-width="2" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                {{-- Section 41 : « Donateur anonyme » vaut aussi pour
                                     l'organisateur. Un anonymat qu'il peut lever n'est
                                     pas un anonymat. --}}
                                <p class="truncate font-semibold text-ink">{{ $donation->publicDonorName() }}</p>
                                <p class="text-xs text-grey-400 tabular">
                                    {{ $donation->confirmed_at?->timezone($tz)->translatedFormat('j F Y \a\ H\hi') }}
                                    · {{ $donation->reference }}
                                </p>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <div class="text-right">
                                <p class="font-display font-bold text-ink tabular">{{ $donation->formattedNetAmount() }}</p>
                                @if ($donation->isRefunded())
                                    <p class="text-xs text-grey-400 tabular">
                                        {{ $donation->isFullyRefunded() ? 'Rembourse' : 'Sur '.$donation->formattedSubtotal() }}
                                    </p>
                                @endif
                            </div>

                            @if ($canRefund && ! $donation->isFullyRefunded() && $refundingId !== $donation->id)
                                <button type="button" wire:click="startRefund({{ $donation->id }})"
                                        class="btn btn-secondary btn-sm">
                                    Rembourser
                                </button>
                            @endif
                        </div>
                    </div>

                    @if ($refundingId === $donation->id)
                        {{-- Rembourser n'efface rien : le don reste confirme et
                             visible, une operation nouvelle s'ajoute a lui
                             (sections 46, 47). --}}
                        <div class="glass-inset mt-3 p-3.5">
                            <p class="text-sm font-semibold text-ink">
                                Rembourser {{ $donation->publicDonorName() }}
                            </p>
                            <p class="mt-1 text-xs text-grey-500">
                                Le don reste dans l'historique. Seul le montant qu'il represente change,
                                et le total collecte se recalcule.
                            </p>

                            <div class="mt-3 grid gap-x-3 sm:grid-cols-2">
                                <x-ui.field label="Montant" for="refundAmount"
                                            hint="Vide = tout le restant."
                                            :error="$errors->first('refundAmount')">
                                    <input id="refundAmount" type="number" min="1" inputmode="numeric"
                                           wire:model="refundAmount" class="field tabular"
                                           placeholder="{{ $donation->netAmount() }}"
                                           @error('refundAmount') aria-invalid="true" aria-describedby="refundAmount-error" @enderror>
                                </x-ui.field>

                                <x-ui.field label="Motif" for="refundReason"
                                            :error="$errors->first('refundReason')">
                                    <input id="refundReason" type="text" wire:model="refundReason"
                                           class="field" placeholder="Don en double"
                                           @error('refundReason') aria-invalid="true" aria-describedby="refundReason-error" @enderror>
                                </x-ui.field>
                            </div>

                            {{-- Un remboursement est irreversible : la confirmation
                                 est le dernier point ou l'operation peut encore
                                 etre arretee. --}}
                            <div class="mt-2 flex flex-col-reverse gap-2 sm:flex-row-reverse sm:justify-start">
                                <button type="button" wire:click="refund"
                                        wire:confirm="Confirmer ce remboursement ? L'operation est definitive et sera tracee."
                                        wire:loading.attr="disabled" wire:target="refund"
                                        class="btn btn-primary btn-sm">
                                    Confirmer le remboursement
                                </button>
                                <button type="button" wire:click="cancelRefund" class="btn btn-secondary btn-sm">
                                    Revenir
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <x-ui.empty-state
                    icon="hand-coins"
                    title="Aucun don confirme"
                    description="Partagez le lien de votre collecte. Les dons apparaitront ici des qu'un paiement sera confirme." />
            @endforelse
        </div>

        @if ($donations->hasPages())
            <div class="mt-5">{{ $donations->links() }}</div>
        @endif
    </x-ui.panel>
</div>
