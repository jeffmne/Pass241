<?php

namespace App\Mail;

use App\Models\Donation;
use App\Services\Fundraising\DonationReceiptPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmation d'un don, avec son recu (sections 53, 69).
 *
 * Le donateur n'a pas de compte et rien ne le ramene spontanement sur la
 * plateforme : sans cet email, il n'a aucune trace de son geste une fois
 * l'onglet ferme. C'est aussi la seule facon pour lui de conserver le lien de
 * suivi, qui est signe et qu'il ne peut pas reconstituer.
 *
 * LE RECU EST JOINT, pas seulement lie. Une piece comptable se classe, se
 * transmet a un tresorier ou se joint a une note de frais ; un lien ne fait
 * aucune de ces choses, et se perime le jour ou la boite de messagerie est
 * archivee.
 *
 * REPONSE A L'ORGANISATEUR : un donateur qui repond a ce message veut parler a
 * la cause, pas a la plateforme.
 *
 * Aucun message de don n'est repris nulle part : il n'en existe pas
 * (section 42).
 */
class DonationReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Donation $donation) {}

    public function envelope(): Envelope
    {
        // `publicCampaign()` : cet email se rend depuis un worker, sans contexte
        // d'organisation, ou la relation ordinaire reviendrait nulle.
        $organization = $this->donation->publicCampaign()?->organization;

        $replyTo = $organization?->contact_email
            ? [new Address($organization->contact_email, $organization->name)]
            : [];

        return new Envelope(
            subject: 'Votre don pour '.($this->donation->campaign?->name ?? 'la collecte'),
            replyTo: $replyTo,
        );
    }

    public function content(): Content
    {
        $campaign = $this->donation->publicCampaign();

        return new Content(
            markdown: 'mail.donation-received',
            with: [
                'donation' => $this->donation,
                'campaign' => $campaign,
                'organization' => $campaign?->organization,
                'tz' => config('app.display_timezone'),
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $receipts = app(DonationReceiptPdf::class);

        return [
            Attachment::fromData(
                fn () => $receipts->render($this->donation),
                $receipts->filename($this->donation),
            )->withMime('application/pdf'),
        ];
    }
}
