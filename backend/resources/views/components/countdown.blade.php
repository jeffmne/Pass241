@props(['startsAt' => null, 'endsAt' => null, 'status' => '', 'subject' => 'event'])

@php
    $labels = match ($subject) {
        'campaign' => ['upcoming' => 'Début de la collecte dans', 'ongoing' => 'Fin de la collecte dans', 'ended' => 'Collecte terminée', 'cancelled' => 'Collecte annulée', 'unlimited' => 'Collecte en cours'],
        'poll' => ['upcoming' => 'Ouverture des votes dans', 'ongoing' => 'Clôture des votes dans', 'ended' => 'Votes terminés', 'cancelled' => 'Vote annulé', 'unlimited' => 'Votes en cours'],
        default => ['upcoming' => 'Début de l’événement dans', 'ongoing' => 'Fin de l’événement dans', 'ended' => 'Événement terminé', 'cancelled' => 'Événement annulé', 'unlimited' => 'Événement en cours'],
    };
    $serverNow = now();
    $phase = match (true) {
        $status === 'CANCELLED' => 'cancelled',
        in_array($status, ['COMPLETED', 'CLOSED', 'EXPIRED', 'ARCHIVED'], true), $endsAt && $serverNow->greaterThanOrEqualTo($endsAt) => 'ended',
        $startsAt && $serverNow->lessThan($startsAt) => 'upcoming',
        default => 'ongoing',
    };
    $target = match ($phase) { 'upcoming' => $startsAt, 'ongoing' => $endsAt, default => null };
    if (! $endsAt) $labels['ongoing'] = $labels['unlimited'];
    $remaining = $target ? max(0, (int) ceil($serverNow->diffInSeconds($target, false))) : 0;
    $parts = ['days' => intdiv($remaining, 86400), 'hours' => intdiv($remaining % 86400, 3600), 'minutes' => intdiv($remaining % 3600, 60), 'seconds' => $remaining % 60];
    $units = ['days' => 'Jours', 'hours' => 'Heures', 'minutes' => 'Minutes', 'seconds' => 'Secondes'];
@endphp

<section {{ $attributes->class(['mt-5 border-t border-grey-200/60 pt-5']) }}
         aria-label="Compte à rebours"
         data-countdown
         data-starts-at="{{ $startsAt?->toIso8601String() }}"
         data-ends-at="{{ $endsAt?->toIso8601String() }}"
         data-server-now="{{ $serverNow->toISOString() }}"
         data-status="{{ $status }}"
         data-label-upcoming="{{ $labels['upcoming'] }}"
         data-label-ongoing="{{ $labels['ongoing'] }}"
         data-label-ended="{{ $labels['ended'] }}"
         data-label-cancelled="{{ $labels['cancelled'] }}"
         data-countdown-phase="{{ $phase }}">
    <p class="text-sm font-semibold text-ink" data-countdown-label aria-live="polite" aria-atomic="true">{{ $labels[$phase] }}</p>

    <div role="timer" aria-live="off" aria-label="{{ $labels[$phase] }}" data-countdown-digits @if (! $target) hidden @endif>
        <dl class="mt-3 grid max-w-md grid-cols-4 gap-2 text-center sm:gap-5">
            @foreach ($units as $unit => $label)
                <div class="flex min-w-0 flex-col gap-1">
                    <dt class="order-2 text-xs text-grey-500">{{ $label }}</dt>
                    <dd class="order-1 font-display text-3xl leading-tight font-bold text-azure-700 tabular sm:text-4xl" data-countdown-{{ $unit }}>{{ str_pad((string) $parts[$unit], 2, '0', STR_PAD_LEFT) }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    <p class="mt-2 text-sm text-grey-500" data-countdown-no-end @if ($phase !== 'ongoing' || $endsAt) hidden @endif>Aucune date de fin prévue.</p>

    @if ($startsAt || $endsAt)
        <p class="mt-3 text-xs leading-relaxed text-grey-500">
            @if ($startsAt)
                Début : <time datetime="{{ $startsAt->toIso8601String() }}">{{ $startsAt->copy()->timezone(config('app.display_timezone'))->translatedFormat('j F Y à H:i') }}</time>.
            @endif
            @if ($endsAt)
                Fin : <time datetime="{{ $endsAt->toIso8601String() }}">{{ $endsAt->copy()->timezone(config('app.display_timezone'))->translatedFormat('j F Y à H:i') }}</time>.
            @endif
            Heure de Libreville.
        </p>
    @endif
</section>
