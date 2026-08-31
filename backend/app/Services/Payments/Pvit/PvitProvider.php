<?php

namespace App\Services\Payments\Pvit;

use App\Enums\PaymentState;
use App\Services\Payments\CollectionProvider;
use App\Services\Payments\Payable;
use App\Services\Payments\PaymentCapabilities;
use App\Services\Payments\PaymentResult;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Encaissement par PVit (BAKOAI) — mobile money Gabon et CEMAC.
 *
 * PVit est un AGREGATEUR : une seule API pour Airtel Money et Moov Money. C'est
 * ce qui permet au produit de n'avoir qu'un driver la ou la section 21.1 en
 * envisageait deux.
 *
 * ---------------------------------------------------------------------------
 * LE PIEGE PRINCIPAL : QUEL MONTANT ENVOYER
 * ---------------------------------------------------------------------------
 *
 * PVit recoit un montant ET une mention `owner_charge`. Avec `CUSTOMER`, il
 * AJOUTE ses frais par-dessus et debite le total au payeur.
 *
 * Or nos entites stockent deja le total : `paymentAmount()` vaut le sous-total
 * PLUS les frais que nous avons calcules. Le lui envoyer ferait ajouter les
 * frais une SECONDE fois — le payeur serait debite de 10 725 F la ou il a lu
 * 10 350 F sur son ecran, et la difference partirait chez le prestataire.
 *
 * C'est donc `commissionBase()` — le sous-total, avant frais — qui part chez
 * PVit. Les deux valeurs se rejoignent ensuite : PVit debite
 * `sous-total + ses frais`, et nous avons annonce `sous-total + nos frais`,
 * calcules sur le meme bareme releve sur leur API. La verification que les deux
 * coincident se fait au callback, sur `totalAmount`.
 *
 * ---------------------------------------------------------------------------
 * CE QUE CE DRIVER NE FAIT PAS
 * ---------------------------------------------------------------------------
 *
 * Ni reversement, ni remboursement : le compte marchand n'expose aujourd'hui ni
 * l'un ni l'autre parmi ses endpoints. Le driver n'implemente donc pas
 * `SettlementProvider`, et `refund()` echoue bruyamment plutot que de faire
 * croire a un remboursement qui n'aura pas lieu (section 21.2, decision D-024).
 */
class PvitProvider implements CollectionProvider
{
    public function __construct(private readonly PvitSecret $secret) {}

    public function name(): string
    {
        return 'pvit';
    }

    /**
     * Capacites declarees d'apres LES ENDPOINTS REELLEMENT PROVISIONNES sur le
     * compte, et non d'apres le catalogue commercial de PVit.
     *
     * PVit documente un paiement partage et un remboursement ; aucun des deux
     * n'est ouvert sur ce compte. Les declarer disponibles ferait choisir au
     * jalon du reversement une strategie qui echouerait en production — c'est
     * exactement ce que la decision D-024 cherche a eviter.
     */
    public function capabilities(): PaymentCapabilities
    {
        return new PaymentCapabilities(
            nativeSplit: false,
            payout: false,
            scheduledPayout: false,
            partialRefund: false,
            recipientNameLookup: false,
        );
    }

    /**
     * Pousse la demande de paiement sur le telephone du payeur (section 21.1).
     *
     * La reponse ne signifie JAMAIS que le paiement a abouti : PVit accuse
     * reception, l'operateur sollicite ensuite le client, et le resultat arrive
     * par callback ou par interrogation du statut. Rendre autre chose que
     * `Pending` ici serait le mensonge le plus couteux du produit.
     */
    public function initializePayment(Payable $payable): PaymentResult
    {
        $config = $this->config();

        $response = $this->send(fn (string $secret) => Http::withHeaders(['X-Secret' => $secret])
            ->asJson()
            ->timeout(30)
            ->post($config['base_url'].$config['endpoints']['payment'], [
                // LE SOUS-TOTAL, jamais le total : voir l'en-tete de classe.
                'amount' => $payable->commissionBase(),
                'reference' => $this->alphanumericReference($payable->paymentReference()),
                'service' => 'RESTFUL',
                'transaction_type' => 'PAYMENT',
                'operator_code' => $this->operatorCode($payable),
                'customer_account_number' => $payable->payerPhone(),
                'merchant_operation_account_code' => $config['operation_account_code'],
                'callback_url_code' => $config['callback_url_code'],
                'owner_charge' => $config['charge_owner'],
            ]));

        if ($response->failed()) {
            return new PaymentResult(
                state: PaymentState::Failed,
                orderReference: $payable->paymentReference(),
                failureReason: $this->reason($response),
                raw: $this->payload($response),
            );
        }

        return new PaymentResult(
            state: PaymentState::Pending,
            providerReference: $this->transactionId($response),
            orderReference: $payable->paymentReference(),
            amount: $payable->paymentAmount(),
            raw: $this->payload($response),
        );
    }

