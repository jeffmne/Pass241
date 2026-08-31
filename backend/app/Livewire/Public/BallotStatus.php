<?php

namespace App\Livewire\Public;

use App\Actions\Payments\ConfirmPayment;
use App\Enums\PaymentState;
use App\Models\Ballot;
use App\Services\Payments\PaymentManager;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Suivi d'un vote paye, apres le declenchement du paiement (sections 21.1,
 * 86.4).
 *
 * Le votant voit ce qu'il doit faire — valider sur son telephone — et combien
 * de temps il lui reste. La page se rafraichit d'elle-meme tant que le bulletin
 * est en attente.
 *
 * Le rafraichissement fait aussi office de POLLING DE SECOURS : les webhooks
 * mobile money peuvent etre en retard ou manquants selon l'operateur.
 * Interroger le prestataire pendant que le votant regarde son ecran est le
 * moment ou cela coute le moins et sert le plus.
 *
 * LE FRONTEND NE DECIDE JAMAIS QU'UN PAIEMENT EST CONFIRME (sections 22, 50, et
 * section 86.4 : « le vote n'est VALIDE qu'apres confirmation reelle du
 * paiement par le PSP »). Cette page LIT un etat ; c'est `ConfirmPayment`, cote
 * serveur, qui tranche. Une requete forgee depuis ce composant ne peut donc pas
 * transformer une intention de vote en voix.
 */
#[Layout('components.layouts.public')]
class BallotStatus extends Component
{
    #[Locked]
    public Ballot $ballot;

    public function mount(string $reference): void
    {
        // Portee levee : le votant n'appartient a aucune organisation. L'acces
        // est protege par la signature de l'URL, verifiee par le middleware.
        $this->ballot = Ballot::publicByReference($reference) ?? abort(404);
    }

    /**
     * Interroge le prestataire, si le bulletin est encore en attente.
     *
     * Ne fait rien lorsque le bulletin a deja un etat definitif : inutile de
     * solliciter l'operateur pour une transaction close.
     */
    public function refreshStatus(PaymentManager $payments, ConfirmPayment $confirmPayment): void
    {
        $this->ballot->refresh();

        if (! $this->ballot->isPending()) {
            return;
        }

        $result = $payments->driver()->checkPaymentStatus($this->ballot);

        if ($result->state === PaymentState::Succeeded) {
            $this->ballot = $confirmPayment->handle($this->ballot, $result->providerReference);

            return;
        }

        if ($result->state === PaymentState::Failed) {
            $this->ballot = $confirmPayment->markFailed(
                $this->ballot,
                $result->failureReason ?? 'Paiement refuse par l\'operateur.'
            );
        }
    }

    public function render()
    {
        $candidate = $this->ballot->publicCandidate();

        return view('livewire.public.ballot-status', [
            'candidate' => $candidate,
            // `resolvedPoll` : la relation ordinaire porte la portee par
            // organisation et reviendrait nulle sur ce chemin public.
            'poll' => $candidate?->resolvedCategory()?->resolvedPoll(),
            'category' => $candidate?->resolvedCategory(),
            'secondsLeft' => $this->ballot->secondsBeforeExpiry(),
        ]);
    }
}
