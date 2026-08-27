<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Enums\PaymentState;
use App\Enums\PayoutState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Provider de developpement (decision D-002).
 *
 * Aucun acces Airtel Money ni Moov Money n'est disponible. Ce provider simule
 * le flux complet de la section 21.1 — push USSD, validation, refus, absence de
 * callback jusqu'au delai — pour que tout ce qui compte reellement puisse etre
 * construit et teste : l'idempotence des webhooks, le calcul de commission,
 * l'emission des billets, la verification atomique du stock.
 *
 * Ce sont ces parties-la qui sont risquees, pas l'appel HTTP a l'operateur.
 *
 * L'etat simule vit dans le cache, jamais en base : rien de ce provider ne doit
 * survivre a l'environnement de developpement.
 *
 * Implemente les DEUX contrats (decision D-024) : il encaisse et il reverse.
 * C'est volontairement le cas le plus favorable — un prestataire complet, avec
 * virement programme. Cela permet de construire le reversement apres evenement
 * du jalon D sans attendre un contrat, tout en gardant a l'esprit qu'aucun
 * prestataire reel n'a encore confirme ces capacites.
 */
class FakeProvider implements CollectionProvider, SettlementProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function capabilities(): PaymentCapabilities
    {
        return new PaymentCapabilities(
            // Faux : meme en simulation, on ne se donne pas la capacite la plus
            // confortable. L'eclatement natif change l'architecture entiere du
            // reversement, et le supposer acquis conduirait a construire le
            // jalon D sur une hypothese qu'aucun prestataire gabonais n'a
            // confirmee.
            nativeSplit: false,
            payout: true,
            scheduledPayout: true,
            partialRefund: true,
            recipientNameLookup: true,
        );
    }

    public function initializePayment(Payable $payable): PaymentResult
    {
        $reference = 'FAKE-'.Str::upper(Str::random(12));

        $this->remember($payable, PaymentState::Pending);

        return new PaymentResult(
            state: PaymentState::Pending,
            providerReference: $reference,
            orderReference: $payable->paymentReference(),
            amount: $payable->paymentAmount(),
            raw: ['simulated' => true, 'pushed_to' => $payable->payerPhone()],
        );
    }

    public function checkPaymentStatus(Payable $payable): PaymentResult
    {
        return new PaymentResult(
            state: $this->recall($payable),
            providerReference: $payable->providerReference(),
            orderReference: $payable->paymentReference(),
            amount: $payable->paymentAmount(),
        );
    }

    public function processCallback(Request $request): ?PaymentResult
    {
        // Meme en simulation, la signature est verifiee : c'est le
        // comportement que les vrais providers devront reproduire, et un
        // webhook non authentifie ne doit jamais confirmer un paiement
        // (section 22).
        if (! $this->signatureIsValid($request)) {
            return null;
        }

        $state = match ($request->input('status')) {
            'SUCCEEDED' => PaymentState::Succeeded,
            'FAILED' => PaymentState::Failed,
            default => PaymentState::Unknown,
        };

        return new PaymentResult(
            state: $state,
            providerReference: $request->input('provider_reference'),
            // Identifiant du message : c'est lui qui porte l'idempotence
            // (section 23).
            eventId: $request->input('event_id'),
            failureReason: $request->input('failure_reason'),
            orderReference: $request->input('order_reference'),
            amount: $request->integer('amount') ?: null,
            raw: $request->all(),
        );
    }

    public function refund(Payable $payable, int $amount, string $reason): PaymentResult
    {
        return new PaymentResult(
            state: PaymentState::Succeeded,
            providerReference: 'FAKE-REFUND-'.Str::upper(Str::random(10)),
            orderReference: $payable->paymentReference(),
            amount: $amount,
            raw: ['simulated' => true, 'reason' => $reason],
        );
    }

    /*
     * ---------------------------------------------------------------------
     * Simulation
     * ---------------------------------------------------------------------
     */

    /**
     * Signature attendue d'un webhook simule.
     *
     * HMAC de la charge utile avec la cle de l'application. C'est la meme forme
     * que ce qu'attendront Airtel et Moov, pour que le code appelant n'ait rien
     * a changer le jour du branchement.
     */
    public static function sign(array $payload): string
    {
        ksort($payload);

        return hash_hmac('sha256', json_encode($payload), config('app.key'));
    }

    private function signatureIsValid(Request $request): bool
    {
        $signature = $request->header('X-Pass241-Signature');

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        // Comparaison a temps constant : une comparaison naive laisse fuir la
        // signature attendue, un caractere a la fois.
        return hash_equals(self::sign($request->except([])), $signature);
    }

    /** Etat simule, tel qu'un ecran de simulation l'a positionne. */
    private function recall(Payable $payable): PaymentState
    {
        $stored = Cache::get($this->key($payable));

        return $stored ? PaymentState::from($stored) : PaymentState::Unknown;
    }

    public function remember(Payable $payable, PaymentState $state): void
    {
        Cache::put($this->key($payable), $state->value, now()->addHour());
    }

    private function key(Payable $payable): string
    {
        return 'fake-payment:'.$payable->paymentReference();
    }

    /*
     * ---------------------------------------------------------------------
     * Reversement (SettlementProvider)
     * ---------------------------------------------------------------------
     */

    /**
     * Simule un ordre de reversement.
     *
     * IDEMPOTENT sur la cle de la requete, et ce n'est pas un detail de
     * simulation : c'est la propriete que les vrais prestataires devront
     * garantir. Un ordre rejoue envoie l'argent deux fois, et une somme
     * envoyee en double ne se recupere pas. La cle est donc consultee avant
     * toute chose, et la reponse d'origine renvoyee telle quelle.
     */
    public function settle(PayoutRequest $request): PayoutResult
    {
        $key = 'fake-payout:'.$request->idempotencyKey;

        if ($existing = Cache::get($key)) {
            return new PayoutResult(
                state: PayoutState::from($existing['state']),
                providerReference: $existing['reference'],
                recipientName: $existing['recipient_name'],
                raw: ['simulated' => true, 'replayed' => true],
            );
        }

        // Un virement date dans le futur reste dans les livres du prestataire
        // jusqu'a l'echeance : c'est ce qui evite a Pass241 d'etre depositaire
        // des fonds (section 21.2).
        $state = $request->releaseAt && $request->releaseAt->isFuture()
            ? PayoutState::Scheduled
            : PayoutState::Succeeded;

        $reference = 'FAKEPAY-'.Str::upper(Str::random(12));
        $recipientName = $this->lookupRecipientName(
            $request->recipientPhone,
            $request->recipientOperator,
        );

        Cache::put($key, [
            'state' => $state->value,
            'reference' => $reference,
            'recipient_name' => $recipientName,
        ], now()->addDay());

        Cache::put('fake-payout-ref:'.$reference, $state->value, now()->addDay());

        return new PayoutResult(
            state: $state,
            providerReference: $reference,
            recipientName: $recipientName,
            raw: [
                'simulated' => true,
                'amount' => $request->amount,
                'to' => $request->recipientPhone,
                'release_at' => $request->releaseAt?->toIso8601String(),
            ],
        );
    }

    public function checkPayout(string $providerReference): PayoutResult
    {
        $stored = Cache::get('fake-payout-ref:'.$providerReference);

        // Reference inconnue : UNKNOWN et jamais FAILED. Conclure a l'echec
        // conduirait a rejouer l'ordre, donc a payer deux fois.
        return new PayoutResult(
            state: $stored ? PayoutState::from($stored) : PayoutState::Unknown,
            providerReference: $providerReference,
            raw: ['simulated' => true],
        );
    }

    public function lookupRecipientName(string $phone, PaymentProvider $operator): ?string
    {
        // Un vrai prestataire interroge l'operateur. Ici, un nom stable
        // derive du numero suffit a exercer l'affichage de confirmation.
        return 'TITULAIRE '.$operator->value.' '.Str::substr($phone, -4);
    }
}
