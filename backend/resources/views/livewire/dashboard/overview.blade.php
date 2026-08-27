@php
    use App\Enums\KycStatus;

    $kyc = $organization->kyc_status;
@endphp

<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-ink sm:text-3xl">Bonjour {{ Str::before($user->name, ' ') }}</h1>
        <p class="mt-1 text-sm text-grey-500">{{ $organization->name }}</p>
    </header>

    {{-- ----------------------------------------------------------------
         Chiffres de billetterie (section 56)

         Tout est agrege en SQL : un evenement de 100 000 billets produit les
         memes quelques lignes de resultat qu'un evenement de dix.

         Les MONTANTS sont conditionnes par la permission financiere, pas les
         decomptes de billets : un CO_ORGANIZER doit savoir combien de billets
         sont partis pour organiser l'entree, sans voir les recettes.
    ----------------------------------------------------------------- --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-ticket class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Billets vendus</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                {{ number_format($stats['sold'], 0, ',', "\u{202F}") }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500 tabular">
                sur {{ number_format($stats['capacity'], 0, ',', "\u{202F}") }} mis en vente
            </p>
        </div>

        @if ($canSeeMoney)
            <div class="surface p-4">
                <div class="flex items-center gap-2 text-grey-500">
                    <x-lucide-banknote class="size-4" stroke-width="1.5" />
                    <span class="text-xs font-semibold tracking-wide uppercase">Recettes</span>
                </div>
                <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                    {{ \App\Support\Money::format($stats['revenue']) }}
                </p>
                {{-- Le net figure des la premiere lecture : afficher la recette
                     brute seule ferait lire le reversement comme une erreur
                     (section 21.2). --}}
                <p class="mt-0.5 text-sm text-grey-500 tabular">
                    {{ \App\Support\Money::format($stats['net']) }} apres commission
                </p>
            </div>
        @endif

        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-package class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Billets restants</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                {{ number_format($stats['remaining'], 0, ',', "\u{202F}") }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500">
                @if ($stats['capacity'] > 0)
                    {{ number_format($stats['sold'] / $stats['capacity'] * 100, 1, ',', ' ') }} % ecoules
                @else
                    Aucun billet mis en vente
                @endif
            </p>
        </div>

        @if ($stats['hasEntries'])
            <div class="surface p-4">
                <div class="flex items-center gap-2 text-grey-500">
                    <x-lucide-scan-line class="size-4" stroke-width="1.5" />
                    <span class="text-xs font-semibold tracking-wide uppercase">Entrees</span>
                </div>
                <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                    {{ number_format($stats['entries'], 0, ',', "\u{202F}") }}
                </p>
                <p class="mt-0.5 text-sm text-grey-500 tabular">
                    {{ number_format($stats['attendanceRate'], 1, ',', ' ') }} % de presence
                </p>
            </div>
        @else
            {{-- Le controle d'acces arrive en phase 5. Annoncer « 0 entree » et
                 « 0 % de presence » ne renseignerait sur rien et se lirait
                 comme un echec. --}}
            <div class="surface p-4">
                <div class="flex items-center gap-2 text-grey-400">
                    <x-lucide-scan-line class="size-4" stroke-width="1.5" />
                    <span class="text-xs font-semibold tracking-wide uppercase">Entrees</span>
                </div>
                <p class="mt-2 font-display text-lg font-bold text-grey-400">A venir</p>
                <p class="mt-0.5 text-sm text-grey-400">
                    Disponible avec le controle d'acces.
                </p>
            </div>
        @endif
    </div>

    {{-- ----------------------------------------------------------------
         Repartition par categorie de billet

         Les barres sont en CSS, sans bibliotheque de graphiques : quatre a dix
         lignes ne justifient pas des dizaines de kilo-octets sur une connexion
         mobile gabonaise (section 72). Le chiffre reste a cote de la barre —
         c'est lui qu'on lit, la barre ne sert qu'a comparer.
    ----------------------------------------------------------------- --}}
    @if ($byType->isNotEmpty())
        <div class="surface mt-6 p-4 sm:p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="font-display text-lg font-bold text-ink">Ventes par categorie</h2>
                <p class="text-xs text-grey-400">Toutes vos categories de billets</p>
            </div>

            <div class="mt-5 space-y-4">
                @foreach ($byType as $type)
                    <div>
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <span class="font-medium text-ink">{{ $type['name'] }}</span>
                            <span class="text-sm text-grey-500 tabular">
                                {{ number_format($type['sold'], 0, ',', "\u{202F}") }} vendus
                                @if ($type['capacity'] > 0)
                                    · {{ number_format($type['remaining'], 0, ',', "\u{202F}") }} restants
                                @endif
                                @if ($canSeeMoney)
                                    · {{ \App\Support\Money::format($type['revenue']) }}
                                @endif
                            </span>
                        </div>

                        <div class="mt-1.5 flex items-center gap-3">
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-grey-200/70">
                                <div class="h-full rounded-full bg-azure-500"
                                     style="width: {{ number_format(max($type['share'], $type['sold'] > 0 ? 2 : 0), 2, '.', '') }}%"></div>
                            </div>
                            <span class="w-14 shrink-0 text-right text-sm text-grey-500 tabular">
                                {{ number_format($type['share'], 1, ',', ' ') }} %
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="mt-5 text-xs leading-relaxed text-grey-400">
                Les pourcentages portent sur la repartition des ventes. Un billet
                rembourse, annule ou expire n'est pas compte comme vendu.
            </p>
        </div>
    @endif

    {{-- Cartes empilees sur telephone, en grille a partir de sm --}}
    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-building-2 class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Organisation</span>
            </div>
            <p class="mt-2 truncate font-display text-lg font-bold text-ink">{{ $organization->name }}</p>
            <p class="mt-0.5 text-sm text-grey-500">{{ $organization->type->label() }}</p>
        </div>

        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-wallet class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Reversement</span>
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
                <x-lucide-users class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Equipe</span>
            </div>
            <p class="mt-2 font-display text-lg font-bold text-ink tabular">
                {{ $memberCount }} {{ $memberCount > 1 ? 'membres' : 'membre' }}
            </p>
            <p class="mt-0.5 truncate text-sm text-grey-500">{{ implode(', ', $roles) }}</p>
        </div>
    </div>

    {{-- ----------------------------------------------------------------
         Reversements (section 21.2, decision D-026)

         Une liste en consultation seule. Aucun bouton « retirer » n'existe
         nulle part dans le produit : le reversement est declenche par le
         systeme apres l'evenement, jamais par un humain. Un bouton laisserait
         croire que Pass241 detient les fonds et les libere a la demande.
    ----------------------------------------------------------------- --}}
    @if ($settlements->isNotEmpty())
        <div class="surface mt-6 p-4 sm:p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="font-display text-lg font-bold text-ink">Reversements</h2>
                <p class="text-xs text-grey-400">Verses apres chaque evenement</p>
            </div>

            <ul class="mt-4 divide-y divide-grey-200/70">
                @foreach ($settlements as $settlement)
                    <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-ink">
                                {{ $settlement->settlableName() ?? 'Reversement' }}
                            </p>
                            <p class="mt-0.5 text-xs text-grey-500 tabular">
                                {{ $settlement->reference }}
                                @if ($settlement->expectedOn())
                                    —
                                    {{ $settlement->expectedOn()
                                        ->timezone(config('app.display_timezone'))
                                        ->translatedFormat('j F Y') }}
                                @endif
                                — vers {{ $settlement->maskedRecipient() }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <span @class([
                                'rounded-full px-2.5 py-1 text-xs font-semibold',
                                'bg-success/10 text-success' => $settlement->isSettled(),
                                'bg-warning/10 text-warning' => ! $settlement->isSettled() && $settlement->isAccepted(),
                                'bg-danger/10 text-danger' => ! $settlement->isAccepted(),
                            ])>
                                {{ $settlement->status->label() }}
                            </span>
                            <span class="font-display font-bold text-ink tabular">
                                {{ $settlement->formattedNet() }}
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>

            <p class="mt-4 text-xs leading-relaxed text-grey-400">
                Montants nets, commission Pass241 deja deduite. Les frais de
                paiement sont a la charge de l'acheteur et n'entrent pas dans ce calcul.
            </p>
        </div>
    @endif

    {{-- Etat vide : le produit dit ou il en est plutot que d'afficher des
         indicateurs vides qui ne renseignent sur rien. --}}
    <div class="surface mt-6 p-6 text-center sm:p-10">
        <x-lucide-calendar-plus class="mx-auto size-8 text-azure-300" stroke-width="1.5" />
        <h2 class="mt-3 font-display text-lg font-bold text-ink">Aucun evenement pour le moment</h2>
        <p class="mx-auto mt-1.5 max-w-md text-sm text-grey-500">
            La creation d'evenements arrive avec le prochain module. Votre compte,
            votre organisation et vos acces sont deja en place.
        </p>
    </div>
</div>
