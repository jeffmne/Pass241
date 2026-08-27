<?php

namespace App\Livewire\Public;

use App\Actions\Invitations\AcceptInvitation;
use App\Models\Invitation;
use App\Models\Ticket;
use App\Support\Tenancy\OrganizationContext;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Page d'invitation (section 25).
 *
 * L'invite arrive par un lien recu dans un message. Il n'a pas de compte, il
 * n'en creera pas, et il decide en une seconde s'il vient ou non. La page tient
 * donc en une phrase et un bouton.
 *
 * ACCEPTER EST UN GESTE EXPLICITE. Emettre le billet a la simple ouverture du
 * lien remplirait l'evenement de billets jamais reclames — un lien s'ouvre par
 * curiosite, souvent depuis un groupe ou il a ete transfere. La place n'est
 * definitivement prise que lorsque quelqu'un le dit.
 *
 * Aucune donnee de l'invite n'est demandee : son nom est deja sur l'invitation,
 * puisque c'est l'organisateur qui l'a invite (section 16).
 */
#[Layout('components.layouts.public')]
class InvitationPage extends Component
{
    public string $token = '';

    public ?Invitation $invitation = null;

    public ?Ticket $ticket = null;

    public ?string $error = null;

    public bool $accepting = false;

    public function mount(string $token): void
    {
        $this->token = $token;

        $invitation = Invitation::publicByToken($token);

        // 404 plutot qu'un message : un jeton inconnu ne doit pas confirmer
        // qu'il existe des invitations a deviner.
        abort_if($invitation === null, 404);

        $this->invitation = $invitation;
        $this->ticket = $this->issuedTicket();
    }

    public function accept(AcceptInvitation $accept): void
    {
        if ($this->invitation === null || $this->ticket !== null) {
            return;
        }

        $this->accepting = true;
        $this->error = null;

        try {
            $ticket = $accept->handle($this->invitation);
        } catch (RuntimeException $e) {
            // Le motif vient de l'action et se lit tel quel : « expire »,
            // « annulee », « complet ». L'invite doit savoir s'il peut encore
            // faire quelque chose.
            $this->error = $e->getMessage();
            $this->accepting = false;
            $this->invitation = Invitation::publicByToken($this->token);

            return;
        }

        $this->accepting = false;

        /*
         * Redirection immediate vers le billet, sur son lien signe : l'invite
         * doit repartir avec le meme objet qu'un acheteur, pas avec une page
         * de confirmation qui ne sert a rien (section 15.1).
         */
        $this->redirect($ticket->publicUrl(), navigate: false);
    }

    /** Le billet deja emis, quand l'invitation a ete acceptee. */
    private function issuedTicket(): ?Ticket
    {
        if ($this->invitation === null) {
            return null;
        }

        return app(OrganizationContext::class)->withoutScope(fn () => Ticket::query()
            ->acrossOrganizations()
            ->where('order_id', $this->invitation->order_id)
            ->first());
    }

    public function render()
    {
        return view('livewire.public.invitation-page', [
            'event' => $this->invitation?->event,
            'ticketTypeName' => $this->invitation?->order?->items->first()?->ticket_type_name,
        ]);
    }
}
