<?php

namespace App\Enums;

/**
 * Pieces attendues pour la verification d'identite (section 10.1).
 */
enum DocumentType: string
{
    case Identity = 'IDENTITY';
    case Rccm = 'RCCM';

    public function label(): string
    {
        return match ($this) {
            self::Identity => 'Piece d\'identite',
            self::Rccm => 'RCCM ou equivalent',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Identity => 'Carte nationale d\'identite ou passeport, en cours de validite.',
            self::Rccm => 'Registre de commerce, recepisse de declaration ou statuts de l\'association.',
        };
    }

    /**
     * Pieces exigees selon la nature de l'organisation.
     *
     * @return list<self>
     */
    public static function requiredFor(OrganizationType $type): array
    {
        return match ($type) {
            OrganizationType::Individual => [self::Identity],
            OrganizationType::Company, OrganizationType::Association => [self::Identity, self::Rccm],
        };
    }
}
