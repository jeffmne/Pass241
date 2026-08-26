<?php

namespace App\Services\Payments;

use App\Enums\PayoutState;

/**
 * Reponse normalisee d'un prestataire a un ordre de reversement.
 *
 * Chaque prestataire parle son propre dialecte : ce type est la frontiere ou
 * ces dialectes s'arretent. Rien au-dela ne doit connaitre le format de
 * reponse de PVit, PAYNALA ou Digitech Africa.
 */
readonly class PayoutResult
{
    /**
     * @param  string|null  $providerReference  identifiant du virement chez le prestataire
     * @param  string|null  $recipientName  titulaire du numero, quand le prestataire le renvoie
     * @param  array<string, mixed>  $raw  charge utile d'origine, conservee pour l'audit
     */
    public function __construct(
        public PayoutState $state,
        public ?string $providerReference = null,
        public ?string $failureReason = null,
        public ?string $recipientName = null,
        public array $raw = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->state === PayoutState::Succeeded;
    }

    public function failed(): bool
    {
        return $this->state === PayoutState::Failed;
    }

    /**
     * Le prestataire a pris l'ordre en charge.
     *
     * A distinguer de `succeeded()` : un virement programme est accepte sans
     * etre arrive. Confondre les deux ferait annoncer a l'organisateur un
     * argent qu'il n'a pas encore.
     */
    public function accepted(): bool
    {
        return $this->state->isAccepted();
    }
}
