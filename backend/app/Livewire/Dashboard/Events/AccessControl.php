<?php

namespace App\Livewire\Dashboard\Events;

use App\Actions\Access\AssignAgent;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Livewire\Concerns\RequiresPermission;
use App\Models\AccessScan;
use App\Models\EntryPoint;
use App\Models\Event;
use App\Models\EventAgent;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Gestion du controle d'acces d'un evenement (sections 29, 30).
 *
 * Points d'entree et agents sur le meme ecran, volontairement : un agent
 * s'affecte a une porte, et separer les deux obligerait a naviguer entre deux
 * pages pour une seule decision.
 *
 * Aucun filtrage par organisation n'apparait dans les requetes : il est
 * applique par la portee globale du trait BelongsToOrganization, et la liaison
 * de route sur l'evenement a deja verifie l'appartenance (section 9).
 *
 * Le mot de passe genere d'un nouvel agent n'est JAMAIS stocke : il vit dans
 * l'etat du composant le temps d'etre lu, et disparait au rafraichissement
 * suivant.
 */
#[Layout('components.layouts.app')]
class AccessControl extends Component
{
    use RequiresPermission;

    public Event $event;

    /* Point d'entree */
    public bool $showEntryForm = false;

    public ?int $editingEntryId = null;

    public string $entryName = '';

    public string $entryDescription = '';

    /* Agent */
    public bool $showAgentForm = false;

    public string $agentName = '';

    public string $agentEmail = '';

    public ?int $agentEntryPointId = null;

    /** Identifiants a transmettre, affiches une seule fois. */
    public ?array $newAgentCredentials = null;

    protected function requiredPermission(): PermissionName
    {
        return PermissionName::AccessControlManage;
    }

    public function mount(Event $event): void
    {
        $this->event = $event;
    }

    /*
     * ---------------------------------------------------------------------
     * Points d'entree
     * ---------------------------------------------------------------------
     */

    public function editEntry(int $id): void
    {
        $entry = EntryPoint::query()->whereKey($id)->firstOrFail();

        $this->editingEntryId = $entry->getKey();
        $this->entryName = $entry->name;
        $this->entryDescription = $entry->description ?? '';
        $this->showEntryForm = true;
    }

