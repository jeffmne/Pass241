<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| Section 80.5 : la plateforme encaisse, retient sa commission et reverse le
| net. Les montants sont stockes en entiers XAF, jamais en flottant. Cette
| expectation sert de garde-fou dans les tests financiers.
|
*/

expect()->extend('toBeXaf', function () {
    expect($this->value)->toBeInt();

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/
