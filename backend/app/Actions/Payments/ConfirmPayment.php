<?php

namespace App\Actions\Payments;

use App\Services\Audit\AuditLogger;
use App\Services\Commission\CommissionCalculator;
use App\Services\Payments\Payable;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Confirmation d'un encaissement (sections 21.2, 23, decision D-041).
 *
 * LE SEUL ENDROIT OU DE L'ARGENT DEVIENT ACQUIS, quel que soit le module :
 * une commande de billets aujourd'hui, un don ensuite, un vote payant plus
 * tard. Quatre garanties tiennent ensemble ici, et aucune ne peut sauter sans
 * consequence :
 *
 * 1. IDEMPOTENCE (section 23). Une transaction deja confirmee ressort telle
 *    quelle. Les webhooks mobile money sont rejoues, c'est normal, et un rejeu
 *    ne doit jamais produire un second effet ni une seconde commission.
 *
 * 2. VERROU DE LIGNE. Deux livraisons simultanees du meme message arrivent
 *    toutes deux ici : la seconde attend, puis constate que c'est fait. Sans
 *    lui, le test d'idempotence serait gagnable par les deux a la fois.
 *
 * 3. TOUT OU RIEN. Statut, consequences metier et commission sont ecrits dans
 *    une seule transaction. Une commande payee sans billets, ou un don confirme
 *    sans commission, seraient l'un et l'autre impossibles a rattraper
 *    proprement.
 *
 * 4. COMMISSION CALCULEE (regles 80.1 et 80.2). Jamais saisie, taux fige au
 *    moment de la transaction, assise sur `commissionBase()` et jamais sur le
 *    montant debite — celui-ci contient les frais du prestataire, avances par
 *    le payeur (decision D-023).
 *
 * CE QUI EST PROPRE A CHAQUE MODULE vit dans le `Payable` : le stock et les
 * billets pour une commande, rien de tel pour un don. Cette action ne sait pas
 * ce qu'elle confirme, et c'est le but — elle ne peut donc pas diverger d'un
 * module a l'autre.
 */
class ConfirmPayment
{
    public function __construct(
        private readonly CommissionCalculator $commissions,
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * @param  string|null  $providerReference  identifiant de la transaction chez l'operateur
     *
     * @throws RuntimeException si la transaction ne peut plus etre confirmee
     */
    public function handle(Payable&Model $payable, ?string $providerReference = null): Payable&Model
    {
        // Section 23 : seul le premier passage confirme. Les suivants sortent
        // sans rien faire.
        if ($payable->isPaymentConfirmed()) {
            return $payable;
        }

        if ($reason = $payable->paymentRefusalReason()) {
            throw new RuntimeException($reason);
        }

        $organization = $payable->paymentOrganization()
            ?? throw new RuntimeException('Cette transaction n\'a plus d\'organisation.');

        $confirmed = $this->context->runFor($organization, function () use (
            $payable, $providerReference, $organization
        ) {
            return DB::transaction(function () use ($payable, $providerReference, $organization) {
                $fresh = $payable->lockedForPayment();

                if (! $fresh || $fresh->isPaymentConfirmed()) {
                    return $fresh ?? $payable;
                }

                if ($reason = $fresh->paymentRefusalReason()) {
                    throw new RuntimeException($reason);
                }

                // Ce qui peut encore refuser : le stock d'une commande, dont la
                // reservation a pu expirer entre la validation sur le telephone
                // et l'arrivee du callback (section 17.1).
                $fresh->guardPaymentConfirmation();

                $fresh->applyPaymentConfirmed($providerReference);

                $this->commissions->record(
                    commissionable: $fresh,
                    organization: $organization,
                    grossAmount: $fresh->commissionBase(),
                    type: $fresh->commissionType(),
                );

                $this->audit->log(
                    $fresh->confirmedAuditAction(),
                    $fresh,
                    $fresh->paymentAuditContext(),
                    actor: null,
                    organizationId: $organization->getKey(),
                );

                return $fresh;
            });
        });

        /*
         * Emails et notifications, HORS de la transaction : un serveur de
         * messagerie indisponible ne doit jamais defaire un encaissement. Le
         * `try` reste utile — en file synchrone, l'echec remonterait ici.
         */
        try {
            $confirmed->afterPaymentConfirmed();
        } catch (Throwable $e) {
            report($e);
        }

        return $confirmed;
    }

    /**
     * Marque un paiement comme echoue (section 21.1).
     *
     * Distinct de l'expiration : ici l'operateur a repondu, et sa reponse est
     * un refus. Le motif est conserve pour que le payeur sache s'il doit
     * reessayer ou recharger son compte.
     */
    public function markFailed(Payable&Model $payable, string $reason): Payable&Model
    {
        if (! $payable->isPaymentPending()) {
            return $payable;
        }

        try {
            // Un callback d'echec peut arriver pendant la confirmation. Le
            // statut relu sous le meme verrou ne doit jamais retrograder un
            // paiement acquis depuis la premiere lecture du modele.
            $changed = DB::transaction(function () use ($payable, $reason) {
                $fresh = $payable->lockedForPayment();

                if (! $fresh || ! $fresh->isPaymentPending()) {
                    return false;
                }

                $fresh->applyPaymentFailed($reason);

                return true;
            });

            $payable->refresh();

            if (! $changed) {
                return $payable;
            }
        } catch (Throwable $e) {
            report($e);

            return $payable;
        }

        $this->audit->log(
            $payable->failedAuditAction(),
            $payable,
            ['reference' => $payable->paymentReference(), 'reason' => $reason],
            actor: null,
            organizationId: $payable->paymentOrganization()?->getKey(),
        );

        return $payable;
    }
}
