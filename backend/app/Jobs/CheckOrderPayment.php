<?php

namespace App\Jobs;

use App\Actions\Payments\ConfirmPayment;
use App\Enums\PaymentState;
use App\Models\Order;
use App\Services\Payments\PaymentManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Le secours PSP ne bloque aucun worker HTTP pendant l'attente reseau. */
class CheckOrderPayment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('payment-checks');
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function handle(PaymentManager $payments, ConfirmPayment $confirm): void
    {
        $order = Order::query()->acrossOrganizations()->find($this->orderId);

        if (! $order || ! $order->isPending()) {
            return;
        }

        $key = 'payment-check:'.$this->orderId;
        if (! Cache::add($key, true, 20)) {
            return;
        }

        try {
            $result = $payments->driver($order->psp)->checkPaymentStatus($order);

            if ($result->state === PaymentState::Succeeded) {
                // Meme verification du montant que le chemin webhook.
                if (($result->amount !== null && $result->amount !== $order->paymentAmount())
                    || ($result->subtotal !== null && $result->subtotal !== $order->commissionBase())
                    || ($result->amount === null && $result->subtotal === null)) {
                    throw new \RuntimeException('Montant de paiement incoherent.');
                }
                $confirm->handle($order, $result->providerReference);
            } elseif ($result->state === PaymentState::Failed) {
                $confirm->markFailed($order, $result->failureReason ?? 'Paiement refuse par l\'operateur.');
            }
        } catch (Throwable $error) {
            Cache::forget($key);
            throw $error;
        }
    }
}
