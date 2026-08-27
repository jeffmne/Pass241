<?php

namespace App\Jobs;

use App\Mail\DonationReceived;
use App\Models\Donation;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Envoie la confirmation et le recu d'un don (sections 53, 69).
 *
 * En file d'attente, et non dans la requete : cet email porte un PDF, dont le
 * rendu coute quelques centaines de millisecondes. L'appel qui declenche
 * l'envoi est le webhook de l'operateur mobile money, qui abandonne s'il attend
 * trop — la confirmation d'un paiement ne peut pas payer le prix d'une mise en
 * page.
 *
 * Si l'envoi echoue, le don reste confirme et compte dans la collecte : le
 * donateur garde son lien de suivi, ou le recu se telecharge. L'email est une
 * commodite, pas le canal de livraison.
 */
class SendDonationReceipt implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $donationId) {}

    public function handle(OrganizationContext $context): void
    {
        // Un worker de file d'attente n'a aucun contexte d'organisation : le don
        // est donc retrouve hors portee.
        $donation = Donation::query()->acrossOrganizations()->find($this->donationId);

        if (! $donation || ! $donation->donor_email || ! $donation->isConfirmed()) {
            return;
        }

        $organization = $donation->organization;

        if (! $organization) {
            return;
        }

        /*
         * Le chargement des relations se fait DANS le contexte de
         * l'organisation : hors contexte, la portee globale masquerait la
         * campagne et l'email partirait sans son nom.
         */
        $context->runFor($organization, function () use ($donation) {
            $donation->load([
                'campaign' => fn ($query) => $query->acrossOrganizations()->with('organization'),
            ]);

            Mail::to($donation->donor_email)->send(new DonationReceived($donation));
        });
    }
}
