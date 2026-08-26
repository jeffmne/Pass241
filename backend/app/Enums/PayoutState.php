<?php

namespace App\Enums;

/**
 * Etat d'un reversement tel que le rapporte un prestataire (section 21.2).
 *
 * Distinct de PaymentState, volontairement : un encaissement et un virement
 * sortant echouent pour des raisons differentes, et surtout ne se rattrapent
 * pas de la meme facon. Un encaissement rate ne coute rien — l'acheteur
 * recommence. Un virement rate laisse un organisateur sans son argent.
 */
enum PayoutState: string
{
    /** Instruction acceptee, fonds pas encore arrives. */
    case Pending = 'PENDING';

    /**
     * Instruction acceptee et programmee a une date future.
     *
     * Etat propre au reversement apres evenement : les fonds restent dans les
     * livres du prestataire jusqu'a l'echeance, et Pass241 n'en est jamais
     * depositaire (section 21.2).
     */
    case Scheduled = 'SCHEDULED';

    case Succeeded = 'SUCCEEDED';

    case Failed = 'FAILED';

    /**
     * Le prestataire ne sait pas repondre.
     *
     * A traiter comme « pas encore de reponse », JAMAIS comme un echec : sur
     * un virement, conclure a l'echec trop vite conduit a rejouer l'ordre et a
     * payer deux fois. Une somme envoyee en double ne se recupere pas.
     */
    case Unknown = 'UNKNOWN';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /** Le prestataire s'est engage, meme si rien n'est encore arrive. */
    public function isAccepted(): bool
    {
        return in_array($this, [self::Pending, self::Scheduled, self::Succeeded], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Reversement en cours',
            self::Scheduled => 'Reversement programme',
            self::Succeeded => 'Reversement effectue',
            self::Failed => 'Reversement echoue',
            self::Unknown => 'Etat inconnu',
        };
    }
}