    /**
     * Interroge PVit sur l'etat d'une transaction.
     *
     * C'EST LE REPLI QUAND LE CALLBACK N'ARRIVE PAS, et il n'est pas theorique :
     * les notifications mobile money se perdent. C'est aussi — et surtout — ce
     * qui permet de ne jamais croire un callback sur parole, PVit ne le signant
     * pas. Une confirmation passe toujours par cette reponse-ci, obtenue par un
     * appel SORTANT et authentifie.
     */
    public function checkPaymentStatus(Payable $payable): PaymentResult
    {
        $reference = $this->alphanumericReference($payable->paymentReference());

        $payload = $this->fetchStatus($reference);

        if ($payload === null) {
            // L'API est injoignable ou ne connait pas encore la transaction :
            // on ne sait pas, et on le dit. Conclure a l'echec ferait perdre un
            // paiement peut-etre abouti.
            return new PaymentResult(
                state: PaymentState::Unknown,
                providerReference: $payable->providerReference(),
                orderReference: $payable->paymentReference(),
                failureReason: 'Statut PVit non verifiable pour l\'instant.',
            );
        }

        return new PaymentResult(
            state: $this->state((string) ($payload['status'] ?? '')),
            providerReference: $payable->providerReference(),
            orderReference: $payable->paymentReference(),
            failureReason: $this->failureFrom($payload),
            amount: $this->debitedAmount($payload),
            raw: $payload,
        );
    }

    /**
     * Montant reellement debite au payeur, d'apres PVit — ou null si PVit ne
     * permet pas de le savoir.
     *
     * ---------------------------------------------------------------------
     * LES FRAIS PVIT ONT DEUX COMPOSANTES, ET LE STATUT N'EN MONTRE QU'UNE
     * ---------------------------------------------------------------------
     *
     * `fees` dans une reponse de statut n'est PAS le total preleve : c'est la
     * seule part de PVit. La part de l'operateur voyage a cote, sous
     * `operatorFees`, et le statut ne la renvoie pas du tout.
     *
     * Mesure faite sur le compte de test le 2026-08-31, qui ne laisse pas de
     * place au doute :
     *
     *              get-fees   `fees` du statut
     *   Airtel      3,5 %          2,5 %
     *   Moov        2,5 %          2,5 %
     *
     * Moov concorde exactement ; Airtel differe d'exactement UN POINT. C'est la
     * part operateur d'Airtel, que Moov n'a pas. L'exemple de callback de la
     * documentation le confirme, ou les trois nombres se recomposent :
     * `amount 150 + fees 3,5 + operatorFees 1,5 = totalAmount 155`.
     *
     * C'EST DONC `get-fees` QUI FAIT FOI pour ce que le payeur voit partir de
     * son telephone, et le bareme configure (350 et 250 points de base) est
     * juste. Additionner `amount + fees` depuis un statut sous-estimerait le
     * debit de la part operateur, et ferait croire a une surfacturation de
     * notre part la ou il n'y en a aucune — ce diagnostic a ete pose, puis
     * infirme par la mesure ci-dessus.
     *
     * D'ou le parti pris : on ne rend un montant QUE lorsque PVit le donne
     * complet. A defaut, `null` — l'appelant sait alors qu'il ne sait pas,
     * plutot que de recevoir un nombre faux.
     *
     * @param  array<string, mixed>  $payload
     */
    private function debitedAmount(array $payload): ?int
    {
        // Total explicite : la seule valeur qui se suffise a elle-meme.
        if (isset($payload['totalAmount'])) {
            return (int) ceil((float) $payload['totalAmount']);
        }

        // Les deux composantes de frais sont la : on peut recomposer.
        if (isset($payload['amount'], $payload['fees'], $payload['operatorFees'])) {
            return (int) ceil(
                (float) $payload['amount']
                + (float) $payload['fees']
                + (float) $payload['operatorFees']
            );
        }

        return null;
    }

