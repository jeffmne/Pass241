<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;

uses(Tests\TestCase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-01 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('rend les quatre unites et les dates absolues meme sans JavaScript', function () {
    $html = Blade::render('<x-countdown :starts-at="$start" :ends-at="$end" />', [
        'start' => now()->addDays(2)->addHours(3)->addMinutes(4)->addSeconds(5),
        'end' => now()->addDays(3),
    ]);

    expect($html)->toContain('data-countdown-phase="upcoming"', 'Début de l’événement dans', 'Jours', 'Heures', 'Minutes', 'Secondes')
        ->toContain('data-countdown-days>02<', 'data-countdown-hours>03<', 'data-countdown-minutes>04<', 'data-countdown-seconds>05<')
        ->toContain('datetime=', 'Heure de Libreville');
});

it('affiche le compteur de fin pour une collecte deja commencee', function () {
    $html = Blade::render('<x-countdown subject="campaign" :starts-at="$start" :ends-at="$end" />', [
        'start' => now()->subDay(), 'end' => now()->addHour(),
    ]);
    expect($html)->toContain('data-countdown-phase="ongoing"', 'Fin de la collecte dans');
});

it('presente un vote annule sans compteur actif', function () {
    $html = Blade::render('<x-countdown subject="poll" status="CANCELLED" :starts-at="$start" :ends-at="$end" />', [
        'start' => now()->addDay(), 'end' => now()->addDays(2),
    ]);
    expect($html)->toContain('data-countdown-phase="cancelled"', 'Vote annulé')
        ->toMatch('/data-countdown-digits\s+hidden/');
});

it('explique une collecte sans date de fin sans inventer une echeance', function () {
    $html = Blade::render('<x-countdown subject="campaign" />');
    expect($html)->toContain('Collecte en cours', 'Aucune date de fin prévue.')
        ->toMatch('/data-countdown-digits\s+hidden/');
});
