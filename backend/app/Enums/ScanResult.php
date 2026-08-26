<?php

namespace App\Enums;

/**
 * Issue d'un controle a l'entree (section 28).
 *
 * Les libelles sont ceux que l'agent lit sur son telephone, a bout de bras,
 * dans le bruit et sous un mauvais eclairage. Ils sont donc courts, sans
 * jargon, et la decision — entree autorisee ou refusee — se lit avant le
 * detail.
 *
 * Chaque cas de refus a son propre code, et ce n'est pas de la coquetterie :
 * « deja utilise » et « QR non reconnu » appellent deux reactions differentes
 * de l'agent. Le premier envoie chercher le vrai porteur, le second signale une
 * contrefacon.
 */
enum ScanResult: string
{
    case Accepted = 'ACCEPTED';

    /** Billet valide, mais deja passe. */
    case AlreadyUsed = 'ALREADY_USED';

    /** QR non reconnu : signature invalide, ou billet inexistant. */
    case Invalid = 'INVALID';

    case Refunded = 'REFUNDED';

    case Cancelled = 'CANCELLED';

    /** Billet authentique, mais emis pour un autre evenement. */
    case WrongEvent = 'WRONG_EVENT';

    /**
     * Scan perdant d'un double scan hors connexion (section 31.1).
     *
     * L'agent avait vu « entree autorisee » sur son telephone ; a la
     * synchronisation, un scan au timestamp local plus ancien l'emporte. Ce
     * code existe pour que l'historique dise la verite plutot que de faire
     * disparaitre l'un des deux passages.
     */
    case ConflictLost = 'CONFLICT_LOST';

    public function allowsEntry(): bool
    {
        return $this === self::Accepted;
    }

    /** Message principal, en majuscules sur l'ecran de l'agent. */
    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Billet valide',
            self::AlreadyUsed => 'Billet deja utilise',
            self::Invalid => 'Billet invalide',
            self::Refunded => 'Billet annule',
            self::Cancelled => 'Billet annule',
            self::WrongEvent => 'Autre evenement',
            self::ConflictLost => 'Billet deja utilise',
        };
    }

    /** Ce que l'agent doit faire, en une ligne. */
    public function instruction(): string
    {
        return match ($this) {
            self::Accepted => 'Entree autorisee',
            self::AlreadyUsed, self::ConflictLost => 'Entree refusee — ce billet est deja passe',
            self::Invalid => 'Entree refusee — QR Code non reconnu',
            self::Refunded, self::Cancelled => 'Entree refusee — billet annule',
            self::WrongEvent => 'Entree refusee — billet emis pour un autre evenement',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
