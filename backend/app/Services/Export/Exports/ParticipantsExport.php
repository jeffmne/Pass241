<?php

namespace App\Services\Export\Exports;

use App\Enums\PermissionName;
use App\Models\Event;
use App\Models\Ticket;
use App\Services\Export\Exportable;
use Generator;

/**
 * Participants d'un evenement (section 59).
 *
 * C'est l'export le plus demande : la liste qu'on imprime pour la porte, qu'on
 * envoie au traiteur, ou qu'on garde apres coup. Il porte donc ce qui sert a
 * l'entree — nom, reference, tarif, etat — et de quoi joindre l'acheteur.
 *
 * Les billets ANNULES et REMBOURSES y figurent, avec leur etat : un participant
 * qui se presente avec un billet rembourse doit pouvoir etre identifie comme
 * tel a la porte, et le faire disparaitre de la liste priverait l'organisateur
 * de l'explication.
 */
class ParticipantsExport implements Exportable
{
    public function __construct(private readonly Event $event) {}

    public function label(): string
    {
        return 'Participants';
    }

    public function filename(): string
    {
        return 'participants-'.$this->event->slug.'-'.now()->format('Y-m-d');
    }

    public function headings(): array
    {
        return [
            'Reference billet',
            'Participant',
            'Type de billet',
            'Prix paye',
            'Statut',
            'Entree le',
            'Reference commande',
            'Acheteur',
            'Telephone',
            'Email',
        ];
    }

    public function rows(): Generator
    {
        $tz = config('app.display_timezone');

        /*
         * `lazyById` plutot que `get` : les billets sont lus par paquets, et
         * jamais tous a la fois. Le curseur avance sur la cle primaire, ce qui
         * reste correct meme si des billets sont emis pendant l'export.
         */
        $tickets = Ticket::query()
            ->where('event_id', $this->event->getKey())
            ->with(['orderItem', 'order'])
            ->orderBy('id')
            ->lazyById(500);

        foreach ($tickets as $ticket) {
            yield [
                $ticket->reference,
                $ticket->holder_name,
                $ticket->orderItem?->ticket_type_name,
                $ticket->orderItem?->unit_price,
                $ticket->status->label(),
                $ticket->used_at?->timezone($tz)->format('Y-m-d H:i'),
                $ticket->order?->reference,
                $ticket->order?->buyerName(),
                $ticket->order?->payer_phone,
                $ticket->order?->email,
            ];
        }
    }

    public function permission(): PermissionName
    {
        return PermissionName::AttendeesView;
    }

    public function auditContext(): array
    {
        return ['event' => $this->event->slug];
    }
}
