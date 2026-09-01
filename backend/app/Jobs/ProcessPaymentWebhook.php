<?php

namespace App\Jobs;

use App\Actions\Payments\ConfirmPayment;
use App\Enums\PaymentState;
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

        /*
         * LECTURE NORMALISEE, posee par le controleur depuis le `PaymentResult`
         * du driver.
         *
         * Ce job ne connait donc AUCUN dialecte de prestataire. Il en
         * connaissait un — celui du provider de simulation, avec ses
         * `order_reference` et ses `SUCCEEDED` — et cela ne se voyait pas tant
         * qu'il n'y en avait qu'un. Un second prestataire, qui parle de
         * `merchantReferenceId` et de `SUCCESS`, aurait echoue en silence :
         * aucune reference trouvee, message marque traite, paiement jamais
         * confirme.
         *
         * Le repli sur les cles d'origine couvre les messages enregistres avant
         * cette normalisation. Il peut disparaitre une fois la table purgee.
         */
        $normalized = is_array($payload['_normalized'] ?? null) ? $payload['_normalized'] : [];

        $reference = $normalized['order_reference'] ?? $payload['order_reference'] ?? null;

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

        /*
         * L'etat vient du `PaymentState` normalise, et non d'une chaine du
         * prestataire. Un `AMBIGUOUS` de PVit y arrive en `UNKNOWN`, et tombera
         * donc dans le cas « etat non definitif » ci-dessous — ce qui est le
         * seul traitement juste : ni confirmer un paiement dont le prestataire
         * doute lui-meme, ni le refuser alors que l'argent est peut-etre parti.
         */
        $state = $normalized['state'] ?? $payload['status'] ?? null;

        if ($state === PaymentState::Succeeded->value || $state === 'SUCCEEDED') {
            $this->confirm($webhook, $payable, $confirmPayment, $normalized ?: $payload);

            return;
        }

        if ($state === PaymentState::Failed->value || $state === 'FAILED') {
            $reason = $normalized['failure_reason'] ?? $payload['failure_reason'] ?? null;

            $confirmPayment->markFailed(
                $payable,
                is_string($reason) && $reason !== '' ? $reason : 'Paiement refuse par l\'operateur.',
            );
            $webhook->markProcessed('Paiement refuse.');

            return;
        }

        // Etat intermediaire : rien a faire, mais le message est marque traite
        // pour ne pas etre repris indefiniment.
        $webhook->markProcessed('Etat non definitif : '.(is_string($state) ? $state : 'inconnu'));
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
