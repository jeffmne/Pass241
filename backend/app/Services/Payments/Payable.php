<?php

namespace App\Services\Payments;

use App\Enums\AuditAction;
use App\Enums\CommissionType;
use App\Enums\PaymentProvider;
use App\Models\Organization;

/**
 * Ce qui peut etre encaisse (decision D-041).
 *
 * Une commande de billets et un don n'ont presque rien en commun — l'une a un
 * evenement, du stock et des billets, l'autre une campagne et un donateur. Mais
 * LE CHEMIN DE L'ARGENT EST LE MEME : idempotence face a un webhook rejoue,
 * verrou de ligne, verification du montant, commission au taux fige, journal.
 *
 * Ce contrat existe pour que ce chemin ne soit ecrit qu'une fois. Sans lui, le
 * module Collectes puis le module Votes reecriraient chacun leur idempotence,
 * et la seconde divergerait de la premiere — du cote le moins parcouru, donc
 * celui ou l'erreur se verrait le plus tard.
 *
 * CE QUI EST PARTAGE vit dans `ConfirmPayment`. CE QUI EST PROPRE au module vit
 * dans ces methodes : le stock pour une commande, rien pour un don ; les
 * billets pour l'une, la progression de la collecte pour l'autre.
 *
 * Toute implementation est un modele Eloquent : la commission s'y rattache de
 * facon polymorphe, et le verrou porte sur sa ligne.
 */
interface Payable
{
    /*
     * -----------------------------------------------------------------------
     * Ce que le prestataire a besoin de savoir
     * -----------------------------------------------------------------------
     */

    /** Reference lisible, citee au support et portee par les webhooks. */
    public function paymentReference(): string;

    /** Ce que le prestataire doit encaisser, frais compris (decision D-023). */
    public function paymentAmount(): int;

    /** Identifiant de la transaction chez l'operateur, une fois connu. */
    public function providerReference(): ?string;

    /** Numero vers lequel pousser la demande de paiement (section 21.1). */
    public function payerPhone(): ?string;

    public function paymentOperator(): ?PaymentProvider;

    /*
     * -----------------------------------------------------------------------
     * Ce que la plateforme preleve
     * -----------------------------------------------------------------------
     */

    /**
     * Assiette de la commission Pass241.
     *
     * JAMAIS le montant debite : celui-ci contient les frais du prestataire,
     * avances par le payeur (decision D-023). Les confondre ferait prelever une
     * commission sur l'argent d'un tiers.
     */
    public function commissionBase(): int;

    public function commissionType(): CommissionType;

    /** L'organisation qui recevra le net. Le contexte y est pose pour ecrire. */
    public function paymentOrganization(): ?Organization;

    /*
     * -----------------------------------------------------------------------
     * Etat
     * -----------------------------------------------------------------------
     */

    /** Deja encaisse : un second passage ne doit rien produire (section 23). */
    public function isPaymentConfirmed(): bool;

    /** En attente du payeur. */
    public function isPaymentPending(): bool;

    /**
     * Raison pour laquelle ce paiement ne peut plus aboutir, ou null.
     *
     * Rendre une phrase et non un booleen : elle remonte telle quelle a
     * l'ecran, et « une commande annulee ne peut plus etre payee » renseigne la
     * ou un `false` laisse chercher.
     */
    public function paymentRefusalReason(): ?string;

    /*
     * -----------------------------------------------------------------------
     * Confirmation — l'ordre des trois etapes compte
     * -----------------------------------------------------------------------
     */

    /**
     * Relecture VERROUILLEE de la ligne, hors portee d'organisation.
     *
     * C'est ce verrou qui serialise deux webhooks livres en parallele : le
     * second attend, puis constate que le paiement est deja confirme. Sans lui,
     * le test d'idempotence serait gagnable par les deux a la fois.
     */
    public function lockedForPayment(): ?static;

    /**
     * Dans la transaction, AVANT toute ecriture : ce qui peut encore refuser.
     *
     * Une commande y verifie que le stock suffit toujours (section 17.1) — la
     * reservation a pu expirer entre la validation sur le telephone et
     * l'arrivee du callback. Un don n'a rien a verifier.
     *
     * @throws \RuntimeException si la confirmation doit etre refusee
     */
    public function guardPaymentConfirmation(): void;

    /**
     * Dans la transaction : ecrit l'encaissement et ses consequences directes.
     *
     * Statut, date, reference du prestataire — puis ce qui n'a de sens que pour
     * ce module : l'emission des billets pour une commande.
     */
    public function applyPaymentConfirmed(?string $providerReference): void;

    /**
     * HORS transaction : ce qui ne doit jamais annuler un paiement acquis.
     *
     * Emails et notifications. Un serveur de messagerie indisponible ne peut
     * pas defaire un encaissement.
     */
    public function afterPaymentConfirmed(): void;

    public function applyPaymentFailed(string $reason): void;

    /*
     * -----------------------------------------------------------------------
     * Journal (sections 51, 68)
     * -----------------------------------------------------------------------
     */

    /** @return array<string, mixed> lu APRES confirmation */
    public function paymentAuditContext(): array;

    public function confirmedAuditAction(): AuditAction;

    public function failedAuditAction(): AuditAction;
}
