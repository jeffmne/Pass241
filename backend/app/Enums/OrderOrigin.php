<?php

namespace App\Enums;

/**
 * D'ou vient une commande (sections 15, 25).
 *
 * Deux chemins produisent des billets, et ils ne racontent pas la meme chose :
 * un ACHAT, ou une INVITATION offerte par l'organisateur. Les deux consomment
 * du stock — un invite occupe une place comme un acheteur — mais un seul fait
 * entrer de l'argent.
 *
 * La distinction vit ici plutot que dans un montant a zero : un billet gratuit
 * peut aussi resulter d'un code promo a 100 %, et confondre les deux fausserait
 * les statistiques de vente le jour ou les codes promo arriveront (section 26).
 */
enum OrderOrigin: string
{
    case Purchase = 'PURCHASE';
    case Invitation = 'INVITATION';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Achat',
            self::Invitation => 'Invitation',
        };
    }

    /** Cette commande represente-t-elle une vente ? */
    public function isSale(): bool
    {
        return $this === self::Purchase;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
