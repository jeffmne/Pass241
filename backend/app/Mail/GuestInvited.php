<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Lien d'invitation envoye a l'invite (sections 25, 69).
 *
 * A la difference de l'email de billet, celui-ci N'EST PAS UNE COMMODITE : le
 * lien est le seul chemin vers la place reservee. L'invite n'a pas de compte,
 * aucune commande a son nom, et rien sur la plateforme ne le ramenerait vers
 * cette invitation s'il perdait le message. C'est pourquoi l'organisateur garde
 * le lien sous les yeux dans son ecran : cet email lui epargne le copier-coller,
 * il ne le remplace pas.
 *
 * REPONSE A L'ORGANISATEUR, pas a la plateforme. Un invite repond toujours a ce
 * genre de message — pour confirmer, pour demander s'il peut venir accompagne,
 * pour dire qu'il ne pourra pas. Cette reponse doit arriver a celui qui invite,
 * qui est le seul a pouvoir y repondre.
 */
class GuestInvited extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Invitation $invitation) {}

    public function envelope(): Envelope
    {
        $organization = $this->invitation->event?->organization;

        /*
         * L'adresse de contact de l'organisation, quand elle existe. Sans elle,
         * la reponse revient a l'expediteur par defaut — le message part quand
         * meme, une adresse de contact manquante n'est pas une raison de ne pas
         * inviter.
         */
        $replyTo = $organization?->contact_email
            ? [new Address($organization->contact_email, $organization->name)]
            : [];

        return new Envelope(
            subject: 'Vous etes invite a '.$this->invitation->event?->name,
            replyTo: $replyTo,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.guest-invited',
            with: [
                'invitation' => $this->invitation,
                'event' => $this->invitation->event,
                'organization' => $this->invitation->event?->organization,
                'tz' => config('app.display_timezone'),
            ],
        );
    }
}
