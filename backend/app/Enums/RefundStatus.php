<?php

namespace App\Enums;

/**
 * Etat d'une demande de remboursement (section 24.1).
 *
 * Distinct du statut du billet, volontairement : la section 24.1 impose de
 * pouvoir auditer le PROCESSUS DE DECISION, pas seulement son resultat. Un
 * billet refuse et un billet jamais demande se ressemblent si l'on ne regarde
 * que le billet ; ils n'ont rien a voir du point de vue du client.
 */
enum RefundStatus: string
{
    /** Soumise par le client, en attente de la decision de l'organisateur. */
    case Requested = 'REQUESTED';

    case Approved = 'APPROVED';

    case Rejected = 'REJECTED';

    /**
     * Hors delai.
     *
     * Refusee automatiquement cote serveur, sans que l'organisateur ait a se
     * prononcer : la section 24.1 ferme les demandes 48h avant l'evenement.
     */
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'En attente de decision',
            self::Approved => 'Acceptee',
            self::Rejected => 'Refusee',
            self::Expired => 'Hors delai',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Requested;
    }

    public function isFinal(): bool
    {
        return $this !== self::Requested;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
