@php
    use App\Support\Money;

    $tz = config('app.display_timezone');

    // Les liens d'export reprennent EXACTEMENT les filtres affiches : un
    // fichier telecharge depuis un ecran filtre sur les votes de cette annee ne
    // doit pas contenir la billetterie de l'an dernier.
    $exportParams = ['module' => $type?->value ?? 'tous', 'period' => $period];
@endphp

{{--
    Le grand livre des transactions (sections 56, 59).

    L'ecran qui repond a « pourquoi ai-je recu moins que ce qui a ete paye ».
    Les trois modules y sont ensemble, dans l'ordre du temps, avec ce que la
    plateforme a retenu sur chacun.

    LECTURE SEULE, SANS EXCEPTION : aucun bouton n'ecrit ici. La table est en
    ajout seul (section 46) et l'organisateur ne corrige jamais une ligne
    d'argent confirmee (regle 80.1.6).

    Surfaces pleines pour le tableau, verre pour les trois chiffres de synthese
    seulement — un montant qu'on relit ligne a ligne a besoin de contraste, pas
    de matiere (regle 70.4). Aucun emoji (regle 80.6).
--}}
<div class="space-y-5">

    {{-- La sous-navigation au-dessus du titre : elle dit dans quelle section on
         se trouve, avant que le titre ne dise quelle page. La mettre dans les
         actions de l'en-tete la collait aux onglets de filtre de l'ecran
         voisin, en pilules identiques — les deux groupes se lisaient alors
         comme un seul. --}}
    <x-finance-nav />

    <x-ui.page-header
        title="Finances"
        description="Ce qui a ete encaisse, ce que Pass241 a retenu, et ce qui vous revient." />

    {{-- ----------------------------------------------------------------
         Filtres
    ----------------------------------------------------------------- --}}
    <div class="flex flex-col gap-2.5 sm:flex-row sm:items-center">
        <div class="sm:w-56">
            <label for="ledger-module" class="sr-only">Filtrer par module</label>
            <select id="ledger-module" wire:model.live="module" class="field">
                <option value="">Tous les modules</option>
                @foreach ($modules as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="scroll-x no-scrollbar -mx-1 px-1 sm:ml-auto">
            <div class="seg" role="radiogroup" aria-label="Periode">
                @foreach ($periods as $value => $label)
                    <button type="button"
                            role="radio"
                            wire:click="$set('period', '{{ $value }}')"
                            aria-checked="{{ $period === $value ? 'true' : 'false' }}"
                            class="seg-item">{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ----------------------------------------------------------------
         Les trois chiffres qui expliquent l'ecart
    ----------------------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card
            label="Encaisse"
            :value="Money::formatShort($totals['gross'])"
            icon="arrow-down-to-line"
            :hint="$totals['transactions'] . ' transaction' . ($totals['transactions'] > 1 ? 's' : '')
                . ($totals['refunded'] > 0 ? ', dont ' . Money::formatShort($totals['refunded']) . ' rembourses' : '')" />

        <x-ui.stat-card
            label="Commission Pass241"
            :value="Money::formatShort($totals['commission'])"
            icon="percent"
            tone="neutral"
            hint="Retenue au taux en vigueur le jour de chaque transaction." />

        <x-ui.stat-card
            label="Vous revient"
            :value="Money::formatShort($totals['net'])"
            icon="wallet"
            tone="success"
            hint="Verse par reversement, apres verification de votre dossier." />
    </div>

    {{-- ----------------------------------------------------------------
         Le fichier
    ----------------------------------------------------------------- --}}
    @if ($canExport)
        <div class="glass-inset p-4 sm:p-5">
            <x-export-buttons
                label="Exporter ces transactions"
                hint="Les filtres ci-dessus s'appliquent au fichier. Les remboursements y sortent en negatif."
                :csv="route('exports.transactions', $exportParams + ['format' => 'csv'])"
                :xlsx="route('exports.transactions', $exportParams + ['format' => 'xlsx'])" />
        </div>
    @endif

    {{-- ----------------------------------------------------------------
         Le grand livre
    ----------------------------------------------------------------- --}}
    <div wire:loading.delay wire:target="module, period, gotoPage, previousPage, nextPage">
        <x-ui.skeleton variant="list" :rows="4" class="surface p-4 sm:p-5" />
    </div>

    <x-ui.panel flush
                wire:loading.delay.class="opacity-40"
                wire:target="module, period, gotoPage, previousPage, nextPage"
                class="transition-opacity">

        @if ($transactions->isEmpty())
            <x-ui.empty-state
                icon="receipt"
                :tone="$type || $period !== 'tout' ? 'filtered' : 'neutral'"
                title="Aucune transaction"
                :description="$type || $period !== 'tout'
                    ? 'Aucune transaction sur cette periode pour ce module.'
                    : 'Vos encaissements apparaitront ici des le premier paiement confirme.'" />
        @else
            {{-- `scroll` et non `stack` : les colonnes de ce tableau se
                 comparent entre elles — brut, commission, net sont une seule
                 phrase lue horizontalement. Les empiler en carte la couperait
                 en trois. --}}
            <x-ui.table responsive="scroll" caption="Transactions de l'organisation">
                <x-slot:head>
                    <th scope="col">Date</th>
                    <th scope="col">Module</th>
                    <th scope="col">Reference</th>
                    <th scope="col" class="col-amount">Brut</th>
                    <th scope="col" class="col-amount">Taux</th>
                    <th scope="col" class="col-amount">Commission</th>
                    <th scope="col" class="col-amount">Net</th>
                </x-slot:head>

                @foreach ($transactions as $transaction)
                    @php
                        $refund = $transaction->isAdjustment();
                        // Le signe est porte par l'affichage, jamais par la
                        // base : une ligne d'ajustement y decrit un mouvement
                        // de retour, avec un montant positif.
                        $sign = $refund ? '-' : '';
                    @endphp

                    <tr wire:key="tx-{{ $transaction->id }}">
                        <td class="text-grey-500 tabular">
                            {{ $transaction->created_at->timezone($tz)->translatedFormat('j M Y') }}
                        </td>

                        <td>
                            <span class="text-ink">{{ $transaction->type->label() }}</span>
                            @if ($refund)
                                <x-ui.badge tone="neutral" class="ml-1.5">Remboursement</x-ui.badge>
                            @endif
                        </td>

                        <td class="cell-primary tabular">
                            {{ $transaction->commissionable?->paymentReference() ?? '—' }}
                        </td>

                        <td @class(['col-amount font-normal', 'text-grey-500' => ! $refund, 'text-danger' => $refund])>
                            {{ $sign }}{{ Money::format($transaction->gross_amount, false) }}
                        </td>

                        <td class="col-amount font-normal text-grey-500">
                            {{ $transaction->formattedRate() }}
                        </td>

                        <td @class(['col-amount font-normal', 'text-grey-500' => ! $refund, 'text-danger' => $refund])>
                            {{ $sign }}{{ Money::format($transaction->commission_amount, false) }}
                        </td>

                        <td @class(['col-amount', 'text-danger' => $refund])>
                            {{ $sign }}{{ Money::format($transaction->net_amount, false) }}
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif

        <x-slot:footer>
            Les montants sont en francs CFA. « Brut » est l'assiette de la
            commission — jamais le total debite, qui contient en plus les frais
            de l'operateur mobile, avances par le payeur et qui ne transitent pas
            par Pass241. Une ligne « Remboursement » retire ce qui a ete rendu :
            elle s'ajoute au journal, elle n'efface jamais l'encaissement
            d'origine.
        </x-slot:footer>
    </x-ui.panel>

    @if ($transactions->hasPages())
        <div>{{ $transactions->links() }}</div>
    @endif
</div>
