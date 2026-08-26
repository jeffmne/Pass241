<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Ticket;
use App\Services\Billing\ReceiptPdf;
use App\Services\Ticketing\TicketPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Copie du billet par email (section 69).
 *
 * « V1 : affichage immediat dans l'application + email en copie facultative. »
 *
 * Cet email est une COMMODITE, jamais le canal de livraison. Le billet est
 * deja affiche a l'acheteur et reste accessible depuis « Mes billets » : si
 * l'email n'arrive pas — mauvaise adresse, boite pleine, courrier indesirable —
 * personne ne perd son billet.
 *
 * Le corps du message renvoie vers le billet en ligne par un lien signe, qui
 * affiche toujours l'etat REEL du billet — utilise, rembourse, valide. C'est
 * la version qui fait foi.
 *
 * Le PDF joint est la version imprimable, et le seul endroit ou elle circule :
 * la plateforme ne la propose plus au telechargement, le bouton de la page
 * billet produisant une image. Une piece jointe s'archive, se transfere a un
 * proche et s'imprime, ce qu'un lien ne fait pas.
 *
 * Un QR en piece jointe circule ensuite hors de tout controle : c'est assume,
 * et c'est sans consequence. Le billet est nominatif (section 16) et un seul
 * scan le consomme (section 19) — une copie transmise ne cree pas une seconde
 * entree, elle vole la premiere.
 */
class TicketsIssued extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Vos billets pour '.$this->order->event->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.tickets-issued',
            with: [
                'order' => $this->order,
                'event' => $this->order->event,
                'tickets' => $this->order->tickets,
                'tz' => config('app.display_timezone'),
            ],
        );
    }

    /**
     * Un PDF par billet.
     *
     * Un seul document regroupant toute la commande obligerait le porteur a
     * trouver sa page parmi cinq avant de la tendre a l'agent, et empecherait
     * d'envoyer son billet a chacun. Un fichier par billet se transfere tel
     * quel.
     *
     * Le rendu se fait ici, donc dans le worker qui envoie l'email
     * (SendTicketsEmail) : jamais dans la requete qui confirme le paiement.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $pdf = app(TicketPdf::class);
        $receipts = app(ReceiptPdf::class);

        $attachments = $this->order->tickets
            ->map(fn (Ticket $ticket) => Attachment::fromData(
                fn () => $pdf->render($ticket),
                $pdf->filename($ticket),
            )->withMime('application/pdf'))
            ->all();

        /*
         * Le recu de paiement, joint une seule fois (decision D-029).
         *
         * Il ne porte pas sur un billet mais sur la commande entiere : le
         * dupliquer par billet n'aurait aucun sens comptable. C'est la piece
         * qu'on classe ou qu'on joint a une note de frais, et la recevoir
         * sans avoir a revenir sur le site est exactement ce qu'on en attend.
         */
        $attachments[] = Attachment::fromData(
            fn () => $receipts->render($this->order),
            $receipts->filename($this->order),
        )->withMime('application/pdf');

        return $attachments;
    }
}
