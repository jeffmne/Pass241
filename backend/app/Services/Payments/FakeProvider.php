<?php

namespace App\Services\Payments;

use App\Enums\PaymentState;
use App\Models\Order;
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
 */
class FakeProvider implements PaymentProviderInterface
{
    public function name(): string
    {
        return 'fake';
    }

    public function initializePayment(Order $order): PaymentResult
    {
        $reference = 'FAKE-'.Str::upper(Str::random(12));

        $this->remember($order, PaymentState::Pending);

        return new PaymentResult(
            state: PaymentState::Pending,
            providerReference: $reference,
            orderReference: $order->reference,
            amount: $order->amount,
            raw: ['simulated' => true, 'pushed_to' => $order->payer_phone],
        );
    }

    public function checkPaymentStatus(Order $order): PaymentResult
    {
        return new PaymentResult(
            state: $this->recall($order),
            providerReference: $order->provider_reference,
            orderReference: $order->reference,
            amount: $order->amount,
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

    public function refund(Order $order, int $amount, string $reason): PaymentResult
    {
        return new PaymentResult(
            state: PaymentState::Succeeded,
            providerReference: 'FAKE-REFUND-'.Str::upper(Str::random(10)),
            orderReference: $order->reference,
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
    private function recall(Order $order): PaymentState
    {
        $stored = Cache::get($this->key($order));

        return $stored ? PaymentState::from($stored) : PaymentState::Unknown;
    }

    public function remember(Order $order, PaymentState $state): void
    {
        Cache::put($this->key($order), $state->value, now()->addHour());
    }

    private function key(Order $order): string
    {
        return 'fake-payment:'.$order->reference;
    }
}
