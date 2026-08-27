<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;

/**
 * Contrat d'ENCAISSEMENT (section 21).
 *
 * Anciennement `PaymentProviderInterface`, scinde en deux au jalon B
 * (decision D-024) : encaisser et reverser sont deux metiers, et tous les
 * prestataires ne font pas les deux. Un agregateur peut savoir prendre un
 * paiement mobile money sans exposer la moindre API de virement sortant.
 * Les reunir dans une seule interface obligeait chaque driver a mentir sur la
 * moitie de son contrat.
 *
 * Le reversement vit dans SettlementProvider, que ce meme driver implemente
 * ou non.
 *
 * Aucune methode ne modifie le statut de la transaction. Un provider decrit ce
 * que dit le prestataire ; c'est ConfirmPayment qui en tire les consequences.
 * Cette separation est ce qui rend l'idempotence possible (section 23).
 *
 * Le sujet est un `Payable` et non une commande (decision D-041) : un
 * prestataire encaisse un montant vers un numero, il n'a aucune raison de
 * savoir si ce qu'on lui fait payer est un billet ou un don.
 */
interface CollectionProvider
{
    /** Identifiant technique, tel qu'il apparait dans les URL de webhook. */
    public function name(): string;

    /**
     * Ce que ce prestataire sait faire (decision D-024).
     *
     * Declare par le driver et non lu en configuration : un prestataire ne
     * sait pas eclater un encaissement parce qu'un fichier l'affirme.
     */
    public function capabilities(): PaymentCapabilities;

    /**
     * Declenche le paiement : push USSD vers le numero de l'acheteur
     * (section 21.1).
     *
     * Retourne l'etat immediat, en general PENDING — l'acheteur doit encore
     * valider sur son telephone.
     */
    public function initializePayment(Payable $payable): PaymentResult;

    /**
     * Interroge le prestataire sur l'etat d'une transaction.
     *
     * Appelee en polling de secours pendant la fenetre de paiement : les
     * webhooks mobile money peuvent etre en retard ou manquants selon
     * l'operateur (section 21.1).
     */
    public function checkPaymentStatus(Payable $payable): PaymentResult;

    /**
     * Verifie l'authenticite d'un webhook et le traduit.
     *
     * Doit renvoyer null si la signature est invalide : un webhook non
     * authentifie ne doit jamais confirmer un paiement (section 22).
     */
    public function processCallback(Request $request): ?PaymentResult;

    /**
     * Rembourse tout ou partie d'une transaction (section 24).
     *
     * Reste ici, et non dans SettlementProvider : un remboursement annule un
     * encaissement et repart vers l'acheteur. Un reversement, lui, va vers
     * l'organisateur. Confondre les deux flux ferait rembourser depuis la
     * mauvaise poche.
     *
     * Le montant est en entiers XAF. L'operation est traitee comme une
     * transaction distincte, liee a la transaction d'origine, jamais comme une
     * modification du paiement initial (section 46).
     */
    public function refund(Payable $payable, int $amount, string $reason): PaymentResult;
}
