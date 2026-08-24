<?php

namespace App\Enums;

/**
 * Statuts d'un billet (section 19).
 *
 * Regle absolue : un billet utilise ne peut jamais l'etre une seconde fois.
 * C'est la seule chose qui separe une billetterie d'un bout de papier.
 */
enum TicketStatus: string
{
    case Active = 'ACTIVE';
    case Used = 'USED';
    case Cancelled = 'CANCELLED';
    case Refunded = 'REFUNDED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Valide',
            self::Used => 'Deja utilise',
            self::Cancelled => 'Annule',
            self::Refunded => 'Rembourse',
            self::Expired => 'Expire',
        };
    }

    /**
     * Seul un billet ACTIVE ouvre l'entree. Tous les autres etats la ferment,
     * pour des raisons differentes que l'agent doit pouvoir lire a l'ecran
     * (section 18.1).
     */
    public function allowsEntry(): bool
    {
        return $this === self::Active;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Used => 'neutral',
            self::Cancelled, self::Refunded, self::Expired => 'danger',
        };
    }
}
