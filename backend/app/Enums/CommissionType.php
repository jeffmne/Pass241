<?php

namespace App\Enums;

/**
 * Nature de la transaction commissionnee (section 21.2).
 *
 * Deux taux distincts, pour deux modules distincts. Les separer ici evite que
 * le module Collectes, developpe plus tard, reutilise par megarde le taux de
 * la billetterie.
 */
enum CommissionType: string
{
    case Ticketing = 'TICKETING';
    case Donation = 'DONATION';

    public function label(): string
    {
        return match ($this) {
            self::Ticketing => 'Billetterie',
            self::Donation => 'Collecte de fonds',
        };
    }

    /**
     * Taux en points de base (1 % = 100).
     *
     * Jamais un flottant : 2,5 % ne se represente pas exactement en binaire, et
     * l'erreur finirait par produire un franc d'ecart sur certaines
     * transactions.
     */
    public function basisPoints(): int
    {
        return (int) config('commission.basis_points.'.$this->value);
    }

    /** « 5 % », « 2,5 % » — pour l'affichage uniquement. */
    public function formattedRate(): string
    {
        $percent = $this->basisPoints() / 100;

        return rtrim(rtrim(number_format($percent, 2, ',', ' '), '0'), ',').' %';
    }
}
