<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\PaymentWebhook;
use App\Services\Payments\PaymentManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reception des callbacks de paiement (sections 22, 23).
 *
 * Ce controleur ne confirme rien. Il authentifie, journalise, et confie le
 * traitement a une file d'attente. Trois raisons :
 *
 * - un operateur attend une reponse rapide, et considere souvent un delai
 *   comme un echec — ce qui declenche des relivraisons en cascade ;
 * - un message est conserve meme si son traitement echoue, donc rien n'est
 *   jamais perdu ;
 * - le traitement peut etre rejoue sans que l'operateur ait a renvoyer quoi
 *   que ce soit.
 *
 * Le frontend ne decide jamais qu'un paiement est confirme (section 22) : cette
 * route est le seul chemin entrant, et elle n'accepte que des messages signes.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentManager $payments): JsonResponse
    {
        try {
            $driver = $payments->driver($provider);
        } catch (\InvalidArgumentException) {
            // Provider inconnu : on ne dit pas lesquels existent.
            return response()->json(['status' => 'ignored'], 202);
        }

        $result = $driver->processCallback($request);

        // Signature invalide. Le message est conserve — c'est ce qui permet de
        // constater une tentative de fraude — mais rien ne sera traite.
        if ($result === null) {
            PaymentWebhook::create([
                'provider' => $provider,
                // Sans identifiant propre, la contrainte d'unicite regrouperait
                // tous les messages invalides sur une meme cle.
                'event_id' => 'invalid:'.Str::uuid()->toString(),
                'signature_valid' => false,
                'payload' => $request->all(),
                'outcome' => 'Signature invalide.',
                'ip_address' => $request->ip(),
            ]);

            // 202 et non 401 : repondre differemment selon la validite de la
            // signature indiquerait a un attaquant quand il approche.
            return response()->json(['status' => 'ignored'], 202);
        }

        try {
            /*
             * Insertion dans une transaction imbriquee, donc derriere un point
             * de sauvegarde.
             *
             * Sous PostgreSQL, une violation de contrainte avorte toute la
             * transaction en cours : sans ce point de sauvegarde, un doublon
             * rendrait inutilisable la transaction englobante, et la requete
             * suivante echouerait elle aussi. Le doublon est un cas NORMAL
             * ici — c'est le mecanisme d'idempotence — il ne doit rien casser.
             */
            $webhook = DB::transaction(fn () => PaymentWebhook::create([
                'provider' => $provider,
                'event_id' => $result->eventId,
                'signature_valid' => true,
                'payload' => $result->raw,
                'ip_address' => $request->ip(),
            ]));
        } catch (UniqueConstraintViolationException) {
            /*
             * Section 23 : ce message a deja ete recu.
             *
             * L'idempotence est portee par la contrainte d'unicite en base, et
             * non par un `exists()` prealable : deux livraisons simultanees
             * passeraient toutes deux un test applicatif, jamais la contrainte.
             *
             * Reponse 200 : du point de vue de l'operateur, le message est bien
             * arrive. Un code d'erreur relancerait des relivraisons inutiles.
             */
            return response()->json(['status' => 'duplicate'], 200);
        }

        ProcessPaymentWebhook::dispatch($webhook->getKey());

        return response()->json(['status' => 'accepted'], 200);
    }
}
