<?php

use App\Http\Controllers\Api\Agent\AuthController as AgentAuthController;
use App\Http\Controllers\Api\Agent\DashboardController as AgentDashboardController;
use App\Http\Controllers\Api\Agent\EventController as AgentEventController;
use App\Http\Controllers\Api\Agent\HistoryController as AgentHistoryController;
use App\Http\Controllers\Api\Agent\ScanController as AgentScanController;
use App\Http\Controllers\Api\Agent\SyncController as AgentSyncController;
use App\Http\Controllers\Api\Agent\TicketSearchController as AgentTicketSearchController;
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

/*
|--------------------------------------------------------------------------
| Application agent (sections 27, 28, 31)
|--------------------------------------------------------------------------
|
| Toutes les routes exigent l'abilite `agent` : un jeton emis pour un
| telephone d'agent ne donne acces a rien d'autre. Un appareil se perd a une
| entree de concert, et c'est ce qui fait la difference entre un incident et
| une compromission.
|
| Le perimetre reel — quels evenements, quelle porte — est verifie dans chaque
| controleur par AgentAccess, jamais deduit de la requete.
|
*/
Route::prefix('agent')->group(function () {
    // Limitation stricte : c'est la seule route ouverte sans jeton.
    Route::post('/connexion', [AgentAuthController::class, 'login'])
        ->middleware('throttle:20,1')
        ->name('api.agent.login');

    Route::middleware(['auth:sanctum', 'agent-token', 'ability:agent'])->group(function () {
        Route::post('/deconnexion', [AgentAuthController::class, 'logout'])
            ->name('api.agent.logout');

        Route::get('/evenements', AgentEventController::class)
            ->name('api.agent.events');

        /*
         * Synchronisation : limitation large. Une porte qui se reconnecte
         * enchaine les pages, et l'etrangler ferait durer le telechargement au
         * moment ou l'agent en a le plus besoin.
         */
        Route::get('/evenements/{event}/billets', AgentSyncController::class)
            ->middleware('throttle:120,1')
            ->name('api.agent.sync');

        /*
         * Remontee des scans : limitation large elle aussi. Refuser un lot
         * ferait perdre des controles deja effectues, ce qui est pire que de
         * les accepter — l'idempotence protege deja des doublons.
         */
        Route::post('/evenements/{event}/scans', AgentScanController::class)
            ->middleware('throttle:120,1')
            ->name('api.agent.scans');

        /*
         * Tableau de bord (section 27.14) : ce que l'application ne peut pas
         * savoir seule, c'est-a-dire ce que font les autres portes.
         */
        Route::get('/evenements/{event}/tableau-de-bord', AgentDashboardController::class)
            ->middleware('throttle:120,1')
            ->name('api.agent.dashboard');

        /*
         * Historique (section 27.15) : l'application tient le sien en local,
         * celui-ci le corrige — un verdict a pu changer par arbitrage, et un
         * telephone reinstalle repart de zero.
         */
        Route::get('/evenements/{event}/controles', AgentHistoryController::class)
            ->middleware('throttle:120,1')
            ->name('api.agent.history');

        /*
         * Recherche manuelle (section 27.15), quand le QR ne se lit pas.
         *
         * Limitation plus stricte que le reste : c'est la seule route qui rend
         * des noms de participants, et une porte n'a aucune raison d'en faire
         * des dizaines par minute. Elle sert un agent qui cherche quelqu'un,
         * pas un telephone qui recopie une liste.
         */
        Route::get('/evenements/{event}/recherche', AgentTicketSearchController::class)
            ->middleware('throttle:30,1')
            ->name('api.agent.search');
    });
});