    public function saveEntry(AuditLogger $audit): void
    {
        $this->authorizeManage();

        $data = $this->validate([
            'entryName' => ['required', 'string', 'min:2', 'max:80'],
            'entryDescription' => ['nullable', 'string', 'max:160'],
        ], attributes: [
            'entryName' => 'nom du point d\'entree',
            'entryDescription' => 'description',
        ]);

        $attributes = [
            'name' => trim($data['entryName']),
            'description' => trim($data['entryDescription']) ?: null,
        ];

        try {
            /*
             * Ecriture dans une transaction imbriquee, donc dans un POINT DE
             * SAUVEGARDE. PostgreSQL avorte la transaction entiere des qu'une
             * contrainte est violee : sans ce point, un nom en double
             * empoisonnerait la requete suivante et l'ecran planterait au lieu
             * d'afficher une erreur de saisie.
             */
            DB::transaction(function () use ($attributes) {
                if ($this->editingEntryId) {
                    EntryPoint::query()->whereKey($this->editingEntryId)->firstOrFail()->update($attributes);

                    return;
                }

                EntryPoint::create($attributes + [
                    'event_id' => $this->event->getKey(),
                    'position' => (int) EntryPoint::query()
                        ->where('event_id', $this->event->getKey())->max('position') + 1,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Deux entrees du meme nom rendraient l'historique de controle
            // inexploitable : « qui est passe par l'entree VIP ? » n'aurait
            // plus de reponse unique.
            $this->addError('entryName', 'Une entree porte deja ce nom sur cet evenement.');

            return;
        }

        $audit->log(AuditAction::EventUpdated, $this->event, [
            'entry_point' => $attributes['name'],
        ]);

        $this->resetEntryForm();
        $this->dispatch('notify', message: 'Point d\'entree enregistre.');
    }

    /**
     * Ouvre ou ferme une entree.
     *
     * Fermer plutot que supprimer : une entree fermee en cours de soiree ne
     * recoit plus de scan, mais l'historique de ceux qui y sont deja passes
     * reste lisible.
     */
    public function toggleEntry(int $id): void
    {
        $this->authorizeManage();

        $entry = EntryPoint::query()->whereKey($id)->firstOrFail();
        $entry->update(['is_active' => ! $entry->is_active]);
    }

    public function resetEntryForm(): void
    {
        $this->reset(['showEntryForm', 'editingEntryId', 'entryName', 'entryDescription']);
        $this->resetErrorBag();
    }

    /*
     * ---------------------------------------------------------------------
     * Agents
     * ---------------------------------------------------------------------
     */

    public function saveAgent(AssignAgent $assign): void
    {
        $this->authorizeManage();

        $data = $this->validate([
            'agentName' => ['required', 'string', 'min:2', 'max:80'],
            'agentEmail' => ['required', 'email', 'max:255'],
            'agentEntryPointId' => ['nullable', 'integer'],
        ], attributes: [
            'agentName' => 'nom de l\'agent',
            'agentEmail' => 'adresse email',
            'agentEntryPointId' => 'point d\'entree',
        ]);

        // Le point d'entree doit appartenir a CET evenement : un identifiant
        // venu du navigateur ne doit pas pouvoir affecter un agent a la porte
        // d'un autre evenement (regle 80.4).
        if ($data['agentEntryPointId'] !== null) {
            EntryPoint::query()
                ->where('event_id', $this->event->getKey())
                ->whereKey($data['agentEntryPointId'])
                ->firstOrFail();
        }

        try {
            $result = $assign->handle(
                event: $this->event,
                email: $data['agentEmail'],
                name: $data['agentName'],
                entryPointId: $data['agentEntryPointId'],
                actor: auth()->user(),
            );
        } catch (RuntimeException $e) {
            $this->addError('agentEmail', $e->getMessage());

            return;
        }

        $this->newAgentCredentials = $result['password'] === null ? null : [
            'email' => $data['agentEmail'],
            'password' => $result['password'],
        ];

        $this->reset(['showAgentForm', 'agentName', 'agentEmail', 'agentEntryPointId']);
        $this->resetErrorBag();

        $this->dispatch('notify', message: 'Agent affecte.');
    }

    public function revokeAgent(int $id, AssignAgent $assign): void
    {
        $this->authorizeManage();

        $assignment = EventAgent::query()->whereKey($id)->firstOrFail();

        $assign->revoke($assignment, auth()->user());

        $this->dispatch('notify', message: 'Agent retire de cet evenement.');
    }

    public function dismissCredentials(): void
    {
        $this->newAgentCredentials = null;
    }

    private function authorizeManage(): void
    {
        abort_unless(
            auth()->user()->can(PermissionName::AccessControlManage->value),
            403,
            'Vous n\'avez pas le droit de gerer le controle d\'acces.'
        );
    }

    public function render()
    {
        /*
         * Activite des agents, agregee en SQL.
         *
         * Compter les scans agent par agent ferait autant de requetes que
         * d'agents ; sur un evenement de 100 000 billets, chacune parcourrait
         * un volume consequent (section 27.2, « consulter l'activite »).
         */
        $activity = DB::table('access_scans')
            ->where('event_id', $this->event->getKey())
            ->whereNotNull('agent_id')
            ->groupBy('agent_id')
            ->selectRaw("
                agent_id,
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE result = 'ACCEPTED') AS accepted,
                MAX(scanned_at) AS last_scan
            ")
            ->get()
            ->keyBy('agent_id');

        return view('livewire.dashboard.events.access-control', [
            'entryPoints' => EntryPoint::query()
                ->where('event_id', $this->event->getKey())
                ->orderBy('position')->orderBy('id')
                ->withCount(['agents as agents_count' => fn ($q) => $q->where('is_active', true)])
                ->get(),
            'agents' => EventAgent::query()
                ->where('event_id', $this->event->getKey())
                ->with(['agent', 'entryPoint'])
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->get(),
            'activity' => $activity,
            'scanTotal' => AccessScan::query()
                ->where('event_id', $this->event->getKey())
                ->count(),
        ]);
    }
}
