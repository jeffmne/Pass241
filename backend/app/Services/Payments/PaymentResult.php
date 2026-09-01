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
        /**
         * Montant HORS FRAIS que le prestataire a enregistre, quand il le
         * distingue du total.
         *
         * Existe parce que certains prestataires ne permettent pas de
         * reconstituer le total debite : PVit facture en deux composantes et
         * n'en expose qu'une sur son API de statut. `$amount` y vaut alors
         * `null`, et la verification de montant sautait entierement — une
         * protection perdue en silence sur le chemin de l'argent.
         *
         * Ce champ rend verifiable ce qui l'est : le sous-total enregistre chez
         * le prestataire doit correspondre a celui que nous lui avons envoye.
         * C'est moins que la verification du total, mais ce n'est pas rien —
         * cela attrape un montant enregistre de travers.
         */
        public ?int $subtotal = null,
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
