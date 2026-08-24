<?php

namespace App\Enums;

/**
 * Visibilite de la page publique d'un evenement (section 11).
 *
 * Attention au contresens : la plateforme n'a pas de catalogue public
 * (section 5). Dans les deux cas, l'evenement n'est accessible que par son
 * lien, partage par l'organisateur lui-meme. Ce reglage ne decide donc pas de
 * « qui peut voir », mais de « ce que les moteurs de recherche font du lien ».
 *
 * - LISTED   : page indexable, apercu de partage complet. Pour un concert,
 *              un gala, une conference — tout ce que l'organisateur veut voir
 *              remonter dans une recherche.
 * - UNLISTED : page accessible par le lien mais `noindex`, sans apercu riche.
 *              Pour un anniversaire, un mariage, un evenement prive
 *              (section 3) : le lien circule entre invites, il n'a rien a
 *              faire dans un moteur de recherche.
 */
enum EventVisibility: string
{
    case Listed = 'LISTED';
    case Unlisted = 'UNLISTED';

    public function label(): string
    {
        return match ($this) {
            self::Listed => 'Referencable',
            self::Unlisted => 'Non referencable',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Listed => 'La page peut apparaitre dans les moteurs de recherche.',
            self::Unlisted => 'Accessible uniquement par le lien. Ideal pour un evenement prive.',
        };
    }

    public function isIndexable(): bool
    {
        return $this === self::Listed;
    }
}
