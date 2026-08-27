@php
    use App\Enums\CampaignStatus;

    $tz = config('app.display_timezone');
    $nbsp = "\u{202F}";

    $state = $campaign->displayStatus();
    $cover = $campaign->coverUrl();
    $open = $campaign->acceptsDonations();

    $ends = $campaign->ends_at?->timezone($tz);
    $money = fn (int $amount) => number_format($amount, 0, ',', $nbsp).' F';
@endphp

{{--
    Page publique d'une collecte (sections 36, 37, 54).

    LA TRANSPARENCE EST LE PRODUIT. Montant collecte, pourcentage et nombre de
    dons sont affiches ici parce que la section 37 l'exige, et ils viennent du
    serveur a chaque rendu : rien de ce qui est ecrit sur cette page ne peut
    etre saisi par l'organisateur (regle 80.1).

    Aucun emoji (regle 80.6).
--}}
<div>
    {{-- ---------------------------------------------------------------
         Heros
    ---------------------------------------------------------------- --}}
    <section class="relative isolate flex min-h-[60vh] items-end sm:min-h-[66vh]">
        @if ($cover)
            <div aria-hidden="true" class="event-hero -z-10">
                <div class="event-hero-image" style="background-image: url({{ $cover }})"></div>
                <div class="event-hero-scrim"></div>
            </div>
        @else
            <div aria-hidden="true" class="azure-wash absolute inset-0 -z-10"></div>
        @endif

        {{-- Retrait bas genereux, et ce n'est pas de l'aeration : le voile du
             heros fond vers la couleur de page sur ses derniers pour-cent
             (`event-hero-scrim`). Un texte pose la devient blanc sur blanc. La
             page evenement s'en tire parce qu'elle finit sur un bouton plein ;
             ici la derniere ligne est du texte, elle doit remonter. --}}
        <div class="mx-auto w-full max-w-5xl px-5 pt-24 pb-20 sm:px-6 sm:pt-32 sm:pb-24">
            @if ($state === CampaignStatus::Cancelled)
                {{-- L'annulation passe avant tout : quelqu'un qui rouvre le lien
                     recu doit la lire immediatement, pas la deduire. --}}
                <div class="mb-6 inline-flex items-center gap-2.5 rounded-full bg-danger px-4 py-2 text-sm font-semibold text-white">
                    <x-lucide-circle-alert class="size-4 shrink-0" stroke-width="2" />
                    Collecte annulee
                </div>
            @elseif ($state !== CampaignStatus::Published)
                <div class="mb-6 inline-flex items-center gap-2.5 rounded-full bg-white/90 px-4 py-2 text-sm font-semibold text-ink">
                    <x-lucide-circle-pause class="size-4 shrink-0" stroke-width="2" />
                    {{ $state->label() }}
                </div>
            @endif

            <p @class([
                'text-xs font-semibold tracking-[0.16em] uppercase',
                'text-white/75' => $cover,
                'text-azure-700/80' => ! $cover,
            ])>
                Collecte de fonds
            </p>

            <h1 @class([
                'mt-3 max-w-3xl font-display text-4xl leading-[1.04] font-extrabold sm:text-6xl',
                'text-white [text-shadow:0_2px_24px_rgba(16,19,26,0.45)]' => $cover,
                'text-ink' => ! $cover,
            ])>
                {{ $campaign->name }}
            </h1>

            {{-- Blanc plein et ombre portee, comme le titre : ces deux lignes
                 vivent sur la partie la plus claire du voile, la ou une
                 opacite reduite les effacerait. --}}
            <p @class([
                'mt-5 text-sm font-medium',
                'text-white [text-shadow:0_1px_12px_rgba(16,19,26,0.55)]' => $cover,
                'text-grey-500' => ! $cover,
            ])>
                Au profit de <span class="font-semibold">{{ $campaign->beneficiary_label }}</span>
            </p>

            <p @class([
                'mt-1.5 text-sm',
                'text-white/85 [text-shadow:0_1px_12px_rgba(16,19,26,0.55)]' => $cover,
                'text-grey-500' => ! $cover,
            ])>
                Organise par <span class="font-semibold">{{ $campaign->organization->name }}</span>
            </p>
        </div>
    </section>

    <section class="mx-auto w-full max-w-5xl px-5 pb-14 sm:px-6">
        @if ($state === CampaignStatus::Cancelled && $campaign->cancellation_reason)
            <div class="mb-6 flex items-start gap-3 rounded-2xl border border-danger/30 bg-danger/5 p-4 sm:p-5">
                <x-lucide-circle-alert class="mt-0.5 size-5 shrink-0 text-danger" stroke-width="1.75" />
                <div class="text-sm">
                    <p class="font-display text-base font-bold text-ink">Motif de l'annulation</p>
                    <p class="mt-1 leading-relaxed text-grey-500">{{ $campaign->cancellation_reason }}</p>
                </div>
            </div>
        @endif

        {{-- ---------------------------------------------------------------
             Progression (sections 37, 38)

             Le bloc le plus important de la page. Le pourcentage affiche est
             borne a 100 % — une barre qui deborde se lit comme un defaut,
             alors qu'un objectif depasse est une bonne nouvelle ; le montant,
             lui, reste affiche tel quel.
        ---------------------------------------------------------------- --}}
        <div class="surface p-5 sm:p-7" data-reveal>
            {{-- Une seule colonne, alignee a gauche, du telephone au grand
                 ecran. Deux blocs opposes se cassaient sur un ecran etroit :
                 l'objectif passait a la ligne en restant aligne a droite, et
                 les deux chiffres ne se lisaient plus ensemble. --}}
            <p class="text-xs font-semibold tracking-wide text-grey-500 uppercase">Collecte</p>
            <p class="mt-1 font-display text-3xl leading-none font-extrabold text-ink tabular sm:text-4xl">
                {{ $money($progress['collected']) }}
            </p>
            <p class="mt-1.5 text-sm text-grey-500 tabular">
                sur {{ $money($progress['goal']) }} d'objectif
            </p>

            <div class="mt-5">
                {{-- `aria-valuenow` porte la valeur reelle des le rendu : le
                     remplissage progressif est un effet visuel, il ne doit rien
                     changer a ce qu'annonce un lecteur d'ecran. --}}
                <div class="h-3 w-full overflow-hidden rounded-full bg-azure-100"
                     role="progressbar"
                     aria-valuenow="{{ (int) round($progress['displayPercentage']) }}"
                     aria-valuemin="0" aria-valuemax="100"
                     aria-label="Progression de la collecte">
                    <div class="progress-fill h-full rounded-full bg-gradient-to-r from-azure-500 to-azure-600"
                         style="--progress: {{ $progress['displayPercentage'] }}%"></div>
                </div>

                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span class="font-semibold text-azure-700 tabular">
                        {{ number_format($progress['displayPercentage'], 0, ',', $nbsp) }} % de l'objectif
                    </span>
                    <span class="text-grey-500 tabular">
                        {{ number_format($progress['donations'], 0, ',', $nbsp) }}
                        don{{ $progress['donations'] > 1 ? 's' : '' }}
                    </span>
                </div>
            </div>

            @if ($ends)
                <p class="mt-4 flex items-center gap-2 text-sm text-grey-500">
                    <x-lucide-calendar-clock class="size-4 shrink-0" stroke-width="1.75" />
                    @if ($campaign->hasLapsed())
                        Collecte terminee le {{ $ends->translatedFormat('j F Y') }}
                    @else
                        Jusqu'au {{ $ends->translatedFormat('j F Y') }}
                    @endif
                </p>
            @endif

            {{--
                Le don en ligne arrive au jalon suivant : la couche de paiement
                partagee est en cours (decision D-041). Le bouton est rendu
                inerte plutot que masque — le visiteur voit ce que la page
                proposera, sans cliquer dans le vide. Meme parti pris que les
                entrees de menu du tableau de bord.
            --}}
            @if ($open)
                {{-- Taille normale, largeur au contenu : c'est la mesure des
                     boutons principaux du produit. Un bouton pleine largeur en
                     grande taille ecrasait le bloc de progression, qui est ce
                     que le visiteur vient lire en premier. --}}
                <a href="#donner" class="btn btn-primary btn-pill mt-6">
                    <x-lucide-hand-coins class="size-4" stroke-width="2" />
                    Faire un don
                </a>
            @else
                <p class="mt-6 rounded-xl bg-canvas px-4 py-3 text-sm text-grey-500">
                    Cette collecte n'accepte plus de dons.
                    @if ($progress['donations'] > 0)
                        Les contributions deja recues restent visibles ci-dessous.
                    @endif
                </p>
            @endif
        </div>

        {{-- ---------------------------------------------------------------
             Le don (sections 39, 40)

             Sur la page elle-meme, sans page intermediaire : chaque ecran de
             plus entre l'intention et le paiement fait perdre des donateurs.
        ---------------------------------------------------------------- --}}
        @if ($open)
            <div id="donner" class="surface mt-6 scroll-mt-24 p-5 sm:p-7" data-reveal>
                <h2 class="font-display text-lg font-bold text-ink">Faire un don</h2>
                <p class="mt-1 text-sm text-grey-500">
                    Par Airtel Money ou Moov Money. Sans compte et sans inscription.
                </p>

                <div class="mt-5">
                    <livewire:public.donation-form :campaign="$campaign" />
                </div>
            </div>
        @endif

        {{-- ---------------------------------------------------------------
             Description et beneficiaire
        ---------------------------------------------------------------- --}}
        @if ($campaign->description)
            <div class="surface mt-6 p-5 sm:p-7" data-reveal>
                <h2 class="font-display text-lg font-bold text-ink">La collecte</h2>
                <div class="mt-3 leading-relaxed whitespace-pre-line text-grey-500">
                    {{ $campaign->description }}
                </div>
            </div>
        @endif

        <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_auto]">
            <div class="surface p-5 sm:p-7" data-reveal>
                <h2 class="font-display text-lg font-bold text-ink">Qui recoit les fonds</h2>
                <p class="mt-3 flex items-center gap-2.5 text-ink">
                    <x-lucide-heart-handshake class="size-5 shrink-0 text-azure-600" stroke-width="1.5" />
                    <span class="font-semibold">{{ $campaign->beneficiary_label }}</span>
                </p>
                <p class="mt-3 text-sm leading-relaxed text-grey-500">
                    La collecte est organisee par {{ $campaign->organization->name }}. Pass241 encaisse
                    les dons, preleve sa commission, et reverse le net a l'organisateur — la plateforme
                    n'est jamais depositaire des fonds.
                </p>
            </div>

            {{-- Section 54 : le QR ramene a cette page. Utile pour une affiche,
                 un flyer ou un ecran en salle. --}}
            <div class="surface flex flex-col items-center p-5 text-center sm:p-7"
                 data-reveal data-reveal-step="1">
                <h2 class="font-display text-lg font-bold text-ink">Partager</h2>
                {{-- Encart blanc opaque, jamais de verre derriere un QR
                     (regle 70.4) : la lisibilite au scan prime.

                     Le SVG est rendu a taille fixe par QrRenderer : sans ce
                     conteneur dimensionne, il sort de sa carte sur un
                     telephone et decale tout le bloc. Meme motif que le billet
                     (`[&>svg]:size-full`). --}}
                <div class="mt-4 rounded-2xl bg-white p-3 shadow-[0_2px_12px_rgba(20,84,155,0.12)]">
                    <div class="size-36 [&>svg]:size-full sm:size-44">
                        {!! $qrCode !!}
                    </div>
                </div>
                <p class="mt-3 max-w-[16rem] text-xs break-all text-grey-500">
                    {{ $campaign->publicUrl() }}
                </p>
            </div>
        </div>

        {{-- ---------------------------------------------------------------
             Historique public des dons (section 43)

             Le montant de chaque don est toujours visible ; seule l'identite
             suit le choix du donateur (sections 40, 41). La liste arrive avec
             les dons, au jalon suivant.
        ---------------------------------------------------------------- --}}
        <div class="surface mt-6 p-5 sm:p-7" data-reveal>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="font-display text-lg font-bold text-ink">Derniers dons</h2>
                @if ($progress['donations'] > 0)
                    {{-- Le TOTAL est annonce, pas seulement ce qui est affiche :
                         c'est lui qui fait la transparence, pas la longueur de
                         la liste (section 37).

                         Chaine assemblee en PHP plutot que coupee sur plusieurs
                         lignes : le nombre et le mot doivent rester colles a
                         l'affichage comme a la recherche. --}}
                    <p class="text-sm text-grey-500 tabular">
                        {{ number_format($progress['donations'], 0, ',', $nbsp)
                            .' don'.($progress['donations'] > 1 ? 's' : '').' au total' }}
                    </p>
                @endif
            </div>

            @forelse ($donations as $donation)
                <div @class([
                    'flex items-center justify-between gap-4 py-3',
                    'border-t border-grey-200' => ! $loop->first,
                ])>
                    <div class="flex min-w-0 items-center gap-3">
                        {{-- Meme pastille pour tout le monde : un donateur
                             anonyme ne doit pas se distinguer visuellement, sans
                             quoi l'anonymat deviendrait un signalement. --}}
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-azure-100">
                            <x-lucide-user class="size-4 text-azure-700" stroke-width="1.75" />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate font-medium text-ink">{{ $donation->publicDonorName() }}</p>
                            <p class="text-xs text-grey-400">
                                {{ $donation->confirmed_at?->timezone($tz)->translatedFormat('j F Y') }}
                            </p>
                        </div>
                    </div>

                    {{-- Section 40 : le montant est visible dans tous les cas,
                         que le donateur soit nomme ou anonyme.

                         Section 47 : c'est le NET apres remboursement qui
                         s'affiche. Un don rembourse en totalite reste dans
                         l'historique — il ne s'en retire pas (section 45) —
                         mais il y figure a zero. --}}
                    <div class="shrink-0 text-right">
                        <p class="font-semibold text-ink tabular">
                            {{ $donation->formattedNetAmount() }}
                        </p>
                        @if ($donation->isRefunded())
                            <p class="text-xs text-grey-400">
                                {{ $donation->isFullyRefunded() ? 'Rembourse' : 'Partiellement rembourse' }}
                            </p>
                        @endif
                    </div>
                </div>
            @empty
                <div class="py-8 text-center">
                    <x-lucide-hand-coins class="mx-auto size-8 text-grey-300" stroke-width="1.5" />
                    <p class="mt-3 font-semibold text-ink">Aucun don pour l'instant</p>
                    <p class="mx-auto mt-1 max-w-sm text-sm text-grey-500">
                        Chaque contribution apparaitra ici avec son montant. Un donateur peut choisir
                        de rester anonyme — son montant, lui, reste public.
                    </p>
                </div>
            @endforelse

            @if ($hasMore)
                <button type="button" wire:click="loadMore" class="btn btn-ghost btn-pill mt-4 w-full"
                        wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="loadMore">Voir plus de dons</span>
                    <span wire:loading wire:target="loadMore">Chargement</span>
                </button>
            @endif
        </div>
    </section>
</div>
