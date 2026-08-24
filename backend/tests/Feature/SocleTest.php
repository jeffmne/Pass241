<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
 * Jalon 0 — verification du socle technique.
 *
 * Ces tests ne valident aucune regle metier : ils prouvent que l'environnement
 * exigé par la section 60 (PostgreSQL, Redis) est bien celui sur lequel le
 * reste de la suite s'executera. Si l'un d'eux tombe, tous les tests
 * financiers qui suivront deviennent non concluants.
 */

it('repond sur la page d accueil', function () {
    $this->get('/')->assertOk();
});

it('utilise PostgreSQL et non SQLite', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('joint la base de donnees de test', function () {
    expect(DB::connection()->getDatabaseName())->toBe('pass241_testing');
    expect(DB::select('select 1 as ok')[0]->ok)->toBe(1);
});

it('joint Redis pour le cache et les holds de stock', function () {
    Redis::set('pass241:socle', 'ok');

    expect(Redis::get('pass241:socle'))->toBe('ok');

    Redis::del('pass241:socle');
});

it('stocke les dates en UTC et affiche a l heure de Libreville', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('app.display_timezone'))->toBe('Africa/Libreville');
});

it('utilise le provider de paiement factice en test', function () {
    expect(env('PAYMENT_DEFAULT_DRIVER'))->toBe('fake');
});
