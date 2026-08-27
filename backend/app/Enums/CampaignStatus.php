<?php

namespace App\Enums;

/**
 * Cycle de vie d'une campagne de collecte (section 35).
 *
 * Regle centrale : seul PUBLISHED accepte un don. Tous les autres etats ferment
 * la collecte, pour des raisons differentes, et la distinction doit rester
 * lisible ici plutot que dispersee en conditions au fil des vues.
 *
 * AUCUN RETOUR AU BROUILLON, contrairement aux evenements. Une campagne publiee
 * a pu recevoir des dons, et son historique est public (section 43) : la faire
 * disparaitre derriere un brouillon reviendrait a masquer des dons confirmes,
 * ce que la regle 80.1 interdit. La suspension existe pour cela — elle ferme la
 * collecte en laissant la page et son historique visibles.
 */
enum CampaignStatus: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
    case Paused = 'PAUSED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Published => 'En cours',
            self::Paused => 'Suspendue',
            self::Completed => 'Terminee',
            self::Cancelled => 'Annulee',
            self::Expired => 'Echue',
        };
    }

    /** Section 39 : seule une campagne en cours peut recevoir un don. */
    public function acceptsDonations(): bool
    {
        return $this === self::Published;
    }

    /**
     * La page publique reste consultable au-dela de la periode de collecte.
     *
     * Un donateur qui a contribue doit pouvoir revenir voir ou en est la
     * campagne, et quelqu'un qui arrive apres la fin doit lire le resultat
     * plutot qu'une page introuvable. Seul le brouillon n'a pas de page : il
     * n'a jamais ete partage.
     */
    public function hasPublicPage(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Une campagne encore modifiable en profondeur.
     *
     * Une fois publiee, l'objectif et le beneficiaire engagent ceux qui ont
     * donne : les changer discretement reviendrait a deplacer la cible apres
     * coup. Seuls la description et les images restent modifiables ensuite
     * (voir SaveCampaign).
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** L'etat est-il definitif ? */
    public function isClosed(): bool
    {
        return $this === self::Completed
            || $this === self::Cancelled
            || $this === self::Expired;
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
            self::Draft => [self::Published, self::Cancelled],
            self::Published => [self::Paused, self::Completed, self::Cancelled, self::Expired],
            self::Paused => [self::Published, self::Completed, self::Cancelled, self::Expired],
            // Une campagne echue peut encore etre cloturee formellement par
            // l'organisateur, jamais rouverte : la date de fin est passee.
            self::Expired => [self::Completed, self::Cancelled],
            self::Completed => [],
            self::Cancelled => [],
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
            self::Published => 'success',
            self::Draft, self::Paused, self::Completed, self::Expired => 'neutral',
            self::Cancelled => 'danger',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
