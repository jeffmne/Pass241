<?php

namespace App\Services\Export\Exports;

use App\Enums\PermissionName;
use App\Models\Event;
use App\Models\Order;
use App\Services\Export\Exportable;
use Generator;

/**
 * Commandes d'un evenement (section 59).
 *
 * Document COMPTABLE, contrairement a l'export des participants : il porte les
 * montants, leur eclatement et l'operateur, pour se rapprocher d'un relevé
 * mobile money.
 *
 * LES TROIS MONTANTS Y FIGURENT SEPAREMENT (decision D-023). Le total debite
 * contient les frais du prestataire, avances par l'acheteur : les confondre
 * ferait croire a l'organisateur qu'il a encaisse une somme qui ne lui est
 * jamais revenue. La commission Pass241, elle, n'y est pas — elle se lit dans
 * le reversement, pas dans la commande.
 *
 * Les invitations apparaissent avec leur origine et un montant nul : elles ont
 * pris une place, et une liste de commandes qui les tairait ne se reconcilierait
 * pas avec le nombre de billets emis (decision D-039).
 */
class OrdersExport implements Exportable
{
    public function __construct(private readonly Event $event) {}

    public function label(): string
    {
        return 'Commandes';
    }

    public function filename(): string
    {
        return 'commandes-'.$this->event->slug.'-'.now()->format('Y-m-d');
    }

    public function headings(): array
    {
        return [
            'Reference',
            'Origine',
            'Statut',
            'Acheteur',
            'Telephone',
            'Email',
            'Billets',
            'Sous-total',
            'Frais de paiement',
            'Total debite',
            'Operateur',
            'Reference operateur',
            'Payee le',
            'Creee le',
        ];
    }

    public function rows(): Generator
    {
        $tz = config('app.display_timezone');

        $orders = Order::query()
            ->where('event_id', $this->event->getKey())
            ->withCount('tickets')
            ->orderBy('id')
            ->lazyById(500);

        foreach ($orders as $order) {
            yield [
                $order->reference,
                $order->origin->label(),
                $order->status->label(),
                $order->buyerName(),
                $order->payer_phone,
                $order->email,
                $order->tickets_count,
                $order->subtotal,
                $order->psp_fee_amount,
                $order->amount,
                $order->payment_provider?->label(),
                $order->provider_reference,
                $order->paid_at?->timezone($tz)->format('Y-m-d H:i'),
                $order->created_at?->timezone($tz)->format('Y-m-d H:i'),
            ];
        }
    }

    /**
     * Permission FINANCIERE, et non celle des participants.
     *
     * Cet export porte des montants : un membre autorise a voir la liste des
     * participants n'a pas pour autant a connaitre le chiffre d'affaires
     * (section 10).
     */
    public function permission(): PermissionName
    {
        return PermissionName::FinancesView;
    }

    public function auditContext(): array
    {
        return ['event' => $this->event->slug];
    }
}