    /**
     * Traduit un callback PVit — EN LE VERIFIANT, PAS EN LE CROYANT.
     *
     * ---------------------------------------------------------------------
     * PVIT NE SIGNE PAS SES NOTIFICATIONS
     * ---------------------------------------------------------------------
     *
     * Aucun HMAC, aucun secret partage, rien dans les en-tetes : le corps
     * arrive en clair avec un `"status": "SUCCESS"`. Le contrat de cette
     * methode exige pourtant qu'un webhook non authentifie ne confirme jamais
     * un paiement (section 22). Pris au pied de la lettre, il faudrait rendre
     * `null` a chaque fois — et aucun paiement ne serait jamais confirme.
     *
     * La sortie est de ne pas se servir du corps comme d'une preuve. Il ne sert
     * qu'a designer UNE transaction ; l'etat, lui, est ensuite relu chez PVit
     * par un appel SORTANT et authentifie par notre cle. Quiconque forge un
     * faux callback ne fait donc que declencher une verification qui le
     * dementira.
     *
     * C'est aussi ce qui protege du rejeu : un callback rejoue reinterroge le
     * statut et retrouve le meme etat, que `ConfirmPayment` traite deja de
     * facon idempotente (section 23).
     *
     * L'accusé de reception exige par PVit — un echo de `transactionId` et
     * `code` — n'est pas rendu ici : il appartient a la route qui recoit la
     * requete, pas au driver qui la traduit.
     */
    public function processCallback(Request $request): ?PaymentResult
    {
        $transactionId = (string) ($request->input('transactionId')
            ?? $request->input('transaction_id')
            ?? '');

        $merchantReference = (string) ($request->input('merchantReferenceId')
            ?? $request->input('reference')
            ?? '');

        /*
         * Sans reference marchande, il n'y a rien a verifier — et donc rien a
         * confirmer. On refuse plutot que de deviner : c'est elle, et non
         * l'identifiant PVit, qui interroge l'API de statut.
         */
        if ($merchantReference === '') {
            return null;
        }

        // La verification part de NOTRE reference : c'est ce que l'API de
        // statut attend, malgre le nom de son parametre.
        $verified = $this->fetchStatus($this->alphanumericReference($merchantReference));

        if ($verified === null) {
            /*
             * PVit n'a pas repondu : on ne sait pas. `Unknown` laisse la
             * transaction en attente et la fera reinterroger, la ou `Failed`
             * cloturerait un paiement peut-etre abouti.
             */
            return new PaymentResult(
                state: PaymentState::Unknown,
                providerReference: $transactionId,
                orderReference: $merchantReference ?: null,
                failureReason: 'Statut PVit non verifiable pour l\'instant.',
                raw: (array) $request->all(),
            );
        }

        return new PaymentResult(
            state: $this->state((string) ($verified['status'] ?? '')),
            providerReference: $transactionId,
            /*
             * Identifiant de message, porteur de l'idempotence (section 23).
             * PVit n'en fournit pas : la transaction et son etat verifie en
             * tiennent lieu. Deux notifications pour la meme issue produisent
             * la meme cle, et la seconde est donc ignoree en amont.
             */
            eventId: 'pvit:'.$transactionId.':'.mb_strtoupper((string) ($verified['status'] ?? 'UNKNOWN')),
            failureReason: $this->failureFrom($verified),
            orderReference: $merchantReference ?: null,
            amount: $this->debitedAmount($verified),
            // Les deux charges utiles sont conservees : celle qu'on a RECUE, et
            // celle qui a fait foi. En cas de litige, l'ecart entre les deux
            // est precisement ce qu'on voudra regarder.
            raw: ['callback' => (array) $request->all(), 'verified' => $verified],
        );
    }

