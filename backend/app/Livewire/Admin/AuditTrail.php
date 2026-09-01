<?php

namespace App\Livewire\Admin;

use App\Enums\AuditAction;
use App\Livewire\Concerns\SuperAdminOnly;
use App\Models\AuditLog;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Journal d'audit de la plateforme, pour le Super Admin (sections 58, 67).
 *
 * C'est l'ecran qui donne sa valeur aux autres : tout le reste montre un etat,
 * celui-ci montre COMMENT on y est arrive. C'est ici qu'on arbitre un litige
 * entre un client et un organisateur des mois plus tard.
 *
 * Le journal est en AJOUT SEUL, garanti par declencheur PostgreSQL. Cet ecran
 * ne fait que le lire — il n'expose aucune suppression ni aucun filtre
 * destructif, et n'en exposera jamais : un journal qu'on peut nettoyer ne
 * prouve rien.
 *
 * Les metadonnees sont affichees telles qu'elles ont ete ecrites. Les numeros
 * de telephone y sont deja masques a l'ecriture (decision D-025) : rien n'est
 * a filtrer ici, et filtrer a l'affichage donnerait la fausse impression que
 * la donnee brute est propre.
 */
#[Layout('components.layouts.admin')]
class AuditTrail extends Component
{
    use SuperAdminOnly;

    use WithPagination;

    public string $action = '';

    public string $search = '';

    public function updatingAction(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $logs = AuditLog::query()
            ->with(['organization', 'user'])
            ->when($this->action !== '', fn ($query) => $query->where('action', $this->action))
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';

                $query->where(fn ($q) => $q
                    ->whereHas('organization', fn ($o) => $o->where('name', 'ilike', $term))
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'ilike', $term)));
            })
            ->latest('created_at')
            ->paginate(30);

        return view('livewire.admin.audit-trail', [
            'logs' => $logs,
            /*
             * Seules les actions REELLEMENT presentes dans le journal sont
             * proposees au filtre. Lister les cent valeurs de l'enum donnerait
             * un menu dont la plupart des entrees ne renvoient rien.
             */
            'actions' => AuditLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->map(fn (AuditAction $action) => $action)
                ->all(),
        ]);
    }
}
