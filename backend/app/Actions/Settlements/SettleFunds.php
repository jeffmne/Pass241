<?php

namespace App\Actions\Settlements;

use App\Enums\AuditAction;
use App\Enums\PayoutState;
use App\Models\Settlement;
use App\Notifications\SettlementSent;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\PayoutRequest;
use App\Services\Payments\PayoutResult;
use App\Services\Settlement\Settlable;
use App\Services\Settlement\SettlementLedger;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Reverse a l'organisateur le net d'un evenement ou d'une collecte
 * (section 21.2, decisions D-026 et D-041).
 *
 * QUATRE GARANTIES tiennent ensemble ici, et aucune ne peut sauter :
 *
 * 1. UN SEUL VIREMENT. Une contrainte d'unicite sur le couple polymorphe et une
 *    cle d'idempotence unique en base : deux workers traitant le meme objet au
 *    meme instant ne peuvent pas produire deux ordres. Un virement rejoue
 *    envoie l'argent deux fois, et une somme envoyee en double ne se recupere
 *    pas.
 *
 * 2. LE MONTANT NE VIENT JAMAIS D'UNE REQUETE. Il est calcule a partir des
 *    lignes de commission deja figees, ecrit une fois, puis gele par
 *    declencheur. Une nouvelle tentative reutilise le montant d'origine.
 *
 * 3. RIEN NE PART SANS DESTINATION VALIDE. L'organisation doit pouvoir
 *    recevoir : destination renseignee, non suspendue, non refusee.
 *
 * 4. UN ETAT INCONNU N'EST JAMAIS UN ECHEC. Seul un echec explicite du
 *    prestataire autorise une nouvelle tentative. Conclure trop vite
 *    conduirait a payer une seconde fois un virement peut-etre deja parti —
 *    c'est le job de reconciliation qui tranche.
 *
 * Aucun ecran ne declenche cette action : elle vient d'une commande planifiee.
 * Il n'existe pas de bouton « retirer » dans le produit (section 21.2).
 */
