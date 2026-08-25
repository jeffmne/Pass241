<?php

namespace App\Enums;

/**
 * Etat d'un paiement tel que le rapporte un operateur.
 *
 * Distinct d'OrderStatus, volontairement : c'est le vocabulaire du provider,
 * pas celui de la commande. Les deux se ressemblent aujourd'hui, mais un
 * operateur peut rapporter des etats intermediaires qui n'ont pas a remonter
 * jusqu'au modele metier.
 */
enum PaymentState: string
{
    /** Push USSD envoye, l'acheteur n'a pas encore valide. */
    case Pending = 'PENDING';

    case Succeeded = 'SUCCEEDED';

    case Failed = 'FAILED';

    /**
     * Le provider ne connait pas cette transaction.
     *
     * A traiter comme « pas encore de reponse » et non comme un echec : sur
     * certains operateurs, la transaction n'est interrogeable qu'au bout de
     * quelques secondes.
     */
    case Unknown = 'UNKNOWN';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
