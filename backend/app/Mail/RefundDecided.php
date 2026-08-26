<?php

namespace App\Mail;

use App\Models\RefundRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Reponse a une demande de remboursement (sections 24.1, 69).
 *
 * Le seul canal par lequel le client apprend la decision : il n'a pas de
 * compte, et rien ne le ramene spontanement sur la page de suivi. Sans cet
 * email, un remboursement accepte resterait invisible jusqu'a ce qu'il regarde
 * son releve, et un refus ne lui serait jamais explique.
 *
 * Le motif du refus est repris tel quel. La section 24.1 exige qu'un refus
 * soit motive ; le transmettre ailleurs que dans cet email reviendrait a ne
 * pas le motiver du tout.
 */
class RefundDecided extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly RefundRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->request->isApproved()
                ? 'Votre remboursement a ete accepte'
                : 'Reponse a votre demande de remboursement',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.refund-decided',
            with: [
                'request' => $this->request,
                'order' => $this->request->order,
                'event' => $this->request->order?->event,
            ],
        );
    }
}
