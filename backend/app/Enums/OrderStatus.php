<?php

namespace App\Enums;

/**
 * Statuts d'une commande (section 20).
 *
 * PENDING est un etat transitoire et court : le push USSD est parti, on attend
 * la validation de l'acheteur. Passe le delai de la section 21.1, la commande
 * bascule seule en FAILED (job planifie) — une commande ne reste jamais en
 * attente indefiniment.
 */
enum OrderStatus: string
{
    case Pending = 'PENDING';
    case Paid = 'PAID';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
    case Refunded = 'REFUNDED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente de paiement',
            self::Paid => 'Payee',
            self::Failed => 'Echouee',
            self::Cancelled => 'Annulee',
            self::Refunded => 'Remboursee',
            self::PartiallyRefunded => 'Partiellement remboursee',
        };
    }

    /**
     * Une commande dont l'argent est effectivement encaisse.
     *
     * C'est cet etat, et lui seul, qui consomme definitivement du stock et qui
     * autorise l'emission des billets (section 15.1). Un remboursement partiel
     * en fait toujours partie : le reste de la commande est bien paye.
     */
    public function isPaid(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }

    /** Etat definitif : plus aucune transition n'est attendue. */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Paid, self::PartiallyRefunded => 'success',
            self::Pending => 'warning',
            self::Failed, self::Cancelled, self::Refunded => 'danger',
        };
    }
}
