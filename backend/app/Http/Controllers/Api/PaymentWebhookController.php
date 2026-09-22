<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\PaymentWebhook;
use App\Services\Payments\AcknowledgesCallbacks;
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
    /** Un callback de paiement normal tient dans quelques kilo-octets. */
    private const MAX_BODY_BYTES = 262_144;

    public function __invoke(Request $request, string $provider, PaymentManager $payments): JsonResponse
    {
        try {
            $driver = $payments->driver($provider);
        } catch (\InvalidArgumentException) {
            // Provider inconnu : on ne dit pas lesquels existent.
            return response()->json(['status' => 'ignored'], 202);
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json(['status' => 'payload_too_large'], 413);
        }

        /*
         * Accuse de reception attendu par CE prestataire.
         *
         * Calcule des maintenant, et employe sur TOUS les chemins de sortie —
         * message ignore, doublon, message accepte. Un prestataire qui verifie
         * le corps de la reponse ne fait pas la difference entre nos cas
         * internes : il constate seulement qu'il n'a pas recu ce qu'il
         * attendait, et marque la notification en echec. PVit va plus loin et
         * refuse de valider l'integration tant que l'accuse n'est pas conforme.
         */
        $acknowledgement = $driver instanceof AcknowledgesCallbacks
            ? $driver->callbackAcknowledgement($request)
            : null;

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
                // Une empreinte suffit a rapprocher deux tentatives. Conserver
                // le corps arbitraire d'un attaquant exposerait inutilement la
                // base aux donnees sensibles et au remplissage de stockage.
                'payload' => $this->invalidPayloadFingerprint($request),
                'outcome' => 'Signature invalide.',
                'ip_address' => $request->ip(),
            ]);

            /*
             * 202 et non 401 : repondre differemment selon la validite de la
             * signature indiquerait a un attaquant quand il approche.
             *
             * L'accuse de reception part quand meme. Le message a bien ete
             * RECU — c'est tout ce qu'il atteste — et le refuser ferait relivrer
             * en boucle un message qu'on ne traitera de toute facon jamais.
             */
            return response()->json($acknowledgement ?? ['status' => 'ignored'], 202);
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
                /*
                 * La charge utile d'origine EST conservee — c'est ce qu'on
                 * relira en cas de litige — mais la lecture normalisee est
                 * rangee a cote, sous une cle reservee.
                 *
                 * Sans elle, le traitement en file d'attente devrait rouvrir la
                 * charge utile et en connaitre le dialecte : `order_reference`
                 * et `SUCCEEDED` chez l'un, `merchantReferenceId` et `SUCCESS`
                 * chez l'autre. C'est precisement ce que `PaymentResult` existe
                 * pour empecher — « rien au-dela de cette classe ne doit
                 * connaitre le format de reponse d'Airtel ou de Moov ». Le
                 * driver a deja fait la traduction ici ; la refaire ailleurs la
                 * ferait diverger.
                 */
                'payload' => $result->raw + ['_normalized' => [
                    'state' => $result->state->value,
                    'order_reference' => $result->orderReference,
                    'provider_reference' => $result->providerReference,
                    'amount' => $result->amount,
                    // Repli de verification quand le prestataire ne permet pas
                    // de reconstituer le total debite.
                    'subtotal' => $result->subtotal,
                    'failure_reason' => $result->failureReason,
                ]],
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
            return response()->json($acknowledgement ?? ['status' => 'duplicate'], 200);
        }

        ProcessPaymentWebhook::dispatch($webhook->getKey());

        return response()->json($acknowledgement ?? ['status' => 'accepted'], 200);
    }

    /** @return array<string, mixed> */
    private function invalidPayloadFingerprint(Request $request): array
    {
        $body = $request->getContent();
        $keys = array_slice(array_keys($request->all()), 0, 25);

        return [
            '_forensic' => [
                'sha256' => hash('sha256', $body),
                'bytes' => strlen($body),
                'keys' => array_map(
                    fn (string|int $key): string => mb_substr((string) $key, 0, 80),
                    $keys,
                ),
            ],
        ];
    }
}
