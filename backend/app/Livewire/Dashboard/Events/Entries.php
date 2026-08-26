<?php

namespace App\Livewire\Dashboard\Events;

use App\Enums\PermissionName;
use App\Enums\ScanResult;
use App\Enums\TicketStatus;
use App\Models\AccessScan;
use App\Models\EntryPoint;
use App\Models\Event;
use App\Models\Ticket;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Les entrees d'un evenement, vues par l'organisateur (sections 27.2, 30).
 *
 * Pendant la soiree, c'est l'ecran qu'on garde ouvert : combien sont entres,
 * par quelle porte, et combien reste-t-il a attendre. Le lendemain, c'est
 * celui qui repond aux questions qu'on pose apres coup — « cette personne
 * est-elle entree ? », « pourquoi ce billet a-t-il ete refuse ? ».
 *
 * EN LECTURE SEULE, et definitivement. Le journal des scans est en ajout seul,
 * protege par declencheur PostgreSQL (section 68) : cet ecran n'expose aucune
 * correction, aucune suppression, et n'en exposera jamais. Un organisateur qui
 * pourrait effacer une entree pourrait nier une presence.
 *
 * L'ecran REND VISIBLE l'admission manuelle. Elle est legitime — un QR
 * illisible arrive — mais sa verification est plus faible qu'un scan signe, et
 * c'est la seule chose qu'on veut pouvoir compter apres coup. La cacher
 * reviendrait a la rendre indetectable.
 *
 * Aucun filtrage par organisation n'apparait dans les requetes : il est
 * applique par la portee globale du trait BelongsToOrganization, et la liaison
 * de route sur l'evenement a deja verifie l'appartenance (section 9).
 */
#[Layout('components.layouts.app')]
class Entries extends Component
{
    use WithPagination;

    public Event $event;

    /** Porte, ou '' pour toutes. */
    #[Url(as: 'porte')]
    public string $entryPoint = '';

    /** Code de resultat, ou '' pour tous. */
    #[Url(as: 'resultat')]
    public string $result = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(Event $event): void
    {
        /*
         * `attendees.view` et non `access_control.manage` : cet ecran nomme des
         * personnes. Gerer les portes et lire la liste de ceux qui sont entres
         * sont deux droits differents, et c'est le second qui est en jeu ici.
         */
        abort_unless(auth()->user()->can(PermissionName::AttendeesView->value), 403);

        $this->event = $event;
    }

    public function updatingEntryPoint(): void
    {
        $this->resetPage();
    }

    public function updatingResult(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['entryPoint', 'result', 'search']);
        $this->resetPage();
    }

    public function render()
    {
        $scans = AccessScan::query()
            ->where('event_id', $this->event->getKey())
            ->with(['ticket.orderItem', 'entryPoint', 'agent'])
            ->when($this->entryPoint === 'none', fn ($query) => $query->whereNull('entry_point_id'))
            ->when(
                $this->entryPoint !== '' && $this->entryPoint !== 'none',
                fn ($query) => $query->where('entry_point_id', (int) $this->entryPoint)
            )
            ->when($this->result === 'manual', fn ($query) => $query->where('manual', true))
            ->when(
                $this->result !== '' && $this->result !== 'manual',
                fn ($query) => $query->where('result', $this->result)
            )
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($this->search)).'%';

                // Le refus d'un QR non reconnu n'a pas de billet : la recherche
                // par nom ne le fera donc jamais remonter, et c'est exact —
                // il n'y a personne a nommer derriere.
                $query->whereHas('ticket', fn ($t) => $t
                    ->where('reference', 'ilike', $term)
                    ->orWhere('holder_name', 'ilike', $term));
            })
            // L'heure du CONTROLE, pas celle de la synchronisation : un lot
            // remonte apres coup doit se ranger a l'heure ou les gens sont
            // passes, pas a celle ou le reseau est revenu.
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->paginate(30);

        return view('livewire.dashboard.events.entries', [
            'scans' => $scans,
            'stats' => $this->stats(),
            'entryPoints' => EntryPoint::query()
                ->where('event_id', $this->event->getKey())
                ->orderBy('position')->orderBy('id')
                ->get(),
        ]);
    }

    /**
     * Compteurs de l'evenement, agreges en SQL.
     *
     * Une seule requete pour tous les codes de resultat : les compter un par un
     * ferait sept parcours de la meme table, et sur un evenement de 100 000
     * billets chacun coute (section 72).
     *
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $byResult = AccessScan::query()
            ->where('event_id', $this->event->getKey())
            ->selectRaw('result, count(*) as total, count(*) filter (where manual) as manual')
            ->groupBy('result')
            ->get()
            // Cle sur la VALEUR de l'enum : le modele caste `result`, et
            // comparer des instances d'enum dans un `firstWhere` marche par
            // accident plus que par contrat.
            ->mapWithKeys(fn (AccessScan $row) => [$row->result->value => [
                'total' => (int) $row->getAttributes()['total'],
                'manual' => (int) $row->getAttributes()['manual'],
            ]]);

        $count = fn (ScanResult $result) => $byResult[$result->value]['total'] ?? 0;

        $admitted = $count(ScanResult::Accepted);

        /*
         * Billets attendus a la porte : rembourses et annules exclus. Ils ne se
         * presenteront pas, et les compter afficherait un taux de presence
         * incapable d'atteindre 100 % — un chiffre faux est pire qu'aucun.
         */
        $expected = Ticket::query()
            ->where('event_id', $this->event->getKey())
            ->whereIn('status', [TicketStatus::Active->value, TicketStatus::Used->value])
            ->count();

        $scanned = (int) $byResult->sum('total');

        return [
            'scanned' => $scanned,
            'admitted' => $admitted,
            'expected' => $expected,
            'remaining' => max(0, $expected - $admitted),
            'attendanceRate' => $expected > 0 ? $admitted / $expected * 100 : 0.0,
            'refused' => $scanned - $admitted,
            // Admissions accordees sans lecture du QR (section 27.15).
            'manual' => (int) $byResult->sum('manual'),
            'byResult' => collect(ScanResult::cases())
                ->mapWithKeys(fn (ScanResult $case) => [$case->value => $count($case)])
                ->filter()
                ->all(),
            // Un double scan arbitre signale soit une horloge deregle, soit une
            // tentative de passer a deux avec le meme billet (section 31.1).
            'conflicts' => AccessScan::query()
                ->where('event_id', $this->event->getKey())
                ->whereNotNull('conflict_with')
                ->count(),
        ];
    }
}
