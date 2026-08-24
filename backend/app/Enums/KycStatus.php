<?php

namespace App\Enums;

/**
 * Statut de verification d'identite de l'organisation (section 10.1).
 *
 * Le KYC ne conditionne jamais l'inscription, la creation d'un evenement ou
 * d'une campagne, ni la vente : il conditionne uniquement le reversement des
 * fonds vers l'organisateur.
 */
enum KycStatus: string
{
    case Pending = 'PENDING';
    case Submitted = 'SUBMITTED';
    case Verified = 'VERIFIED';
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Verification non commencee',
            self::Submitted => 'Verification en cours',
            self::Verified => 'Identite verifiee',
            self::Rejected => 'Verification refusee',
        };
    }

    /**
     * Seul l'etat verifie debloque le reversement (section 10.1, 21.2).
     * Les fonds continuent d'etre encaisses et comptabilises dans tous les
     * autres etats — c'est le versement physique qui est suspendu.
     */
    public function allowsPayout(): bool
    {
        return $this === self::Verified;
    }
}
