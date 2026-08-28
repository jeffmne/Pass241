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
     * Separateur de milliers : espace insecable ORDINAIRE (U+00A0).
     *
     * Insecable, parce qu'un montant coupe en fin de ligne se lit « 50 » puis
     * « 000 FCFA » a la ligne suivante — sur un ecran de paiement, c'est une
     * erreur de lecture d'un facteur mille.
     *
     * ORDINAIRE et non etroit (U+202F), et le choix vaut d'etre explique : la
     * typographie francaise prescrit l'espace fine, et c'est ce que ce code
     * employait. Mais dans Plus Jakarta Sans — la police du produit depuis la
     * refonte — U+202F ne mesure que 0,09 em, contre 0,18 em pour l'espace
     * ordinaire. A la graisse 800 des cartes de chiffres, « 500 000 FCFA » se
     * lit alors « 500000FCFA » : les chiffres se touchent et le montant
     * devient illisible.
     *
     * Entre une regle typographique et un montant qu'on ne peut pas lire, le
     * montant l'emporte.
     */
    private const THOUSANDS_SEPARATOR = "\u{00A0}";

    /** « 50 000 FCFA ». */
    public static function format(int $amount, bool $withCurrency = true): string
    {
        return $withCurrency
            ? self::number($amount).self::THOUSANDS_SEPARATOR.'FCFA'
            : self::number($amount);
    }

    /**
     * Un nombre qui n'est PAS un montant : un decompte de billets, de
     * participants, de dons.
     *
     * Il existe pour une raison simple : quatorze vues appelaient
     * `number_format(..., "\u{202F}")` a la main, chacune avec sa propre copie
     * du separateur. Le jour ou ce separateur a du changer, il fallait les
     * retrouver toutes — et il en restait toujours une.
     *
     * Les decimales sont possibles pour les pourcentages, qui suivent la meme
     * regle de separateur mais portent une virgule.
     */
    public static function number(int|float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, ',', self::THOUSANDS_SEPARATOR);
    }

    /**
     * « 50 000 F ».
     *
     * L'unite abregee, pour les endroits ou la place manque : cartouches de
     * collecte, paliers de dons, barres de progression. Six vues portaient
     * chacune leur propre fermeture `fn ($a) => number_format(...).' F'` —
     * autant d'occasions de diverger.
     *
     * A reserver aux contextes ou la devise est deja etablie par ce qui
     * l'entoure. Un recu, un billet ou un reversement gardent « FCFA » en
     * entier : ce sont des pieces qui circulent hors du produit, ou l'abrege
     * ne serait pas compris.
     */
    public static function formatShort(int $amount): string
    {
        return self::number($amount).self::THOUSANDS_SEPARATOR.'F';
    }
}
