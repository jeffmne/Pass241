<?php

namespace App\Support;

/**
 * Mise en forme des montants.
 *
 * Tous les montants du produit sont des entiers de francs CFA (decision
 * D-003). Cette classe ne fait que les afficher : elle ne calcule rien, et
 * surtout ne convertit jamais vers un flottant.
 */
class Money
{
    public const CURRENCY = 'XAF';

    /**
     * « 50 000 FCFA ».
     *
     * Espace insecable etroit entre les milliers et avant l'unite : sans lui,
     * un montant peut se couper en fin de ligne et se lire « 50 » sur une
     * ligne et « 000 FCFA » sur la suivante.
     */
    public static function format(int $amount, bool $withCurrency = true): string
    {
        $formatted = number_format($amount, 0, ',', "\u{202F}");

        return $withCurrency ? $formatted."\u{202F}FCFA" : $formatted;
    }
}
