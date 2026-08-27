<?php

namespace App\Livewire\Public;

use App\Actions\Payments\ConfirmPayment;
use App\Enums\PaymentState;
use App\Models\Donation;
use App\Services\Fundraising\CampaignProgress;
use App\Services\Payments\PaymentManager;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Suivi d'un don apres le declenchement du paiement (sections 21.1, 53).
 *
 * Le donateur voit ce qu'il doit faire — valider sur son telephone — et combien
 * de temps il lui reste. La page se rafraichit d'elle-meme tant que le don est
 * en attente.
 *
 * Le rafraichissement fait aussi office de POLLING DE SECOURS : les webhooks
 * mobile money peuvent etre en retard ou manquants selon l'operateur.
 * Interroger le prestataire pendant que le donateur regarde son ecran est le
 * moment ou cela coute le moins et sert le plus.
 *
 * LE FRONTEND NE DECIDE JAMAIS QU'UN PAIEMENT EST CONFIRME (sections 22, 50) :
 * cette page lit l'etat, et c'est `ConfirmPayment` — cote serveur — qui
 * tranche. Une requete forgee depuis cette page ne peut rien confirmer.
 */
#[Layout('components.layouts.public')]
class DonationStatus extends Component
{
    #[Locked]
    public Donation $donation;

    public function mount(string $reference): void
    {
        // La portee par organisation est levee : le donateur n'appartient a
        // aucune organisation. L'acces est protege par la signature de l'URL
        // (decision D-004), verifiee par le middleware de la route.
        $this->donation = Donation::publicByReference($reference) ?? abort(404);
    }

    /**
     * Interroge le prestataire, si le don est encore en attente.
     *
     * Ne fait rien lorsque le don a deja un etat definitif : inutile de
     * solliciter l'operateur pour une transaction close.
     */
    public function refreshStatus(PaymentManager $payments, ConfirmPayment $confirmPayment): void
    {
        $this->donation->refresh();

        if (! $this->donation->isPending()) {
            return;
        }

        $result = $payments->driver()->checkPaymentStatus($this->donation);

        if ($result->state === PaymentState::Succeeded) {
            $this->donation = $confirmPayment->handle($this->donation, $result->providerReference);

            return;
        }

        if ($result->state === PaymentState::Failed) {
            $this->donation = $confirmPayment->markFailed(
                $this->donation,
                $result->failureReason ?? 'Paiement refuse par l\'operateur.'
            );
        }
    }

    public function render(CampaignProgress $progress)
    {
        /*
         * Jamais `$this->donation->campaign` : Livewire rehydrate le modele
         * sans ses relations a chaque rafraichissement, et la relation
         * ordinaire porte la portee par organisation — elle reviendrait nulle
         * ici, ou personne n'appartient a une organisation.
         */
        $campaign = $this->donation->publicCampaign();

        return view('livewire.public.donation-status', [
            'campaign' => $campaign,
            'secondsLeft' => $this->donation->secondsBeforeExpiry(),
            // La progression est relue a chaque rendu : le donateur qui vient
            // de confirmer doit voir son don compte, c'est la recompense du
            // geste (section 37).
            'progress' => $campaign ? $progress->forCampaign($campaign) : null,
            // Ecran de simulation reserve au provider de developpement : il
            // n'existe pas des lors qu'un vrai operateur est branche.
            'canSimulate' => app(PaymentManager::class)->isFake() && app()->environment('local'),
        ])->layoutData([
            'title' => 'Don '.$this->donation->reference.' — Pass241',
            'indexable' => false,
        ]);
    }
}
