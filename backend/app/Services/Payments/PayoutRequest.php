<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use Carbon\CarbonInterface;

/**
 * Ordre de reversement adresse a un prestataire (section 21.2).
 *
 * Ne contient AUCUN identifiant interne exploitable et aucun montant calcule
 * sur place : le montant vient de la ligne de commission deja figee, jamais
 * d'une requete ni d'un recalcul. C'est la regle qui protege le sortant, ou
 * une erreur est irreversible — un virement mobile money ne se rappelle pas.
 */
readonly class PayoutRequest
{
    public function __construct(
        /** Numero du beneficiaire, tel que saisi par l'organisateur. */
        public string $recipientPhone,

        /** Operateur du numero : le virement passe par son reseau. */
        public PaymentProvider $recipientOperator,

        /** Montant net en entiers XAF (decision D-003). */
        public int $amount,

        /**
         * Reference lisible du reversement, communiquee a l'organisateur et
         * citee au support.
         */
        public string $reference,

        /**
         * Cle d'idempotence, transmise au prestataire.
         *
         * Plus critique encore qu'a l'encaissement : un webhook rejoue cree un
         * doublon rattrapable, un virement rejoue envoie l'argent deux fois et
         * ne se recupere pas. Le prestataire doit pouvoir reconnaitre l'ordre
         * et le refuser.
         */
        public string $idempotencyKey,

        /**
         * Date de liberation des fonds, quand le prestataire sait programmer.
         *
         * Nulle signifie « des que possible ». Renseignee, elle laisse les
         * fonds dans les livres du prestataire jusqu'a l'echeance — c'est ce
         * qui evite a Pass241 d'en etre depositaire (section 21.2).
         */
        public ?CarbonInterface $releaseAt = null,

        /** Libelle porte sur le relevé du beneficiaire. */
        public string $label = 'Pass241',
    ) {}
}
