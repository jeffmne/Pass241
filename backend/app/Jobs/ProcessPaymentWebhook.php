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

    public int $tries = 5;

    public array $backoff = [2, 5, 15, 30];

    public function __construct(public readonly int $webhookId)
    {
        $this->onQueue('payments');
    }

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
         *
         * DEUX NIVEAUX, parce que tous les prestataires ne donnent pas la meme
         * chose. Le total debite est ce qu'on prefere comparer ; certains ne
         * l'exposent pas — PVit facture en deux composantes et n'en publie
         * qu'une sur son API de statut. On compare alors le SOUS-TOTAL qu'il a
         * enregistre a celui qu'on lui a envoye. C'est moins, mais ce n'est pas
         * rien : sans ce repli, la verification sautait entierement, en
         * silence, sur le chemin de l'argent.
         */
        $verification = $this->verifyAmount($payable, $payload);

        if ($verification !== null) {
            $webhook->markProcessed($verification);

            return;
        }

        try {
            $confirmPayment->handle(
                $payable,
                is_string($payload['provider_reference'] ?? null) ? $payload['provider_reference'] : null,
            );

            /*
             * L'absence de verification est DITE, et non passee sous silence.
             *
             * Un prestataire qui ne rend ni total ni sous-total nous fait
             * confirmer sans rien pouvoir comparer. C'est peut-etre acceptable,
             * mais cela doit se voir dans le journal — c'est exactement le
             * genre de trou qui ne se remarque pas avant qu'il ne coute.
             */
            $webhook->markProcessed(
                $this->amountWasComparable($payload)
                    ? 'Paiement confirme.'
                    : 'Paiement confirme (montant non verifiable).'
            );
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

    /**
     * Compare ce que le prestataire a enregistre a ce que nous attendions.
     *
     * Rend le motif du refus, ou null si rien ne s'oppose a la confirmation —
     * y compris quand aucune comparaison n'etait possible. Ne pas pouvoir
     * verifier n'est pas une raison de refuser : l'argent est parti, et laisser
     * la transaction en attente indefiniment serait pire que de la confirmer.
     * C'est en revanche une raison de le DIRE, ce que fait l'appelant.
     *
     * @param  array<string, mixed>  $payload
     */
    private function verifyAmount(Payable&Model $payable, array $payload): ?string
    {
        $amount = $payload['amount'] ?? null;

        if (is_numeric($amount)) {
            return (int) $amount === $payable->paymentAmount()
                ? null
                : sprintf(
                    'Montant incoherent : %s recu, %s attendu.',
                    (int) $amount,
                    $payable->paymentAmount(),
                );
        }

        $subtotal = $payload['subtotal'] ?? null;

        if (is_numeric($subtotal)) {
            return (int) $subtotal === $payable->commissionBase()
                ? null
                : sprintf(
                    'Sous-total incoherent : %s enregistre chez le prestataire, %s attendu.',
                    (int) $subtotal,
                    $payable->commissionBase(),
                );
        }

        return null;
    }

    /** Le prestataire a-t-il donne de quoi verifier quoi que ce soit ? */
    private function amountWasComparable(array $payload): bool
    {
        return is_numeric($payload['amount'] ?? null)
            || is_numeric($payload['subtotal'] ?? null);
    }
}
