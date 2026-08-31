<?php

namespace App\Enums;

/**
 * Cycle de vie d'un bulletin (section 86.4).
 *
 * Regle centrale : SEUL UN BULLETIN CONFIRME COMPTE. C'est la transposition
 * exacte de la regle 80.1 des collectes — seuls les dons confirmes sont
 * comptabilises — et elle vaut pour la meme raison : un paiement annonce n'est
 * pas un paiement recu.
 *
 * Un vote gratuit nait CONFIRMED : il n'y a rien a attendre. Un vote payant nait
 * PENDING et ne compte qu'a la confirmation du prestataire.
 */
enum BallotStatus: string
{
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case Failed = 'FAILED';
    case Refunded = 'REFUNDED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Confirmed => 'Confirme',
            self::Failed => 'Echoue',
            self::Refunded => 'Rembourse',
        };
    }

    /**
     * Ce bulletin compte-t-il dans les voix ?
     *
     * UN BULLETIN REMBOURSE COMPTE ENCORE, et c'est le point le moins evident
     * de cet enum. Le remboursement rend l'argent ; il ne retire pas la voix,
     * parce que la voix a bien ete exprimee et que le classement en a tenu
     * compte au moment ou il etait public. Defaire cela permettrait de retirer
     * des voix a un candidat en remboursant ses votants — exactement ce que la
     * section 86.4 interdit.
     *
     * Le revenu, lui, est bien diminue : c'est l'ajustement de commission qui
     * s'en charge, comme pour un don rembourse (section 47).
     */
    public function countsAsVote(): bool
    {
        return $this === self::Confirmed || $this === self::Refunded;
    }

    /** Ce bulletin a-t-il rapporte de l'argent qui n'a pas ete rendu ? */
    public function countsAsRevenue(): bool
    {
        return $this === self::Confirmed;
    }

    /** Le bulletin consomme-t-il le quota du votant ? */
    public function consumesQuota(): bool
    {
        // En attente compris : sans cela, quelqu'un pourrait lancer dix
        // paiements simultanes sur une categorie plafonnee a un vote, et
        // n'en confirmer qu'un — mais tous auraient passe le controle.
        return $this !== self::Failed;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Confirmed => 'success',
            self::Pending, self::Refunded => 'neutral',
            self::Failed => 'danger',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
