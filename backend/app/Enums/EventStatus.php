<?php

namespace App\Enums;

/**
 * Cycle de vie d'un evenement (section 12).
 *
 * Regle centrale : seul PUBLISHED autorise l'achat public. Tous les autres
 * etats ferment la billetterie, pour des raisons differentes, et cette
 * distinction doit rester lisible dans le code plutot que dispersee en
 * conditions au fil des vues.
 */
enum EventStatus: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
    case SoldOut = 'SOLD_OUT';
    case Ongoing = 'ONGOING';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case Archived = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Published => 'En vente',
            self::SoldOut => 'Complet',
            self::Ongoing => 'En cours',
            self::Completed => 'Termine',
            self::Cancelled => 'Annule',
            self::Archived => 'Archive',
        };
    }

    /**
     * Section 12 : seuls les evenements PUBLISHED peuvent etre achetes
     * publiquement.
     */
    public function allowsPurchase(): bool
    {
        return $this === self::Published;
    }

    /**
     * La page publique reste consultable au-dela de la seule periode de vente :
     * un participant qui a deja son billet doit pouvoir rouvrir le lien recu,
     * et quelqu'un qui arrive apres l'annonce d'une annulation doit lire cette
     * annulation plutot qu'une page introuvable.
     */
    public function hasPublicPage(): bool
    {
        return $this !== self::Draft && $this !== self::Archived;
    }

    /**
     * Un evenement encore modifiable en profondeur. Une fois en vente, le
     * changement de date ou de lieu engage des participants qui ont paye :
     * cela releve d'une communication, pas d'une simple edition (section 12).
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Transitions autorisees.
     *
     * Table explicite plutot que suite de `if` : un etat manquant se voit, et
     * une transition ajoutee par erreur devient visible en revue.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Archived],
            self::Published => [self::Draft, self::SoldOut, self::Ongoing, self::Cancelled],
            self::SoldOut => [self::Published, self::Ongoing, self::Cancelled],
            self::Ongoing => [self::Completed, self::Cancelled],
            self::Completed => [self::Archived],
            self::Cancelled => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Couleur semantique du badge de statut (section 70.1). */
    public function tone(): string
    {
        return match ($this) {
            self::Published, self::Ongoing => 'success',
            self::Draft, self::SoldOut => 'neutral',
            self::Completed, self::Archived => 'neutral',
            self::Cancelled => 'danger',
        };
    }
}
