<?php

namespace App\Services\Payments;

/**
 * Frais de prestataire appliques a une transaction.
 *
 * Porte le montant ET le bareme qui l'a produit. Les deux voyagent ensemble
 * jusqu'a la commande, ou ils sont figes : une transaction doit pouvoir etre
 * reexpliquee dans un an, alors que le bareme aura change.
 */
readonly class PspFee
{
    public function __construct(
        public int $amount,
        public int $basisPoints,
        public int $fixed,
    ) {}

    public static function none(): self
    {
        return new self(0, 0, 0);
    }
}
