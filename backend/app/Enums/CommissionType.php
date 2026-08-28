<?php

namespace App\Enums;

use App\Services\Platform\PlatformSettings;

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
    case Voting = 'VOTING';

    public function label(): string
    {
        return match ($this) {
            self::Ticketing => 'Billetterie',
            self::Donation => 'Collecte de fonds',
            self::Voting => 'Votes',
        };
    }

    /**
     * Taux en points de base (1 % = 100).
     *
     * Jamais un flottant : 2,5 % ne se represente pas exactement en binaire, et
     * l'erreur finirait par produire un franc d'ecart sur certaines
     * transactions.
     *
     * Lu par `PlatformSettings`, qui retombe sur `config/commission.php` quand
     * rien n'a ete regle : le Super Admin peut changer le taux sans
     * deploiement, et une installation neuve fonctionne sans rien initialiser.
     *
     * Changer ce taux N'AFFECTE JAMAIS LE PASSE : chaque commission porte un
     * instantane du sien (decision D-003), et les reversements se calculent a
     * partir de ces instantanes.
     */
    public function basisPoints(): int
    {
        return app(PlatformSettings::class)->commissionBasisPoints($this);
    }

    /** « 5 % », « 2,5 % » — pour l'affichage uniquement. */
    public function formattedRate(): string
    {
        $percent = $this->basisPoints() / 100;

        return rtrim(rtrim(number_format($percent, 2, ',', ' '), '0'), ',').' %';
    }
}
