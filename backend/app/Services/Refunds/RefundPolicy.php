<?php

namespace App\Services\Refunds;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use Carbon\CarbonInterface;

/**
 * Ce qu'un client peut demander, et jusqu'a quand (section 24.1).
 *
 * Toutes les regles de recevabilite vivent ici, et nulle part ailleurs. Le
 * bouton peut rester visible a l'ecran — la section 24.1 le demande, pour que
 * le client comprenne pourquoi il ne peut plus demander — mais c'est le
 * serveur qui tranche, toujours.
 *
 * LE DELAI EST LA PIERRE ANGULAIRE DU MODELE FINANCIER. Fermer les demandes
 * 48h avant l'evenement, alors que le reversement intervient apres
 * (decision D-026), garantit qu'aucun remboursement n'exigera jamais de
 * recuperer des fonds deja partis chez l'organisateur. Reduire ce delai sans
 * revoir le reversement ferait de Pass241 le creancier de ses clients.
 */
class RefundPolicy
{
    /**
     * Heure limite pour demander, sur un evenement donne.
     *
     * L'evenement est relu HORS PORTEE D'ORGANISATION. L'acheteur n'appartient
     * a aucune organisation : une simple lecture de la relation reviendrait
     * null, et la fenetre serait alors consideree comme ouverte pour tout le
     * monde, indefiniment. C'est exactement le genre d'echec en ouvert qu'une
     * regle financiere ne doit jamais avoir.
     */
    public function deadlineFor(Order $order): ?CarbonInterface
    {
        $startsAt = $this->eventStart($order);

        if ($startsAt === null) {
            return null;
        }

        return $startsAt->copy()->subHours(
            (int) config('payments.refunds.window_hours')
        );
    }

    /**
     * La demande est-elle recevable dans le temps ?
     *
     * ECHEC EN FERME : sans date d'evenement connue, la reponse est NON. Un
     * evenement introuvable est une anomalie, et une anomalie ne doit pas
     * ouvrir un droit a remboursement.
     */
    public function isWithinWindow(Order $order): bool
    {
        $deadline = $this->deadlineFor($order);

        return $deadline !== null && $deadline->isFuture();
    }

    private function eventStart(Order $order): ?CarbonInterface
    {
        if ($order->relationLoaded('event') && $order->event) {
            return $order->event->starts_at;
        }

        return Event::query()
            ->acrossOrganizations()
            ->whereKey($order->event_id)
            ->value('starts_at');
    }

    /**
     * Pourquoi la demande est impossible, en une phrase — ou null si elle
     * l'est.
     *
     * Le client doit comprendre, pas seulement se heurter a un bouton inerte.
     */
    public function blocker(Order $order, ?Ticket $ticket = null): ?string
    {
        if (! $order->isPaid()) {
            return 'Cette commande n\'a pas ete payee : il n\'y a rien a rembourser.';
        }

        if ($ticket && ! $ticket->allowsEntry()) {
            return 'Ce billet a deja ete utilise ou annule.';
        }

        if (! $this->isWithinWindow($order)) {
            return sprintf(
                'Les demandes de remboursement se ferment %d heures avant l\'evenement.',
                (int) config('payments.refunds.window_hours'),
            );
        }

        if ($order->refundRequests()->pending()->exists()) {
            return 'Une demande est deja en cours pour cette commande.';
        }

        return null;
    }

    /**
     * Montant rendu au client.
     *
     * Le prix des billets, SANS les frais du prestataire : ils ont ete verses
     * a l'operateur, qui ne les rend pas (decision D-023). Les rembourser
     * quand meme reviendrait a les faire payer par Pass241 a chaque
     * changement d'avis d'un acheteur.
     *
     * Une annulation par l'organisateur suit une autre regle — c'est lui qui
     * les supporte alors — et passera par un chemin distinct.
     */
    public function refundableAmount(Order $order, ?Ticket $ticket = null): int
    {
        if ($ticket === null) {
            return $order->subtotal;
        }

        // Prix reellement paye pour CE billet, releve sur la ligne de
        // commande : le tarif a pu changer depuis l'achat.
        return (int) ($ticket->orderItem?->unit_price ?? 0);
    }

    /** Part des frais du prestataire attachee a ce remboursement, non rendue. */
    public function nonRefundableFee(Order $order, ?Ticket $ticket = null): int
    {
        if ($order->psp_fee_amount <= 0 || $order->subtotal <= 0) {
            return 0;
        }

        if ($ticket === null) {
            return $order->psp_fee_amount;
        }

        $share = $this->refundableAmount($order, $ticket);

        // Prorata en arithmetique entiere, arrondi au plus proche
        // (decision D-003).
        return intdiv($order->psp_fee_amount * $share * 2 + $order->subtotal, $order->subtotal * 2);
    }
}
