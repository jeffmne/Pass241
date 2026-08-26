<?php

namespace App\Livewire\Admin;

use App\Enums\PayoutState;
use App\Models\Settlement;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Console des reversements, reservee au Super Admin (section 58).
 *
 * Consultation seule, et c'est structurel : il n'existe aucune methode pour
 * declencher, rejouer ou annuler un reversement depuis un ecran. Le
 * reversement est instruit par une commande planifiee, et son montant est gele
 * par declencheur en base (decision D-026). « Les donnees financieres
 * historiques restent protegees contre les modifications arbitraires »
 * (section 58) — ici cela veut dire qu'aucun bouton n'existe, pas qu'un bouton
 * est desactive.
 *
 * SEUL ECRAN DU PRODUIT QUI TRAVERSE LES ORGANISATIONS. Toutes les requetes
 * portent `acrossOrganizations()` de facon explicite et assumee : c'est la
 * raison d'etre du role. Partout ailleurs, une telle levee de portee serait un
 * defaut (regle 80.4).
 */
#[Layout('components.layouts.admin')]
class Settlements extends Component
{
    use WithPagination;

    public string $filter = 'all';

    public string $search = '';

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $settlements = Settlement::query()
            ->acrossOrganizations()
            ->with([
                'organization',
                'event' => fn ($query) => $query->acrossOrganizations(),
            ])
            ->when($this->filter === 'attention', fn ($query) => $query
                // Les deux etats sur lesquels quelqu'un doit se pencher : un
                // virement echoue, et un virement dont on ignore le sort.
                ->whereIn('status', [PayoutState::Failed->value, PayoutState::Unknown->value]))
            ->when($this->filter === 'pending', fn ($query) => $query
                ->whereIn('status', [PayoutState::Pending->value, PayoutState::Scheduled->value]))
            ->when($this->filter === 'settled', fn ($query) => $query
                ->where('status', PayoutState::Succeeded->value))
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';

                $query->where(fn ($q) => $q
                    ->where('reference', 'ilike', $term)
                    ->orWhere('provider_reference', 'ilike', $term)
                    ->orWhereHas('organization', fn ($o) => $o->where('name', 'ilike', $term)));
            })
            ->latest()
            ->paginate(25);

        return view('livewire.admin.settlements', [
            'settlements' => $settlements,
            'totals' => $this->totals(),
            'attentionCount' => Settlement::query()->acrossOrganizations()
                ->whereIn('status', [PayoutState::Failed->value, PayoutState::Unknown->value])
                ->count(),
        ]);
    }

    /**
     * Totaux par etat, en une seule requete agregee.
     *
     * Agregation SQL et non parcours de collection : cette console doit rester
     * utilisable quand la plateforme aura des dizaines de milliers de
     * reversements. Charger les lignes pour les additionner en PHP marcherait
     * six mois, puis plus du tout.
     *
     * @return array<string, array{count: int, amount: int}>
     */
    private function totals(): array
    {
        $rows = Settlement::query()
            ->acrossOrganizations()
            ->select('status', DB::raw('COUNT(*) AS count'), DB::raw('SUM(net_amount) AS amount'))
            ->groupBy('status')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[$row->status->value] = [
                'count' => (int) $row->count,
                'amount' => (int) $row->amount,
            ];
        }

        return $totals;
    }
}
