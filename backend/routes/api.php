<?php

use App\Http\Controllers\Api\PaymentWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
 * Callbacks des operateurs de paiement (section 22).
 *
 * Volontairement dans les routes d'API : ce sont des appels de machine a
 * machine, sans session ni jeton CSRF. L'authenticite repose entierement sur
 * la signature du message, verifiee par le provider concerne.
 *
 * Limitation de debit large : un operateur peut relivrer en rafale apres une
 * coupure, et refuser ses messages ferait perdre des confirmations de
 * paiement. Elle protege contre l'inondation, pas contre le trafic normal.
 */
Route::post('/webhooks/payments/{provider}', PaymentWebhookController::class)
    ->middleware('throttle:240,1')
    ->name('webhooks.payments');
