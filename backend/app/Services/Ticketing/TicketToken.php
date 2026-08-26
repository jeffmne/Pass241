<?php

namespace App\Services\Ticketing;

/**
 * Contenu verifie d'un jeton de billet (section 18).
 *
 * Ne contient que des identifiants. Aucun nom, aucune adresse email, aucune
 * donnee personnelle : un QR Code se photographie, se partage et se retrouve
 * sur un ecran verrouille.
 */
readonly class TicketToken
{
    public function __construct(
        public int $ticketId,
        public int $eventId,
        public int $orderId,
        public int $ticketTypeId,
    ) {}
}
