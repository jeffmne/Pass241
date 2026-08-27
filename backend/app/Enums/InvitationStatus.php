<?php

namespace App\Enums;

/**
 * Cycle de vie d'une invitation (section 25).
 *
 * La section 25 liste six etats. Cinq vivent ici ; le sixieme, `USED`, N'EST
 * PAS STOCKE — il se lit sur le billet qui en est issu.
 *
 * La raison tient en une phrase : le passage a l'entree est enregistre par le
 * controle d'acces, qui ne sait rien des invitations. Le dupliquer ici
 * donnerait deux verites pour un meme fait, et la seconde serait fausse le jour
 * ou un scan hors connexion remonterait apres coup (section 31.1). L'invitation
 * dit donc « acceptee », et le billet dit s'il est passe.
 */
enum InvitationStatus: string
{
    /** Creee, place mise de cote, pas encore transmise. */
    case Created = 'CREATED';

    /** Le lien est parti — par email, ou copie par l'organisateur. */
    case Sent = 'SENT';

    /** L'invite a confirme : le billet existe. */
    case Accepted = 'ACCEPTED';

    /** Le delai est passe sans reponse ; la place est revenue au public. */
    case Expired = 'EXPIRED';

    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Creee',
            self::Sent => 'Envoyee',
            self::Accepted => 'Acceptee',
            self::Expired => 'Expiree',
            self::Cancelled => 'Annulee',
        };
    }

    /** L'invite peut-il encore accepter ? */
    public function isOpen(): bool
    {
        return $this === self::Created || $this === self::Sent;
    }

    /** La place est-elle encore retenue pour cet invite ? */
    public function holdsSeat(): bool
    {
        return $this->isOpen() || $this === self::Accepted;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
