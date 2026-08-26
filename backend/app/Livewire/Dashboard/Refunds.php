<?php

namespace App\Livewire\Dashboard;

use App\Actions\Refunds\DecideRefund;
use App\Enums\PermissionName;
use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Decisions de remboursement de l'organisateur (section 24.1).
 *
 * Le client a demande ; c'est ici qu'on tranche. Une decision ne se reprend
 * pas : le modele refuse de rouvrir une demande deja tranchee, et l'ecran ne
 * propose donc aucune action sur celles-la.
 *
 * Les demandes en attente passent en premier, sans option de tri : ce sont les
 * seules sur lesquelles quelqu'un attend une reponse.
 */
#[Layout('components.layouts.app')]
class Refunds extends Component
{
    use WithPagination;

    public string $filter = 'pending';

    /** Motif de refus, par demande. */
    public array $reasons = [];

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function approve(int $id, DecideRefund $action): void
    {
        $this->decide($id, fn (RefundRequest $request) => $action->approve($request, auth()->user()));
    }

    public function reject(int $id, DecideRefund $action): void
    {
        $reason = trim($this->reasons[$id] ?? '');

        if ($reason === '') {
            // Section 24.1 : un refus se motive. Sans motif, le client ne peut
            // ni comprendre ni contester.
            $this->addError('reason.'.$id, 'Indiquez le motif du refus.');

            return;
        }

        $this->decide($id, fn (RefundRequest $request) => $action->reject($request, auth()->user(), $reason));

        unset($this->reasons[$id]);
    }

    private function decide(int $id, callable $decision): void
    {
        abort_unless(
            auth()->user()->can(PermissionName::FinancesRefund->value),
            403,
            'Vous n\'avez pas le droit de trancher les remboursements.'
        );

        // Relu dans la portee de l'organisation : un identifiant venu du
        // navigateur ne doit jamais atteindre la demande d'une autre
        // organisation (regle 80.4).
        $request = RefundRequest::query()->whereKey($id)->firstOrFail();

        try {
            $decision($request);
        } catch (RuntimeException $e) {
            $this->addError('decision', $e->getMessage());

            return;
        }

        $this->dispatch('notify', message: 'Decision enregistree.');
    }

    public function render()
    {
        $requests = RefundRequest::query()
            ->with(['order', 'ticket', 'decidedBy'])
            ->when($this->filter === 'pending', fn ($query) => $query->pending())
            ->when($this->filter === 'decided', fn ($query) => $query
                ->whereIn('status', [
                    RefundStatus::Approved->value,
                    RefundStatus::Rejected->value,
                    RefundStatus::Expired->value,
                ]))
            // Les demandes en attente d'abord : ce sont les seules sur
            // lesquelles quelqu'un attend une reponse.
            ->orderByRaw("CASE WHEN status = 'REQUESTED' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate(20);

        return view('livewire.dashboard.refunds', [
            'requests' => $requests,
            'pendingCount' => RefundRequest::query()->pending()->count(),
            'canDecide' => auth()->user()->can(PermissionName::FinancesRefund->value),
        ]);
    }
}
