@php
    use App\Enums\KycStatus;
    use App\Enums\PermissionName;
    use App\Support\Money;

    $kyc = $organization->kyc_status;
    $tz = config('app.display_timezone');

    $canManageEvents = auth()->user()->can(PermissionName::EventsManage->value);
    $canManageCampaigns = auth()->user()->can(PermissionName::CampaignsManage->value);

    // Raccourci de lecture uniquement : la mise en forme, elle, vit dans
    // `Money` — un seul endroit pour le separateur de milliers de tout le
    // produit.
    $number = fn (int|float $value, int $decimals = 0) => Money::number($value, $decimals);
@endphp

<div class="space-y-6">

    <x-ui.page-header
        title="Bonjour {{ Str::before($user->name, ' ') }}"
        description="Voici l'activite de votre billetterie"
        :eyebrow="$organization->name">

        @if ($canManageEvents)
            <x-slot:actions>
                <a href="{{ route('events.create') }}" wire:navigate class="btn btn-primary">
                    <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                    Nouvel evenement
                </a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    {{-- ================================================================
         Chiffres de billetterie (section 56)

         Quatre cartes, et pas une de plus. Un tableau de bord qui en aligne
         douze n'est plus un tableau de bord : plus rien n'y est prioritaire,
         et l'oeil ne sait pas ou se poser en l'ouvrant le matin.
    ================================================================= --}}
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
             aria-label="Chiffres de la billetterie">

        <x-ui.stat-card
            icon="ticket"
            label="Billets vendus"
            :value="$number($stats['sold'])"
            :hint="'sur ' . $number($stats['capacity']) . ' mis en vente'" />

        @if ($canSeeMoney)
            {{-- Le net figure des la premiere lecture : afficher la recette
                 brute seule ferait lire le reversement comme une erreur
                 (section 21.2). --}}
            <x-ui.stat-card
                icon="banknote"
                label="Recettes"
                tone="success"
                :value="Money::format($stats['revenue'])"
                :hint="Money::format($stats['net']) . ' apres commission'" />
        @endif

        <x-ui.stat-card
            icon="package"
            label="Billets restants"
            tone="warning"
            :value="$number($stats['remaining'])"
            :hint="$stats['capacity'] > 0
                ? $number($stats['sold'] / $stats['capacity'] * 100, 1) . ' % ecoules'
                : 'Aucun billet mis en vente'" />

        @if ($stats['hasEntries'])
            <x-ui.stat-card
                icon="scan-line"
                label="Entrees"
                tone="primary"
                :value="$number($stats['entries'])"
                :hint="$number($stats['attendanceRate'], 1) . ' % de presence'" />
        @else
            {{-- Tant qu'aucun billet n'a ete scanne, annoncer « 0 entree » et
                 « 0 % de presence » ne renseigne sur rien et se lit comme un
                 echec. La carte s'efface au lieu de mentir. --}}
            <x-ui.stat-card
                icon="scan-line"
                label="Entrees"
                tone="neutral"
                muted
                value="—"
                hint="Au premier billet controle." />
        @endif
    </section>

    {{-- ================================================================
         Evolution

         Deux graphiques, deux echelles de temps, deux questions differentes :
         « est-ce que ca monte ? » sur six mois, et « qu'est-ce qui s'est passe
         cette semaine ? » sur sept jours. Le premier se lit en forme, le second
         en comparaison de colonnes — d'ou une courbe d'un cote, des barres de
         l'autre.

         Aucune bibliotheque de graphiques n'est chargee : les deux sont ecrits
         par le serveur (section 72).
    ================================================================= --}}
    <section @class([
        'grid gap-3',
        'lg:grid-cols-2' => $canSeeMoney,
    ])>
        @if ($canSeeMoney)
            <x-ui.panel variant="glass" title="Recettes" description="Six derniers mois">
                <x-ui.chart.area
                    :series="$monthlyRevenue"
                    aria-label="Recettes des six derniers mois"
                    empty-label="Aucune recette sur cette periode" />
            </x-ui.panel>
        @endif

        <x-ui.panel variant="glass" title="Ventes" description="Sept derniers jours">
            <x-ui.chart.bars
                :series="$dailySales"
                :highlight="count($dailySales) - 1"
                aria-label="Billets vendus sur les sept derniers jours"
                empty-label="Aucune vente cette semaine" />
        </x-ui.panel>
    </section>

    {{-- ================================================================
         Actions rapides

         Les quatre gestes que l'organisateur vient faire. Deux colonnes des le
         telephone : empilees sur toute la largeur, elles repousseraient le
         contenu utile sous la ligne de flottaison.

         Chaque tuile est conditionnee par sa permission — une tuile qui mene a
         un ecran interdit se solde par un 403, ce qui se lit comme une panne.
    ================================================================= --}}
    <section class="grid grid-cols-2 gap-3 md:grid-cols-4" aria-label="Actions rapides">
        @if ($canManageEvents)
            <x-ui.quick-action icon="calendar-plus" label="Creer un evenement"
                               :href="route('events.create')" />
        @endif

        @if ($canManageCampaigns)
            <x-ui.quick-action icon="hand-coins" label="Nouvelle collecte" tone="success"
                               :href="route('campaigns.create')" />
        @endif

        @can(PermissionName::FinancesView->value)
            <x-ui.quick-action icon="undo-2" label="Remboursements" tone="warning"
                               :href="route('refunds.index')" />
        @endcan

        <x-ui.quick-action icon="settings" label="Parametres" tone="neutral"
                           :href="route('organization.settings')" />
    </section>

    {{-- ================================================================
         Evenements a venir et dernieres ventes
    ================================================================= --}}
    <section @class(['grid gap-3', 'lg:grid-cols-2' => $canSeeMoney])>

        <x-ui.panel title="Vos evenements" description="Les plus proches">
            @if ($canManageEvents || $recentEvents->isNotEmpty())
                <x-slot:actions>
                    <a href="{{ route('events.index') }}" wire:navigate
                       class="text-sm font-semibold text-azure-700 hover:underline">
                        Tout voir
                    </a>
                </x-slot:actions>
            @endif

            @forelse ($recentEvents as $event)
                @php
                    $sold = (int) $event->sold_count;
                    $capacity = (int) ($event->capacity_count ?? 0);
                    $fill = $capacity > 0 ? min(100, $sold / $capacity * 100) : 0;

                    /*
                     * La couleur de la barre est une LECTURE, pas une
                     * decoration : un evenement a 8 % trois jours avant
                     * l'ouverture des portes n'est pas une bonne nouvelle, et
                     * l'organisateur doit le voir sans calculer.
                     *
                     * Le pourcentage chiffre reste a cote de la barre : la
                     * couleur seule ne dit rien a un daltonien, ni sur une
                     * capture imprimee en noir et blanc.
                     */
                    $tone = match (true) {
                        $capacity === 0 => 'neutral',
                        $fill >= 70 => 'success',
                        $fill >= 30 => 'warning',
                        default => 'danger',
                    };
                @endphp

                <a href="{{ $canManageEvents ? route('events.edit', $event) : route('events.index') }}"
                   wire:navigate
                   class="group -mx-2 flex items-center gap-3 rounded-[var(--radius-md)] px-2 py-3 transition hover:bg-surface-muted">

                    @if ($event->posterUrl())
                        <img src="{{ $event->posterUrl() }}" alt=""
                             loading="lazy"
                             class="size-14 shrink-0 rounded-[var(--radius-sm)] object-cover">
                    @else
                        {{-- Pas d'affiche : la date tient lieu de vignette.
                             Un rectangle gris vide ne dirait rien. --}}
                        <span class="flex size-14 shrink-0 flex-col items-center justify-center rounded-[var(--radius-sm)] bg-azure-100 text-azure-700">
                            <span class="font-display text-lg leading-none font-extrabold tabular">
                                {{ $event->starts_at->timezone($tz)->format('d') }}
                            </span>
                            <span class="mt-0.5 text-[10px] font-bold tracking-wide uppercase">
                                {{ $event->starts_at->timezone($tz)->translatedFormat('M') }}
                            </span>
                        </span>
                    @endif

                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="truncate text-sm font-bold text-ink">{{ $event->name }}</span>
                            <x-status-badge :status="$event->status" />
                        </span>

                        <span class="mt-2 flex items-center gap-2.5">
                            <span class="progress-track flex-1">
                                <span @class([
                                        'progress-fill block',
                                        'progress-fill-success' => $tone === 'success',
                                        'progress-fill-warning' => $tone === 'warning',
                                        'progress-fill-danger' => $tone === 'danger',
                                    ])
                                      style="--progress: {{ number_format($fill, 2, '.', '') }}%"></span>
                            </span>
                            <span @class([
                                'w-20 shrink-0 text-right text-xs font-bold tabular',
                                'text-success' => $tone === 'success',
                                'text-warning' => $tone === 'warning',
                                'text-danger' => $tone === 'danger',
                                'text-grey-400' => $tone === 'neutral',
                            ])>
                                {{ $capacity > 0 ? number_format($fill, 0) . ' %' : 'Sans stock' }}
                            </span>
                        </span>
                    </span>

                    <x-lucide-chevron-right
                        class="size-4 shrink-0 text-grey-400 transition-opacity lg:opacity-0 lg:group-hover:opacity-100"
                        stroke-width="2" aria-hidden="true" />
                </a>
            @empty
                <x-ui.empty-state
                    icon="calendar-plus"
                    title="Aucun evenement"
                    description="Renseignez la date, le lieu et l'affiche. Vous recevrez un lien unique a partager sur WhatsApp ou sur vos affiches.">
                    @if ($canManageEvents)
                        <x-slot:action>
                            <a href="{{ route('events.create') }}" wire:navigate class="btn btn-primary">
                                <x-lucide-plus class="size-4 shrink-0" stroke-width="2.25" aria-hidden="true" />
                                Creer un evenement
                            </a>
                        </x-slot:action>
                    @endif
                </x-ui.empty-state>
            @endforelse
        </x-ui.panel>

        @if ($canSeeMoney)
            <x-ui.panel title="Dernieres ventes" description="Commandes payees">
                @forelse ($recentSales as $order)
                    @php $count = $order->totalTickets(); @endphp

                    <div class="flex items-center gap-3 py-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-success/10 text-success">
                            <x-lucide-smartphone class="size-5" stroke-width="1.75" aria-hidden="true" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm text-ink">
                                <span class="font-bold">{{ $count }} {{ $count > 1 ? 'billets' : 'billet' }}</span>
                                @if ($order->event)
                                    — {{ $order->event->name }}
                                @endif
                            </p>
                            <p class="mt-0.5 truncate text-xs text-grey-500 tabular">
                                {{ $order->buyerName() }}
                                @if ($order->paid_at)
                                    · {{ $order->paid_at->timezone($tz)->translatedFormat('j M \a\ H\hi') }}
                                @endif
                            </p>
                        </div>

                        <span class="shrink-0 font-display text-sm font-bold text-ink tabular">
                            {{ $order->formattedAmount() }}
                        </span>
                    </div>
                @empty
                    <x-ui.empty-state
                        icon="receipt"
                        title="Aucune vente"
                        description="Les commandes payees apparaitront ici, la plus recente en premier." />
                @endforelse
            </x-ui.panel>
        @endif
    </section>

    {{-- ================================================================
         Repartition par categorie de billet

         Les barres sont en CSS, sans bibliotheque : quatre a dix lignes ne
         justifient pas des dizaines de kilo-octets sur une connexion mobile
         gabonaise (section 72). Le chiffre reste a cote de la barre — c'est lui
         qu'on lit, la barre ne sert qu'a comparer.
    ================================================================= --}}
    @if ($byType->isNotEmpty())
        <x-ui.panel title="Ventes par categorie" description="Toutes vos categories de billets">
            <div class="space-y-4">
                @foreach ($byType as $type)
                    <div>
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <span class="text-sm font-semibold text-ink">{{ $type['name'] }}</span>
                            <span class="text-sm text-grey-500 tabular">
                                {{ $number($type['sold']) }} vendus
                                @if ($type['capacity'] > 0)
                                    · {{ $number($type['remaining']) }} restants
                                @endif
                                @if ($canSeeMoney)
                                    · {{ Money::format($type['revenue']) }}
                                @endif
                            </span>
                        </div>

                        <div class="mt-2 flex items-center gap-3">
                            <div class="progress-track flex-1">
                                {{-- Plancher a 2 % : une barre a zero disparait,
                                     et on ne distingue plus « aucune vente » de
                                     « pas de ligne ». --}}
                                <div class="progress-fill"
                                     style="--progress: {{ number_format(max($type['share'], $type['sold'] > 0 ? 2 : 0), 2, '.', '') }}%"></div>
                            </div>
                            <span class="w-14 shrink-0 text-right text-sm text-grey-500 tabular">
                                {{ $number($type['share'], 1) }} %
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            <x-slot:footer>
                Les pourcentages portent sur la repartition des ventes. Un billet
                rembourse, annule ou expire n'est pas compte comme vendu.
            </x-slot:footer>
        </x-ui.panel>
    @endif

    {{-- ================================================================
         Reversements (section 21.2, decision D-026)

         Une liste en consultation seule. Aucun bouton « retirer » n'existe
         nulle part dans le produit : le reversement est declenche par le
         systeme apres l'evenement, jamais par un humain. Un bouton laisserait
         croire que Pass241 detient les fonds et les libere a la demande.
    ================================================================= --}}
    @if ($settlements->isNotEmpty())
        <x-ui.panel title="Reversements" description="Verses apres chaque evenement" flush>
            <x-ui.table caption="Reversements recents">
                <x-slot:head>
                    <th scope="col">Objet</th>
                    <th scope="col">Reference</th>
                    <th scope="col">Statut</th>
                    <th scope="col" class="col-amount">Montant net</th>
                </x-slot:head>

                @foreach ($settlements as $settlement)
                    <tr wire:key="settlement-{{ $settlement->id }}">
                        <td class="cell-primary">{{ $settlement->settlableName() ?? 'Reversement' }}</td>

                        <td data-label="Reference" class="text-grey-500">
                            <span class="tabular">{{ $settlement->reference }}</span>
                            @if ($settlement->expectedOn())
                                <span class="block text-xs">
                                    {{ $settlement->expectedOn()->timezone($tz)->translatedFormat('j F Y') }}
                                    — vers {{ $settlement->maskedRecipient() }}
                                </span>
                            @endif
                        </td>

                        <td data-label="Statut">
                            <x-ui.badge :tone="match (true) {
                                $settlement->isSettled() => 'success',
                                $settlement->isAccepted() => 'warning',
                                default => 'danger',
                            }">{{ $settlement->status->label() }}</x-ui.badge>
                        </td>

                        <td data-label="Montant net" class="col-amount">{{ $settlement->formattedNet() }}</td>
                    </tr>
                @endforeach
            </x-ui.table>

            <x-slot:footer>
                Montants nets, commission Pass241 deja deduite. Les frais de
                paiement sont a la charge de l'acheteur et n'entrent pas dans ce calcul.
            </x-slot:footer>
        </x-ui.panel>
    @endif

    {{-- ================================================================
         Etat du compte

         Trois informations qu'on ne consulte pas tous les jours, mais qu'on
         doit pouvoir retrouver sans chercher : qui l'on est, quand on sera
         paye, et avec qui l'on travaille. En bas de page, donc, mais pas
         ailleurs.
    ================================================================= --}}
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" aria-label="Etat du compte">
        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-building-2 class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                <span class="text-xs font-bold tracking-wider uppercase">Organisation</span>
            </div>
            <p class="mt-2 truncate font-display text-lg font-bold text-ink">{{ $organization->name }}</p>
            <p class="mt-0.5 text-sm text-grey-500">{{ $organization->type->label() }}</p>
        </div>

        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-wallet class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                <span class="text-xs font-bold tracking-wider uppercase">Reversement</span>
            </div>
            <p @class([
                'mt-2 font-display text-lg font-bold',
                'text-success' => $organization->canReceivePayouts(),
                'text-danger' => $kyc === KycStatus::Rejected,
                'text-warning' => ! $organization->canReceivePayouts() && $kyc !== KycStatus::Rejected,
            ])>
                {{ $organization->canReceivePayouts() ? 'Numero enregistre' : 'A configurer' }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500">
                {{-- Le versement intervient apres l'evenement (section 21.2) :
                     le dire ici evite la question la plus posee au support. --}}
                @if ($organization->payoutBlocker())
                    {{ $organization->payoutBlocker() }}
                @elseif ($upcomingPayout)
                    Prochain versement apres « {{ Str::limit($upcomingPayout->name, 24) }} ».
                @else
                    Vos recettes sont versees apres votre evenement.
                @endif
            </p>
        </div>

        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-users class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                <span class="text-xs font-bold tracking-wider uppercase">Equipe</span>
            </div>
            <p class="mt-2 font-display text-lg font-bold text-ink tabular">
                {{ $memberCount }} {{ $memberCount > 1 ? 'membres' : 'membre' }}
            </p>
            <p class="mt-0.5 truncate text-sm text-grey-500">{{ implode(', ', $roles) }}</p>
        </div>
    </section>
</div>
