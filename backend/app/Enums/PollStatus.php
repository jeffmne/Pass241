<?php

namespace App\Enums;

/**
 * Cycle de vie d'un scrutin (section 86.4).
 *
 * Regle centrale : seul ACTIVE accepte un bulletin. Les autres etats ferment le
 * vote, pour des raisons differentes, et la distinction reste lisible ici plutot
 * que dispersee en conditions au fil des vues.
 *
 * SCHEDULED existe, contrairement aux collectes : un concours s'annonce avant
 * d'ouvrir, et sa page doit etre partageable pendant la campagne d'annonce, avec
 * ses candidats visibles et le vote encore ferme. Une collecte n'a pas ce
 * besoin — on peut donner des la publication.
 *
 * AUCUN RETOUR AU BROUILLON une fois le scrutin ouvert. Un vote actif a pu
 * recevoir des bulletins, dont des bulletins payes : le repasser en brouillon
 * ferait disparaitre de la vue publique des votes deja exprimes, ce que la
 * section 86.4 interdit. L'annulation existe pour cela — elle arrete le scrutin
 * sans rien effacer.
 */
enum PollStatus: string
{
    case Draft = 'DRAFT';
    case Scheduled = 'SCHEDULED';
    case Active = 'ACTIVE';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Scheduled => 'Programme',
            self::Active => 'En cours',
            self::Closed => 'Termine',
            self::Cancelled => 'Annule',
        };
    }

    /**
     * Le scrutin accepte-t-il un bulletin ?
     *
     * L'etat ne suffit pas : la fenetre de dates est verifiee en plus, a chaque
     * bulletin, par `Poll::acceptsBallots()`. Un scrutin dont l'echeance vient
     * de passer doit refuser immediatement, sans attendre la tache planifiee
     * (section 86.5, point 8 de la strategie anti-fraude).
     */
    public function acceptsBallots(): bool
    {
        return $this === self::Active;
    }

    /**
     * La page publique reste consultable au-dela de la periode de vote.
     *
     * Quelqu'un qui a vote doit pouvoir revenir voir le resultat, et quelqu'un
     * qui arrive apres la cloture doit lire le classement plutot qu'une page
     * introuvable. Seul le brouillon n'a pas de page : il n'a jamais ete
     * partage.
     */
    public function hasPublicPage(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Un scrutin encore modifiable en profondeur.
     *
     * Une fois ouvert, la fenetre de vote et la visibilite des resultats
     * engagent ceux qui ont vote : les changer discretement reviendrait a
     * deplacer les regles en cours de partie. Seuls la description et les
     * images restent modifiables ensuite (voir SavePoll).
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Scheduled;
    }

    /** L'etat est-il definitif ? */
    public function isClosed(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
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
            self::Draft => [self::Scheduled, self::Active, self::Cancelled],
            // Un scrutin programme ouvre seul a sa date, ou par la main de
            // l'organisateur s'il veut ouvrir plus tot.
            self::Scheduled => [self::Active, self::Cancelled],
            self::Active => [self::Closed, self::Cancelled],
            self::Closed => [],
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
            self::Active => 'success',
            self::Draft, self::Scheduled, self::Closed => 'neutral',
            self::Cancelled => 'danger',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
