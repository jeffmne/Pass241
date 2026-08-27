@php
    $tz = config('app.display_timezone');
    $nbsp = "\u{202F}";
    $money = fn (int $amount) => number_format($amount, 0, ',', $nbsp).' F';

    // Hauteur des barres rapportee au meilleur jour : c'est la forme de la
    // courbe qui renseigne, pas la valeur absolue de chaque barre.
    $peak = max(1, $evolution->max('amount'));
@endphp

{{--
    Dons recus par une collecte (sections 44 a 48, 55).

    EN LECTURE SEULE. Aucun bouton ne modifie quoi que ce soit, et c'est
    volontaire : un don confirme ne se corrige pas, il se rembourse (section 47).

    Surfaces pleines : liste dense de donnees de travail (regle 70.4).
    Aucun emoji (regle 80.6).
--}}
<div>
    <header class="mb-6">
        <a href="{{ route('campaigns.edit', $campaign) }}" wire:navigate
           class="inline-flex items-center gap-1.5 text-sm font-medium text-grey-500 transition-colors hover:text-azure-700">
            <x-lucide-arrow-left class="size-4" stroke-width="1.75" />
            {{ $campaign->name }}
        </a>

        <h1 class="mt-2 font-display text-2xl font-extrabold text-ink sm:text-3xl">Dons recus</h1>
        <p class="mt-1 text-sm text-grey-500">
            Calcules a partir des dons confirmes. Rien ici ne se modifie a la main.
        </p>
    </header>

    {{-- ----------------------------------------------------------------
         Compteurs (section 55)
    ----------------------------------------------------------------- --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="surface p-4" data-reveal data-reveal-step="0">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-hand-coins class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Collecte</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">{{ $money($stats['collected']) }}</p>
            <p class="mt-0.5 text-sm text-grey-500 tabular">
                {{ number_format($progress['displayPercentage'], 0, ',', $nbsp) }} % de l'objectif
            </p>
        </div>

        <div class="surface p-4" data-reveal data-reveal-step="1">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-users class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Donateurs</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">
                {{ number_format($stats['donors'], 0, ',', $nbsp) }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500 tabular">
                {{ number_format($stats['donations'], 0, ',', $nbsp) }}
                don{{ $stats['donations'] > 1 ? 's' : '' }}
            </p>
        </div>

        <div class="surface p-4" data-reveal data-reveal-step="2">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-chart-no-axes-column class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Don moyen</span>
            </div>
            <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">{{ $money($stats['average']) }}</p>
            <p class="mt-0.5 text-sm text-grey-500 tabular">
                Plus gros don : {{ $money($stats['largest']) }}
            </p>
        </div>

        @if ($canSeeNet)
            <div class="surface p-4" data-reveal data-reveal-step="3">
                <div class="flex items-center gap-2 text-grey-500">
                    <x-lucide-wallet class="size-4" stroke-width="1.5" />
                    <span class="text-xs font-semibold tracking-wide uppercase">Net a reverser</span>
                </div>
                <p class="mt-2 font-display text-2xl font-extrabold text-ink tabular">{{ $money($stats['net']) }}</p>
                <p class="mt-0.5 text-sm text-grey-500">Apres commission de 2,5 %</p>
            </div>
        @endif
    </div>

    {{-- ----------------------------------------------------------------
         Evolution (section 55)
    ----------------------------------------------------------------- --}}
    @if ($stats['donations'] > 0)
        <section class="surface mt-6 p-4 sm:p-6" data-reveal>
            <h2 class="font-display text-lg font-bold text-ink">Evolution sur 30 jours</h2>
            <p class="mt-1 text-sm text-grey-500">
                Les jours sans don sont affiches a zero : les masquer ferait lire une
                progression reguliere la ou il y a eu un pic puis rien.
            </p>

            <div class="mt-5 flex h-32 items-end gap-1" role="img"
                 aria-label="Montant collecte jour par jour sur les trente derniers jours">
                @foreach ($evolution as $day)
                    <div class="group relative flex-1"
                         title="{{ $day['date']->translatedFormat('j F') }} — {{ $money($day['amount']) }}">
                        <div class="w-full rounded-t bg-gradient-to-t from-azure-500 to-azure-400 transition-opacity group-hover:opacity-80"
                             style="height: {{ max(2, (int) round($day['amount'] / $peak * 128)) }}px"></div>
                    </div>
                @endforeach
            </div>

            <div class="mt-2 flex justify-between text-xs text-grey-400">
                <span>{{ $evolution->first()['date']->translatedFormat('j F') }}</span>
                <span>{{ $evolution->last()['date']->translatedFormat('j F') }}</span>
            </div>
        </section>
    @endif

    {{-- ----------------------------------------------------------------
         Historique
    ----------------------------------------------------------------- --}}
    <section class="surface mt-6 p-4 sm:p-6">
        <h2 class="font-display text-lg font-bold text-ink">Historique</h2>
        <p class="mt-1 text-sm text-grey-500">
            Un don confirme ne peut etre ni supprime, ni modifie, ni retire de cette liste.
        </p>

        <div class="mt-4 space-y-2">
            @forelse ($donations as $donation)
                <div class="flex items-center justify-between gap-4 rounded-2xl border border-grey-200 bg-white p-3.5">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-azure-100">
                            <x-lucide-user class="size-4 text-azure-700" stroke-width="1.75" />
                        </span>
                        <div class="min-w-0">
                            {{-- Section 41 : « Donateur anonyme » vaut aussi pour
                                 l'organisateur. Un anonymat qu'il peut lever n'est
                                 pas un anonymat. --}}
                            <p class="truncate font-medium text-ink">{{ $donation->publicDonorName() }}</p>
                            <p class="text-xs text-grey-400 tabular">
                                {{ $donation->confirmed_at?->timezone($tz)->translatedFormat('j F Y \a\ H\hi') }}
                                · {{ $donation->reference }}
                            </p>
                        </div>
                    </div>

                    <p class="shrink-0 font-semibold text-ink tabular">{{ $donation->formattedSubtotal() }}</p>
                </div>
            @empty
                <div class="py-10 text-center">
                    <x-lucide-hand-coins class="mx-auto size-8 text-grey-300" stroke-width="1.5" />
                    <p class="mt-3 font-semibold text-ink">Aucun don confirme</p>
                    <p class="mx-auto mt-1 max-w-sm text-sm text-grey-500">
                        Partagez le lien de votre collecte. Les dons apparaitront ici des
                        qu'un paiement sera confirme.
                    </p>
                </div>
            @endforelse
        </div>

        @if ($donations->hasPages())
            <div class="mt-5">{{ $donations->links() }}</div>
        @endif
    </section>
</div>