class SettleFunds
{
    public function __construct(
        private readonly SettlementLedger $ledger,
        private readonly PaymentManager $payments,
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * @param  bool  $force  ignore la date d'echeance, pour les tests et le support
     */
    public function handle(Settlable&Model $settlable, bool $force = false): ?Settlement
    {
        $organization = $settlable->settlementOrganization();

        if (! $organization) {
            return null;
        }

        return $this->context->runFor($organization, function () use ($settlable, $organization, $force) {
            if (! $force && ! $this->ledger->isDue($settlable)) {
                return null;
            }

            if (! $organization->canReceivePayouts()) {
                // Pas une erreur : l'organisateur n'a simplement pas encore
                // renseigne son numero. La commande repassera demain.
                return null;
            }

            $settlement = $this->settlementFor($settlable, $organization);

            if ($settlement === null) {
                return null;
            }

            // Deja accepte par le prestataire : ne jamais renvoyer l'ordre.
            if (! $settlement->isRetryable() && $settlement->status !== PayoutState::Pending) {
                return $settlement;
            }

            return $this->send($settlement, $settlable);
        });
    }

    /**
     * Retrouve ou cree la ligne de reversement.
     *
     * Renvoie null quand il n'y a rien a reverser : aucune transaction
     * confirmee, ou un net sous le seuil ou les frais fixes du prestataire
     * mangeraient l'essentiel du virement.
     */
    private function settlementFor(Settlable&Model $settlable, $organization): ?Settlement
    {
        $existing = $this->existingFor($settlable);

        if ($existing) {
            return $existing;
        }

        $totals = $this->ledger->totalsFor($settlable);
        $minimum = (int) config('payments.payouts.minimum_amount');

        if ($totals['net'] <= 0 || $totals['net'] < $minimum) {
            return null;
        }

        try {
            $settlement = DB::transaction(fn () => Settlement::create([
                'settlable_type' => $settlable->getMorphClass(),
                'settlable_id' => $settlable->getKey(),
                'reference' => $this->uniqueReference(),
                'gross_amount' => $totals['gross'],
                'commission_amount' => $totals['commission'],
                'net_amount' => $totals['net'],
                // Instantane du beneficiaire : si le numero change demain, ce
                // reversement doit continuer a dire ou l'argent est parti.
                'recipient_phone' => $organization->payout_phone,
                'recipient_operator' => $organization->payout_operator->value,
                'recipient_name' => $organization->payout_name,
                'status' => PayoutState::Pending->value,
                'psp' => $this->payments->driver()->name(),
                'idempotency_key' => $this->idempotencyKey($settlable),
                'scheduled_for' => $this->ledger->releaseDateFor($settlable),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Un autre worker vient de creer la ligne. C'est exactement ce que
            // la contrainte protege : on reprend la sienne plutot que d'en
            // creer une seconde.
            return $this->existingFor($settlable);
        }

        $this->audit->log(
            AuditAction::SettlementCreated,
            $settlement,
            [
                'reference' => $settlement->reference,
                'gross' => $settlement->gross_amount,
                'commission' => $settlement->commission_amount,
                'net' => $settlement->net_amount,
                'transactions' => $totals['transactions'],
                'recipient' => $settlement->maskedRecipient(),
            ],
            actor: null,
            organizationId: $organization->getKey(),
        );

        return $settlement;
    }

    private function existingFor(Settlable&Model $settlable): ?Settlement
    {
        return Settlement::query()
            ->where('settlable_type', $settlable->getMorphClass())
            ->where('settlable_id', $settlable->getKey())
            ->first();
    }

    /** Transmet l'ordre au prestataire et enregistre sa reponse. */
    private function send(Settlement $settlement, Settlable&Model $settlable): Settlement
    {
        if (! $this->payments->supportsSettlement()) {
            throw new RuntimeException(
                'Le prestataire configure ne sait pas reverser : aucun versement '
                .'automatique n\'est possible (section 21.2).'
            );
        }

        try {
            $result = $this->payments->settlement()->settle(new PayoutRequest(
                recipientPhone: $settlement->recipient_phone,
                recipientOperator: $settlement->recipient_operator,
                // Le montant vient de la ligne figee, jamais d'un recalcul.
                amount: $settlement->net_amount,
                reference: $settlement->reference,
                idempotencyKey: $settlement->idempotency_key,
                // Une date deja passee n'est pas une programmation : la
                // transmettre telle quelle demanderait au prestataire
                // d'executer dans le passe. Null vaut « des que possible ».
                releaseAt: $settlement->scheduled_for?->isFuture()
                    ? $settlement->scheduled_for
                    : null,
                label: 'Pass241 '.$settlable->settlementLabel(),
            ));
        } catch (Throwable $e) {
            report($e);

            // Une exception n'est PAS un echec de virement : le prestataire a
            // pu recevoir l'ordre avant de perdre la connexion. L'etat reste
            // inconnu, et c'est la reconciliation qui tranchera.
            return $this->recordUnknown($settlement, $e->getMessage());
        }

        return $this->record($settlement, $result);
    }

    private function record(Settlement $settlement, PayoutResult $result): Settlement
    {
        $settlement->forceFill([
            'status' => $result->state->value,
            'provider_reference' => $result->providerReference ?? $settlement->provider_reference,
            'recipient_name' => $result->recipientName ?? $settlement->recipient_name,
            'settled_at' => $result->succeeded() ? now() : null,
            'failed_at' => $result->failed() ? now() : null,
            'failure_reason' => $result->failureReason,
        ])->save();

        $action = match (true) {
            $result->succeeded() => AuditAction::SettlementSucceeded,
            $result->failed() => AuditAction::SettlementFailed,
            default => AuditAction::SettlementAccepted,
        };

        $this->audit->log(
            $action,
            $settlement,
            [
                'reference' => $settlement->reference,
                'net' => $settlement->net_amount,
                'state' => $result->state->value,
                'provider_reference' => $result->providerReference,
                'reason' => $result->failureReason,
            ],
            actor: null,
            organizationId: $settlement->organization_id,
        );

        if ($result->accepted()) {
            $this->notifyOrganizer($settlement);
        }

        return $settlement;
    }

    /**
     * Previent l'organisateur que son argent part (section 69).
     *
     * Envoye des que le prestataire ACCEPTE l'ordre, pas seulement quand le
     * virement aboutit : un reversement programme reste en attente plusieurs
     * heures, et laisser l'organisateur sans nouvelle pendant ce temps le
     * pousse a telephoner au support — c'est la question la plus posee au
     * support d'une billetterie.
     *
     * Sans jamais interrompre : l'ordre est parti, un serveur de messagerie
     * indisponible ne doit pas le remettre en cause.
     */
    private function notifyOrganizer(Settlement $settlement): void
    {
        try {
            $settlement->loadMissing('settlable');
            $settlement->organization->notify(new SettlementSent($settlement));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function recordUnknown(Settlement $settlement, string $reason): Settlement
    {
        $settlement->forceFill([
            'status' => PayoutState::Unknown->value,
            'failure_reason' => $reason,
        ])->save();

        return $settlement;
    }

    /**
     * Cle stable pour un objet donne.
     *
     * Doit rester identique d'une tentative a l'autre : c'est ce qui permet au
     * prestataire de reconnaitre un ordre deja recu et de le refuser. Le type
     * y figure — un evenement et une collecte peuvent porter le meme
     * identifiant.
     */
    private function idempotencyKey(Settlable&Model $settlable): string
    {
        return 'settlement-'.Str::lower(class_basename($settlable)).'-'.$settlable->getKey();
    }

    /** Reference lisible, du type RV-4KX9-27B. */
    private function uniqueReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $block = fn (int $length) => collect(range(1, $length))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');

            $reference = 'RV-'.$block(4).'-'.$block(3);
        } while (Settlement::query()->acrossOrganizations()->where('reference', $reference)->exists());

        return $reference;
    }
}
