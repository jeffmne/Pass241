<?php

namespace App\Enums;

/**
 * Ce qui a declenche un remboursement (decision D-028).
 *
 * Determine le sort des frais du prestataire, et rien d'autre — mais cela
 * suffit a en faire une donnee financiere : la meme somme rendue n'a pas la
 * meme composition selon l'origine.
 */
enum RefundOrigin: string
{
    /** Le client a change d'avis. Les frais restent chez l'operateur. */
    case Customer = 'CUSTOMER';

    /**
     * L'organisateur a annule son evenement.
     *
     * Le client n'y est pour rien : il recoit tout, frais compris, et c'est
     * l'organisateur qui les supporte (decision D-023).
     */
    case EventCancelled = 'EVENT_CANCELLED';

    public function refundsPspFee(): bool
    {
        return $this === self::EventCancelled;
    }

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Demande du client',
            self::EventCancelled => 'Evenement annule',
        };
    }
}
