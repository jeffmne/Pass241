<?php

namespace App\Livewire\Public;

use App\Models\Ticket;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Affichage d'un billet (sections 15.1, 15.2).
 *
 * « Le billet doit rester recuperable depuis la plateforme, pas uniquement via
 * le QR code joint a un email. » C'est cette page, et le lien signe qui y mene,
 * qui tiennent cette promesse.
 *
 * Aucune authentification : l'acheteur n'a pas de compte (section 15). L'acces
 * repose sur la signature de l'URL, verifiee par le middleware de la route
 * (decision D-004).
 */
#[Layout('components.layouts.public')]
class TicketPage extends Component
{
    #[Locked]
    public Ticket $ticket;

    public function mount(string $reference): void
    {
        $this->ticket = Ticket::publicByReference($reference) ?? abort(404);
    }

    public function render()
    {
        $event = $this->ticket->event;

        return view('livewire.public.ticket-page', [
            'event' => $event,
        ])->layoutData([
            'title' => 'Billet '.$this->ticket->reference.' — '.$event->name,
            // Un billet ne doit jamais remonter dans un moteur de recherche.
            'indexable' => false,
            'heroDark' => false,
        ]);
    }
}
