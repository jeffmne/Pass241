<?php

namespace App\Jobs;

use App\Mail\TicketsIssued;
use App\Models\Order;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Envoie la copie du billet par email (section 69).
 *
 * En file d'attente, et non dans la requete : cet email transporte desormais le
 * billet en PDF, dont le rendu coute quelques centaines de millisecondes par
 * billet. L'appel qui declenche l'envoi est le webhook de l'operateur mobile
 * money, qui abandonne s'il attend trop — la confirmation de paiement ne peut
 * pas payer le prix d'une mise en page.
 *
 * L'email reste une COMMODITE : le billet est deja emis et consultable quand ce
 * job demarre. S'il echoue, personne ne perd son billet (section 15.1).
 */
class SendTicketsEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $orderId) {}

    public function handle(OrganizationContext $context): void
    {
        // Un worker de file d'attente n'a aucun contexte d'organisation : la
        // commande est donc retrouvee hors portee.
        $order = Order::query()->acrossOrganizations()->find($this->orderId);

        if (! $order || ! $order->email) {
            return;
        }

        /*
         * Le chargement des relations se fait DANS le contexte de
         * l'organisation : hors contexte, la portee globale masquerait
         * l'evenement et l'email partirait sans son nom.
         */
        $context->runFor($order->organization, function () use ($order) {
            $order->load('tickets.orderItem', 'event.location', 'event.organization');

            Mail::to($order->email)->send(new TicketsIssued($order));
        });
    }
}
