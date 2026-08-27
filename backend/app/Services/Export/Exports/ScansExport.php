<?php

namespace App\Services\Export\Exports;

use App\Enums\PermissionName;
use App\Models\AccessScan;
use App\Models\Event;
use App\Services\Export\Exportable;
use Generator;

/**
 * Controles a l'entree d'un evenement (sections 27.15, 59).
 *
 * TOUS LES SCANS, pas seulement les entrees accordees. Un export qui ne
 * garderait que les acceptes ne repondrait pas aux questions qu'on se pose
 * apres coup : combien de refus, a quelle porte, pour quel motif, et combien
 * d'entrees accordees sans lecture du QR.
 *
 * Le journal des scans est en ajout seul (section 68) : cet export est donc une
 * copie fidele de ce qui s'est passe, et rien de ce qu'il contient n'a pu etre
 * corrige apres coup.
 */
class ScansExport implements Exportable
{
    public function __construct(private readonly Event $event) {}

    public function label(): string
    {
        return 'Controles a l\'entree';
    }

    public function filename(): string
    {
        return 'controles-'.$this->event->slug.'-'.now()->format('Y-m-d');
    }

    public function headings(): array
    {
        return [
            'Horodatage',
            'Resultat',
            'Billet',
            'Participant',
            'Point d\'entree',
            'Agent',
            'Sans lecture du QR',
            'Appareil',
            'Decalage horloge (s)',
        ];
    }

    public function rows(): Generator
    {
        $tz = config('app.display_timezone');

        $scans = AccessScan::query()
            ->where('event_id', $this->event->getKey())
            ->with(['ticket', 'entryPoint', 'agent'])
            ->orderBy('id')
            ->lazyById(500);

        foreach ($scans as $scan) {
            yield [
                $scan->scanned_at?->timezone($tz)->format('Y-m-d H:i:s'),
                $scan->result->label(),
                $scan->ticket?->reference,
                $scan->ticket?->holder_name,
                $scan->entryPoint?->name,
                $scan->agent?->name,
                // Section 27.16 : l'admission manuelle se compte a part. C'est
                // le chiffre qu'un organisateur regarde en premier quand il
                // soupconne un probleme a la porte.
                $scan->manual ? 'Oui' : 'Non',
                $scan->device_id,
                $scan->clock_offset_seconds,
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
