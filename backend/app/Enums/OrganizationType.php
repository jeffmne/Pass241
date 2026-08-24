<?php

namespace App\Enums;

/**
 * Nature juridique de l'organisation. Determine les pieces attendues au moment
 * de la verification d'identite (section 10.1) : piece d'identite pour un
 * particulier, RCCM ou equivalent pour une entreprise ou une association.
 */
enum OrganizationType: string
{
    case Individual = 'INDIVIDUAL';
    case Company = 'COMPANY';
    case Association = 'ASSOCIATION';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Particulier',
            self::Company => 'Entreprise',
            self::Association => 'Association ou BDE',
        };
    }

    /** @return list<string> */
    public function requiredKycDocuments(): array
    {
        return match ($this) {
            self::Individual => ['Piece d\'identite'],
            self::Company, self::Association => ['Piece d\'identite du representant', 'RCCM ou equivalent'],
        };
    }
}
