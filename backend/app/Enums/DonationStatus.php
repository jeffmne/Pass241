<?php

namespace App\Enums;

/**
 * Cycle de vie d'un don (section 39).
 *
 * Regle centrale : SEULS LES DONS CONFIRMES SONT COMPTABILISES. Un don en
 * attente n'est pas de l'argent recu, c'est une intention — l'afficher dans le
 * total ferait mentir la page publique a chaque paiement abandonne.
 *
 * Il n'y a pas d'etat « rembourse » : un remboursement, meme total, ne defait
 * pas le don (section 47). Il s'enregistre a part, lie au don d'origine, et se
 * deduit du montant collecte. Changer le statut du don reviendrait a reecrire
 * le passe au lieu de lui ajouter une ligne (section 46).
 */
enum DonationStatus: string
{
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Confirmed => 'Confirme',
            self::Failed => 'Echoue',
            self::Cancelled => 'Annule',
        };
    }

    /** Section 39 : le seul etat qui compte dans le montant collecte. */
    public function isCounted(): bool
    {
        return $this === self::Confirmed;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Confirmed => 'success',
            self::Pending => 'neutral',
            self::Failed, self::Cancelled => 'danger',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
