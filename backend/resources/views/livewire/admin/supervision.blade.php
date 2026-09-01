@php
    $tz = config('app.display_timezone');

    $healthy = collect($services)->every(fn (array $service) => $service['ok']);
    $calm = collect($incidents)->every(fn (array $incident) => $incident['count'] === 0);

    // Les libelles vivent ici et non dans le service : celui-ci rend des
    // chiffres, l'ecran decide comment les nommer.
    $titles = [
        'webhooks' => 'Callbacks de paiement non traites',
        'jobs' => 'Traitements en echec',
        'settlements' => 'Reversements a trancher',
    ];

    $icons = [
        'webhooks' => 'radio-tower',
        'jobs' => 'circle-slash',
        'settlements' => 'wallet',
    ];

    /*
     * LA GRAVITE SE VOIT, elle ne se deduit pas de la lecture.
     *
     * Les trois incidents ne coutent pas la meme chose. Un callback non traite
     * veut dire qu'un acheteur a paye et n'a rien recu ; un reversement bloque,
     * qu'un organisateur attend son argent. Un email qui n'est pas parti est
     * ennuyeux, et c'est tout — le billet reste consultable en ligne
     * (section 15.1).
     *
     * Les afficher a l'identique obligeait a lire les trois descriptions pour
     * savoir par ou commencer. Sur un ecran dont le seul travail est de trier,
     * c'etait le travail non fait.
     */
    $severities = [
        'webhooks' => 'danger',
        'settlements' => 'danger',
        'jobs' => 'warning',
    ];
@endphp

{{--
    Supervision technique (sections 58, 72).

    L'ecran qu'on ouvre le matin, et qui doit etre vide. Deux blocs, et la
    separation decide de qui est reveille la nuit : l'etat des services repond
    a « ca marche ? », les incidents a « qu'est-ce qui demande un humain ? ».

    Lecture seule : aucun bouton ne rejoue un traitement ni ne marque un
    incident regle. Aucun emoji (regle 80.6).
--}}
<div class="space-y-6">

    <x-ui.page-header
        title="Supervision"
        description="Ce qui est reste en chemin. Cet ecran doit etre vide." />

    {{-- ----------------------------------------------------------------
         Ca marche ?
    ----------------------------------------------------------------- --}}
    <div>
        <h2 class="mb-3 text-xs font-bold tracking-wider text-grey-500 uppercase">
            Etat des services
        </h2>

        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ($services as $key => $service)
                <div wire:key="service-{{ $key }}" class="surface p-4 sm:p-5">
                    <div class="flex items-center gap-2">
                        @if ($service['ok'])
                            <x-lucide-circle-check class="size-4 shrink-0 text-success" stroke-width="2" aria-hidden="true" />
                        @else
                            <x-lucide-circle-alert class="size-4 shrink-0 text-danger" stroke-width="2" aria-hidden="true" />
                        @endif

                        <span class="truncate text-sm font-semibold text-ink">{{ $service['label'] }}</span>
                    </div>

                    <p @class([
                        'mt-2 text-sm',
                        'text-grey-500' => $service['ok'],
                        'text-danger' => ! $service['ok'],
                    ])>
                        {{ $service['detail'] }}
                    </p>
                </div>
            @endforeach
        </div>

        <p class="mt-4 text-xs text-grey-500">
            Ces memes controles repondent sur <span class="tabular">/sante</span>,
            sans authentification, pour un moniteur externe. Une panne y donne un
            code 503 — « indisponible », et non « cette page a un bug ».
        </p>
    </div>

    {{-- ----------------------------------------------------------------
         Qu'est-ce qui demande un humain ?
    ----------------------------------------------------------------- --}}
    <div>
        <h2 class="mb-3 text-xs font-bold tracking-wider text-grey-500 uppercase">
            Incidents
        </h2>

        @if ($calm)
            <div class="surface">
                <x-ui.empty-state
                    icon="circle-check"
                    title="Rien a signaler"
                    description="Aucun callback de paiement en souffrance, aucun traitement en echec, aucun reversement en suspens." />
            </div>
        @else
            <div class="space-y-3">
                @foreach ($incidents as $key => $incident)
                    @continue ($incident['count'] === 0)

                    @php $tone = $severities[$key] ?? 'warning'; @endphp

                    <div wire:key="incident-{{ $key }}"
                         @class([
                             'surface flex flex-wrap items-start gap-4 p-4 sm:p-5',
                             'border-danger/40' => $tone === 'danger',
                             'border-warning/40' => $tone === 'warning',
                         ])>

                        <x-dynamic-component :component="'lucide-' . $icons[$key]"
                                             @class([
                                                 'mt-0.5 size-5 shrink-0',
                                                 'text-danger' => $tone === 'danger',
                                                 'text-warning' => $tone === 'warning',
                                             ])
                                             stroke-width="1.75"
                                             aria-hidden="true" />

                        <div class="min-w-0 flex-1">
                            <p class="font-display font-bold text-ink">{{ $titles[$key] }}</p>
                            <p class="mt-1 text-sm text-grey-500">{{ $incident['detail'] }}</p>

                            @if ($incident['oldest'])
                                <p class="mt-1.5 text-sm text-grey-500">
                                    Le plus ancien remonte au
                                    {{ $incident['oldest']->timezone($tz)->translatedFormat('j F Y \a H\hi') }}.
                                </p>
                            @endif
                        </div>

                        {{-- Le compte porte la meme teinte que le reste de la
                             carte : c'est le chiffre qu'on regarde en premier,
                             et le laisser a l'encre neutre aurait annule la
                             distinction que le cadre vient d'etablir. --}}
                        <p @class([
                            'shrink-0 font-display text-3xl leading-none font-extrabold tabular',
                            'text-danger' => $tone === 'danger',
                            'text-ink' => $tone !== 'danger',
                        ])>
                            {{ $incident['count'] }}
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ----------------------------------------------------------------
         Ce que cet ecran ne fait pas, et pourquoi
    ----------------------------------------------------------------- --}}
    <div class="glass-inset p-4 text-sm leading-relaxed text-grey-500 sm:p-5">
        Aucune action n'est proposee ici, volontairement. Rejouer un callback de
        paiement risque d'encaisser deux fois, et marquer un incident comme regle
        sans l'avoir regle ne fait que le rendre invisible. Ces gestes se font a
        la main, en connaissance de cause.
    </div>
</div>
