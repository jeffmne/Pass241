<?php

namespace App\Services\Payments;

use App\Enums\PaymentState;

/**
 * Reponse normalisee d'un operateur.
 *
 * Chaque provider parle son propre dialecte : ce type est la frontiere ou ces
 * dialectes s'arretent. Rien au-dela de cette classe ne doit connaitre le
 * format de reponse d'Airtel ou de Moov.
 */
readonly class PaymentResult
{
    /**
     * @param  string|null  $providerReference  identifiant de la transaction chez l'operateur
     * @param  string|null  $eventId  identifiant du message, utilise pour l'idempotence (section 23)
     * @param  array<string, mixed>  $raw  charge utile d'origine, conservee pour l'audit
     */
    public function __construct(
        public PaymentState $state,
        public ?string $providerReference = null,
        public ?string $eventId = null,
        public ?string $failureReason = null,
        public ?string $orderReference = null,
        public ?int $amount = null,
        public array $raw = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->state === PaymentState::Succeeded;
    }

    public function failed(): bool
    {
        return $this->state === PaymentState::Failed;
    }
}