    /**
     * Relit l'etat d'une transaction chez PVit.
     *
     * LE PARAMETRE S'APPELLE `transactionId` MAIS ATTEND NOTRE REFERENCE
     * MARCHANDE, pas l'identifiant `PAY…` que PVit nous a rendu a l'initiation.
     * La documentation montre pourtant l'inverse — son exemple passe
     * `transactionId=PAY23102400001`.
     *
     * Mesure faite sur le compte de test le 2026-08-31, sans ambiguite :
     *   transactionId=PAY3108261252964  -> 404 TRANSACTION_NOT_FOUND
     *   transactionId=VTL8J6PH8         -> 200 SUCCESS
     *
     * D'ou le passage par la reference compactee. L'identifiant PVit reste
     * conserve sur la transaction — il sert au rapprochement et au support —
     * mais il n'interroge rien.
     *
     * @return array<string, mixed>|null
     */
    private function fetchStatus(string $merchantReference): ?array
    {
        if ($merchantReference === '') {
            return null;
        }

        $config = $this->config();

        try {
            $response = $this->send(fn (string $secret) => Http::withHeaders(['X-Secret' => $secret])
                ->timeout(20)
                ->get($config['base_url'].$config['endpoints']['status'], [
                    'transactionId' => $merchantReference,
                    'accountOperationCode' => $config['operation_account_code'],
                    'transactionOperation' => 'PAYMENT',
                ]));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $response->successful() ? $this->payload($response) : null;
    }

    /**
     * Non disponible sur ce compte marchand.
     *
     * Echoue plutot que de rendre un echec silencieux : un remboursement qui
     * n'a pas eu lieu ne se remarque que le jour ou le client telephone.
     */
    public function refund(Payable $payable, int $amount, string $reason): PaymentResult
    {
        throw new RuntimeException(
            'PVit : aucun endpoint de remboursement n\'est provisionne sur ce compte marchand. '
            .'Le remboursement doit etre traite hors ligne, ou l\'endpoint demande a PVit.'
        );
    }

    /*
     * -----------------------------------------------------------------------
     * Traduction du dialecte PVit
     * -----------------------------------------------------------------------
     */

    /**
     * Statuts PVit vers les notres.
     *
     * `AMBIGUOUS` MERITE SON SORT PARTICULIER. PVit l'emploie quand il ne sait
     * pas lui-meme conclure. Le traiter comme un succes crediterait des billets
     * jamais payes ; comme un echec, il rendrait du stock et des voix alors que
     * l'argent est peut-etre parti. `Unknown` laisse la transaction en attente
     * et la fait reinterroger — c'est le seul choix qui ne cree pas de tort.
     */
    private function state(string $status): PaymentState
    {
        return match (mb_strtoupper($status)) {
            'SUCCESS' => PaymentState::Succeeded,
            'FAILED' => PaymentState::Failed,
            'PENDING' => PaymentState::Pending,
            default => PaymentState::Unknown,
        };
    }

    /**
     * Reference debarrassee de ses tirets, pour PVit.
     *
     * PVIT REFUSE TOUT CE QUI N'EST PAS ALPHANUMERIQUE : « Field 'reference'
     * must be an alphanumeric value ». Or nos references portent des tirets —
     * `VT-ZHU9-592`, `PS241-4KX9-27B` — et ce n'est pas de la decoration : ce
     * sont elles qu'on dicte au telephone au support, et les groupes de
     * caracteres sont ce qui rend la dictee possible.
     *
     * On les envoie donc compactees, sans toucher a la reference stockee. La
     * transformation est SANS PERTE pour nos formats, dont les tirets sont a
     * des positions fixes : deux references distinctes ne peuvent pas donner la
     * meme chaine compactee.
     *
     * Le retour ne depend de toute facon pas de cette chaine : un callback est
     * rattache par le `transactionId` de PVit, que nous conservons dans
     * `provider_reference`. La reference marchande n'est qu'un repere lisible.
     */
    private function alphanumericReference(string $reference): string
    {
        return (string) preg_replace('/[^A-Za-z0-9]/', '', $reference);
    }

    /** Code operateur PVit correspondant a l'operateur choisi par le payeur. */
    private function operatorCode(Payable $payable): string
    {
        $operator = $payable->paymentOperator()
            ?? throw new RuntimeException(
                'Aucun operateur n\'est associe a ce paiement : impossible de le pousser.'
            );

        $code = config('payments.pvit.operators.'.$operator->value);

        return is_string($code) && $code !== ''
            ? $code
            : throw new RuntimeException(sprintf(
                'Aucun code PVit n\'est configure pour l\'operateur « %s ».',
                $operator->value,
            ));
    }

    /**
     * Envoie une requete, en rejouant UNE fois avec une cle fraiche.
     *
     * La cle mise en cache peut avoir ete invalidee chez PVit sans que nous le
     * sachions. Le rejeu evite qu'un incident de leur cote se traduise chez nous
     * par des paiements refuses. Une seule tentative supplementaire : au-dela,
     * ce n'est plus la cle, et insister ferait attendre le payeur pour rien.
     *
     * @param  callable(string): Response  $request
     */
    private function send(callable $request): Response
    {
        $response = $request($this->secret->get());

        if ($response->status() === 401 || $response->status() === 403) {
            $this->secret->forget();

            return $request($this->secret->get(fresh: true));
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : ['body' => mb_substr($response->body(), 0, 500)];
    }

    /** Message d'erreur exploitable, quel que soit le format renvoye. */
    private function reason(Response $response): string
    {
        $payload = $this->payload($response);

        foreach (['message', 'error'] as $key) {
            if (! blank($payload[$key] ?? null) && is_string($payload[$key])) {
                return $payload[$key];
            }
        }

        if (is_array($payload['messages'] ?? null)) {
            return implode(' ', array_map('strval', $payload['messages']));
        }

        return sprintf('PVit a repondu HTTP %d.', $response->status());
    }

    /** @param  array<string, mixed>  $payload */
    private function failureFrom(array $payload): ?string
    {
        if ($this->state((string) ($payload['status'] ?? '')) !== PaymentState::Failed) {
            return null;
        }

        return is_string($payload['message'] ?? null) && $payload['message'] !== ''
            ? $payload['message']
            : 'Paiement refuse par l\'operateur.';
    }

    /**
     * Identifiant PVit de la transaction.
     *
     * PVIT NE LE NOMME PAS PAREIL SELON L'ENDPOINT, et ce n'est pas une
     * hypothese : l'initiation d'un paiement rend `reference_id`
     * (« PAY3108261252963 »), tandis que la documentation du webhook et l'API
     * de statut parlent de `transactionId`. Mesure faite sur le compte de test
     * le 2026-08-31.
     *
     * On accepte donc les deux formes. Sans cela, `provider_reference` restait
     * vide apres une initiation pourtant reussie — et le paiement devenait
     * INTERROGEABLE PAR PERSONNE : ni le repli par statut, ni le callback
     * n'avaient d'identifiant a quoi se rattacher.
     */
    private function transactionId(Response $response): ?string
    {
        $payload = $this->payload($response);

        foreach (['transactionId', 'transaction_id', 'reference_id', 'referenceId', 'id'] as $key) {
            if (! blank($payload[$key] ?? null)) {
                return (string) $payload[$key];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = (array) config('payments.pvit');

        foreach (['operation_account_code', 'callback_url_code'] as $required) {
            if (blank($config[$required] ?? null)) {
                throw new RuntimeException(sprintf(
                    'Configuration PVit incomplete : %s est vide.',
                    $required,
                ));
            }
        }

        return $config;
    }
}
