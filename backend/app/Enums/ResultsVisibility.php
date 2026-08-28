<?php

namespace App\Enums;

/**
 * Qui voit les resultats, et quand (section 86.4).
 *
 * Le choix appartient a l'organisateur, et il n'est pas cosmetique : afficher un
 * classement en direct pendant un vote CHANGE le vote. Un candidat en tete
 * attire, un candidat distance decourage. Certains concours veulent cet effet
 * d'entrainement, d'autres l'excluent pour que le scrutin reste sincere.
 *
 * Se regle au scrutin, et peut se raffiner categorie par categorie : un meme
 * evenement peut vouloir un classement en direct sur le prix du public, et un
 * resultat scelle jusqu'a la fin sur l'election principale.
 *
 * Ce reglage ne cache JAMAIS un resultat a l'organisateur : il porte sur la page
 * publique. L'organisateur voit toujours ses chiffres, c'est son tableau de bord
 * (section 87).
 */
enum ResultsVisibility: string
{
    case Live = 'LIVE';
    case AfterClose = 'AFTER_CLOSE';

    public function label(): string
    {
        return match ($this) {
            self::Live => 'Visibles en direct',
            self::AfterClose => 'Masques jusqu\'a la cloture',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Live => 'Le public suit le classement pendant toute la duree du vote.',
            self::AfterClose => 'Le public ne decouvre le classement qu\'a la fermeture du vote.',
        };
    }

    /**
     * Le public peut-il voir le classement a cet instant ?
     *
     * Le parametre seul ne decide pas : `AFTER_CLOSE` s'ouvre a la cloture. La
     * question se pose donc toujours avec l'etat du scrutin en main, jamais sur
     * l'enum seule — d'ou l'argument obligatoire.
     */
    public function isPublicFor(PollStatus $status): bool
    {
        return match ($this) {
            self::Live => true,
            self::AfterClose => $status->isClosed(),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
