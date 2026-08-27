<?php

namespace App\Jobs;

use App\Actions\Payments\ConfirmPayment;
use App\Models\PaymentWebhook;
use App\Services\Payments\Payable;
use App\Services\Payments\PayableResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Traite un callback de paiement deja recu et journalise (sections 22, 23).
 *
 * Idempotent a deux niveaux : ce job sort immediatement si le message a deja
 * ete traite, et ConfirmPayment sort a son tour si la transaction est deja
 * confirmee. Les deux sont necessaires — le premier evite le travail inutile,
 * le second protege contre deux messages distincts portant la meme
 * confirmation.
 *
 * Depuis la decision D-041, ce job ne sait plus ce qu'il confirme : une
 * commande de billets, un don, demain un vote payant. Il resout la reference,
 * verifie le montant, et laisse `ConfirmPayment` faire le reste.
 */
class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $webhookId) {}

    public function handle(ConfirmPayment $confirmPayment, PayableResolver $payables): void
    {
        $webhook = PaymentWebhook::find($this->webhookId);

        if (! $webhook || $webhook->wasProcessed() || ! $webhook->signature_valid) {
            return;
        }

        $payload = $webhook->payload ?? [];
        $reference = $payload['order_reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            $webhook->markProcessed('Message sans reference de transaction.');

            return;
        }

        // Un worker n'a aucun contexte d'organisation : la transaction est
        // retrouvee hors portee, puis ConfirmPayment retablit le contexte.
        $payable = $payables->byReference($reference);

        if (! $payable) {
            $webhook->markProcessed('Transaction introuvable : '.$reference);

            return;
        }

        $webhook->forceFill([
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
        ])->save();

        $status = $payload['status'] ?? null;

        if ($status === 'SUCCEEDED') {
            $this->confirm($webhook, $payable, $confirmPayment, $payload);

            return;
        }

        if ($status === 'FAILED') {
            $reason = is_string($payload['failure_reason'] ?? null)
                ? $payload['failure_reason']
                : 'Paiement refuse par l\'operateur.';

            $confirmPayment->markFailed($payable, $reason);
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
        Payable&Model $payable,
        ConfirmPayment $confirmPayment,
        array $payload,
    ): void {
        /*
         * Verification du montant.
         *
         * Un montant different de celui attendu signale soit une erreur de
         * l'operateur, soit une tentative de manipulation. Dans les deux cas,
         * confirmer serait pire que ne rien faire : le message est conserve et
         * signale, la transaction reste en attente.
         */
        $amount = $payload['amount'] ?? null;

        if (is_numeric($amount) && (int) $amount !== $payable->paymentAmount()) {
            $webhook->markProcessed(sprintf(
                'Montant incoherent : %s recu, %s attendu.',
                (int) $amount,
                $payable->paymentAmount(),
            ));

            return;
        }

        try {
            $confirmPayment->handle(
                $payable,
                is_string($payload['provider_reference'] ?? null) ? $payload['provider_reference'] : null,
            );

            $webhook->markProcessed('Paiement confirme.');
        } catch (RuntimeException $e) {
            /*
             * Section 17.1 : le stock ne suffit plus. L'argent est encaisse,
             * donc la transaction ne peut pas simplement echouer — elle doit
             * donner lieu a un remboursement.
             *
             * Le message est marque traite avec le motif : c'est ce qui permet
             * de retrouver ces cas ensuite.
             */
            $webhook->markProcessed('A rembourser — '.$e->getMessage());
        }
    }
}
