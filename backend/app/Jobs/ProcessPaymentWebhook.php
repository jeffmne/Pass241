<?php

namespace App\Jobs;

use App\Actions\Orders\ConfirmPayment;
use App\Models\Order;
use App\Models\PaymentWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Traite un callback de paiement deja recu et journalise (sections 22, 23).
 *
 * Idempotent a deux niveaux : ce job sort immediatement si le message a deja
 * ete traite, et ConfirmPayment sort a son tour si la commande est deja payee.
 * Les deux sont necessaires — le premier evite le travail inutile, le second
 * protege contre deux messages distincts portant la meme confirmation.
 */
class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $webhookId) {}

    public function handle(ConfirmPayment $confirmPayment): void
    {
        $webhook = PaymentWebhook::find($this->webhookId);

        if (! $webhook || $webhook->wasProcessed() || ! $webhook->signature_valid) {
            return;
        }

        $payload = $webhook->payload ?? [];
        $orderReference = $payload['order_reference'] ?? null;

        if (! is_string($orderReference) || $orderReference === '') {
            $webhook->markProcessed('Message sans reference de commande.');

            return;
        }

        // Un worker n'a aucun contexte d'organisation : la commande est
        // retrouvee hors portee, puis ConfirmPayment retablit le contexte.
        $order = Order::query()->acrossOrganizations()->where('reference', $orderReference)->first();

        if (! $order) {
            $webhook->markProcessed('Commande introuvable : '.$orderReference);

            return;
        }

        $webhook->forceFill(['order_id' => $order->getKey()])->save();

        $status = $payload['status'] ?? null;

        if ($status === 'SUCCEEDED') {
            $this->confirm($webhook, $order, $confirmPayment, $payload);

            return;
        }

        if ($status === 'FAILED') {
            $reason = is_string($payload['failure_reason'] ?? null)
                ? $payload['failure_reason']
                : 'Paiement refuse par l\'operateur.';

            $confirmPayment->markFailed($order, $reason);
            $webhook->markProcessed('Paiement refuse.');

            return;
        }

        // Etat intermediaire : rien a faire, mais le message est marque traite
        // pour ne pas etre repris indefiniment.
        $webhook->markProcessed('Etat non definitif : '.(is_string($status) ? $status : 'inconnu'));
    }

    /** @param  array<string, mixed>  $payload */
    private function confirm(
        PaymentWebhook $webhook,
        Order $order,
        ConfirmPayment $confirmPayment,
        array $payload,
    ): void {
        /*
         * Verification du montant.
         *
         * Un montant different de celui de la commande signale soit une erreur
         * de l'operateur, soit une tentative de manipulation. Dans les deux
         * cas, confirmer serait pire que ne rien faire : le message est
         * conserve et signale, la commande reste en attente.
         */
        $amount = $payload['amount'] ?? null;

        if (is_numeric($amount) && (int) $amount !== $order->amount) {
            $webhook->markProcessed(sprintf(
                'Montant incoherent : %s recu, %s attendu.',
                (int) $amount,
                $order->amount,
            ));

            return;
        }

        try {
            $confirmPayment->handle(
                $order,
                is_string($payload['provider_reference'] ?? null) ? $payload['provider_reference'] : null,
            );

            $webhook->markProcessed('Paiement confirme.');
        } catch (RuntimeException $e) {
            /*
             * Section 17.1 : le stock ne suffit plus. L'argent est encaisse,
             * donc la commande ne peut pas simplement echouer — elle doit
             * donner lieu a un remboursement.
             *
             * Le message est marque traite avec le motif : c'est ce qui
             * permettra a l'organisateur, puis au module de remboursement, de
             * retrouver ces cas.
             */
            $webhook->markProcessed('A rembourser — '.$e->getMessage());
        }
    }
}
