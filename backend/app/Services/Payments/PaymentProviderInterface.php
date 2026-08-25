<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Contrat commun aux operateurs de paiement (section 21).
 *
 * Airtel Money et Moov Money viendront s'y brancher sans que rien au-dessus ne
 * change. C'est la raison d'etre de cette interface : le jour ou un troisieme
 * operateur arrive — ou ou l'on passe par un agregateur — seule une classe
 * s'ajoute.
 *
 * Aucune methode ne modifie le statut d'une commande. Un provider decrit ce que
 * dit l'operateur ; c'est ConfirmPayment qui en tire les consequences. Cette
 * separation est ce qui rend l'idempotence possible (section 23).
 */
interface PaymentProviderInterface
{
    /** Identifiant technique du provider, tel qu'il apparait dans les URL de webhook. */
    public function name(): string;

    /**
     * Declenche le paiement : push USSD vers le numero de l'acheteur
     * (section 21.1).
     *
     * Retourne l'etat immediat, en general PENDING — l'acheteur doit encore
     * valider sur son telephone.
     */
    public function initializePayment(Order $order): PaymentResult;

    /**
     * Interroge l'operateur sur l'etat d'une transaction.
     *
     * Appelee en polling de secours pendant la fenetre de paiement : les
     * webhooks mobile money peuvent etre en retard ou manquants selon
     * l'operateur (section 21.1).
     */
    public function checkPaymentStatus(Order $order): PaymentResult;

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
     * Le montant est en entiers XAF. L'operation est traitee comme une
     * transaction distincte, liee a la commande d'origine, jamais comme une
     * modification du paiement initial (section 46).
     */
    public function refund(Order $order, int $amount, string $reason): PaymentResult;
}
