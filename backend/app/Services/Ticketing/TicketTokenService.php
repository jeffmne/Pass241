<?php

namespace App\Services\Ticketing;

use App\Models\Ticket;
use RuntimeException;

/**
 * Signature et verification des jetons de billet (section 18).
 *
 * Le QR Code n'encode JAMAIS un identifiant brut. Il porte une charge utile
 * signee : sans la cle, personne ne peut fabriquer un jeton valide, et
 * incrementer un numero ne mene nulle part.
 *
 * La cle est distincte d'APP_KEY (TICKET_TOKEN_SIGNING_KEY) : une rotation de
 * la cle applicative ne doit jamais invalider des billets deja vendus et
 * imprimes.
 *
 * Ce que le jeton contient : quatre identifiants, rien d'autre. Ni nom, ni
 * email, ni prix. Un QR Code se photographie et se partage — il ne doit rien
 * reveler de son porteur.
 *
 * Ce que le jeton NE dit PAS : si le billet est encore valide. Le statut change
 * apres l'emission — un billet peut etre rembourse ou deja scanne — et un jeton
 * ne peut pas porter une information qui evolue. La verification finale se fait
 * toujours en base (section 18.1).
 */
class TicketTokenService
{
    /**
     * Version du format.
     *
     * Prefixee au jeton pour permettre un changement d'algorithme sans
     * invalider les billets deja en circulation : les anciens continueront
     * d'etre verifies par leur propre branche.
     */
    private const VERSION = '1';

    /**
     * Longueur de la signature conservee, en caracteres hexadecimaux.
     *
     * 32 caracteres, soit 128 bits. Suffisant contre la falsification, et
     * nettement plus court que les 64 d'un HMAC-SHA256 complet — sur un QR
     * Code, chaque caractere ajoute des modules, donc de la difficulte a
     * scanner dans une lumiere mediocre a l'entree d'un concert.
     */
    private const SIGNATURE_LENGTH = 32;

    /**
     * Fabrique le jeton a encoder dans le QR Code.
     *
     * Format : 1.<charge utile>.<signature>
     */
    public function issue(Ticket $ticket): string
    {
        $payload = $this->payloadFor($ticket);

        return self::VERSION.'.'.$payload.'.'.$this->sign($payload);
    }

    /**
     * Verifie un jeton scanne et en extrait les identifiants.
     *
     * Renvoie null pour tout jeton douteux, sans distinguer les causes : un
     * message d'erreur detaille aiderait a fabriquer un faux.
     */
    public function verify(string $token): ?TicketToken
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$version, $payload, $signature] = $parts;

        if ($version !== self::VERSION) {
            return null;
        }

        // Comparaison a temps constant : une comparaison naive laisse fuir la
        // signature attendue, un caractere a la fois.
        if (! hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        $fields = explode('-', $payload);

        if (count($fields) !== 4) {
            return null;
        }

        foreach ($fields as $field) {
            if (! ctype_digit($field)) {
                return null;
            }
        }

        [$ticketId, $eventId, $orderId, $ticketTypeId] = array_map('intval', $fields);

        return new TicketToken($ticketId, $eventId, $orderId, $ticketTypeId);
    }

    /**
     * Charge utile : quatre identifiants separes par des tirets.
     *
     * Volontairement compacte et purement numerique. Un QR Code alphanumerique
     * encode plus densement qu'un QR binaire, et un jeton court reste lisible
     * meme sur un ecran raye, tenu a la main, sous un mauvais eclairage.
     */
    private function payloadFor(Ticket $ticket): string
    {
        return implode('-', [
            $ticket->getKey(),
            $ticket->event_id,
            $ticket->order_id,
            $ticket->ticket_type_id,
        ]);
    }

    private function sign(string $payload): string
    {
        return substr(
            hash_hmac('sha256', self::VERSION.'.'.$payload, $this->key()),
            0,
            self::SIGNATURE_LENGTH,
        );
    }

    private function key(): string
    {
        $key = (string) config('app.ticket_token_key');

        if ($key === '') {
            throw new RuntimeException(
                'TICKET_TOKEN_SIGNING_KEY est absente. Sans elle, aucun billet ne peut etre '
                .'signe ni verifie. Generer une cle : php artisan pass241:ticket-key'
            );
        }

        return $key;
    }
}
