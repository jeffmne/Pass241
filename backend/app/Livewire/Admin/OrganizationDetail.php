<?php

namespace App\Livewire\Admin;

use App\Actions\Organizations\SuspendOrganization;
use App\Enums\DonationStatus;
use App\Enums\PayoutState;
use App\Enums\RefundStatus;
use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Settlement;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Fiche d'une organisation, pour le Super Admin (section 58).
 *
 * Tout ce qu'il faut pour repondre a un appel : qui est ce compte, ou en est sa
 * verification, ce qu'il a vendu, ce qu'il a recu, ce qu'on lui a verse, et ce
 * qu'il a fait recemment.
 *
 * DEUX PRINCIPES TIENNENT CET ECRAN :
 *
 * 1. IL LIT TOUT, IL N'ECRIT QU'UNE CHOSE. La seule action est la suspension —
 *    et sa levee. Aucune donnee financiere n'est modifiable ici, ni ailleurs
 *    dans la console : billets, commandes, dons et commissions sont proteges
 *    par des declencheurs en base, y compris contre le Super Admin.
 *
 * 2. LA PORTEE EST LEVEE PARTOUT, EXPLICITEMENT. Cet ecran vit hors du
 *    middleware d'organisation : le Super Admin n'appartient a aucune et les
 *    voit toutes. Chaque requete le dit donc en toutes lettres, plutot que de
 *    dependre d'un contexte absent.
 */
#[Layout('components.layouts.admin')]
class OrganizationDetail extends Component
{
    public Organization $organization;

    public bool $confirming = false;

    public string $reason = '';

    public function mount(Organization $organization): void
    {
        $this->organization = $organization;
    }

    /*
     * -----------------------------------------------------------------------
     * La seule ecriture de la console (section 10)
     * -----------------------------------------------------------------------
     */

    public function startDecision(): void
    {
        $this->confirming = true;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function cancelDecision(): void
    {
        $this->confirming = false;
        $this->reason = '';
    }

    /**
     * Suspend le compte, ou le reactive.
     *
     * Une confirmation explicite est exigee dans les deux sens : suspendre
     * coupe les ventes en cours de tous les evenements du compte, et reactiver
     * les rouvre. Ni l'un ni l'autre ne doit tenir a un clic isole.
     */
    public function decide(SuspendOrganization $suspensions): void
    {
        $this->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: ['reason' => 'motif']);

        try {
            $this->organization = $this->organization->isSuspended()
                ? $suspensions->reactivate($this->organization, auth()->user(), trim($this->reason))
                : $suspensions->suspend($this->organization, auth()->user(), trim($this->reason));
        } catch (RuntimeException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }

        $this->cancelDecision();

        $this->dispatch('notify', message: $this->organization->isSuspended()
            ? 'Compte suspendu. Les ventes sont fermees.'
            : 'Compte reactive.');
    }

    public function render()
    {
        $id = $this->organization->getKey();

        return view('livewire.admin.organization-detail', [
            'sales' => $this->sales($id),
            'fundraising' => $this->fundraising($id),
            'settlements' => Settlement::query()
                ->acrossOrganizations()
                ->where('organization_id', $id)
                ->with('settlable')
                ->latest()
                ->limit(10)
                ->get(),
            'settledTotal' => (int) Settlement::query()
                ->acrossOrganizations()
                ->where('organization_id', $id)
                ->where('status', PayoutState::Succeeded->value)
                ->sum('net_amount'),
            'events' => Event::query()
                ->acrossOrganizations()
                ->where('organization_id', $id)
                ->latest('starts_at')
                ->limit(10)
                ->get(),
            'eventCount' => Event::query()->acrossOrganizations()->where('organization_id', $id)->count(),
            'campaigns' => Campaign::query()
                ->acrossOrganizations()
                ->where('organization_id', $id)
                ->latest('id')
                ->limit(10)
                ->get(),
            'members' => $this->organization->members()->with('user')->get(),
            /*
             * Activite recente du compte. Volontairement courte : dix lignes
             * se lisent d'un coup d'oeil, cinquante demandent un effort et ne
             * seraient plus regardees. Le journal complet a son propre ecran.
             */
            'activity' => AuditLog::query()
                ->where('organization_id', $id)
                ->with('user')
                ->latest('created_at')
                ->limit(10)
                ->get(),
        ]);
    }

    /**
     * Ventes du compte, agregees en SQL.
     *
     * Les tables sont attaquees directement, comme dans `PlatformStatistics` :
     * la portee globale n'a rien a filtrer ici, et l'ecrire en SQL rend
     * l'intention lisible plutot que dependante d'un `acrossOrganizations()`
     * qu'on pourrait oublier.
     *
     * @return array{tickets: int, gross: int, commission: int, refunded: int}
     */
    private function sales(int $organizationId): array
    {
        $tickets = DB::table('tickets')
            ->join('order_items', 'order_items.id', '=', 'tickets.order_item_id')
            ->where('tickets.organization_id', $organizationId)
            ->whereIn('tickets.status', [TicketStatus::Active->value, TicketStatus::Used->value])
            ->selectRaw('COUNT(*) AS sold, COALESCE(SUM(order_items.unit_price), 0) AS gross')
            ->first();

        /*
         * Commission NETTE : les lignes d'origine moins les ajustements de
         * remboursement (decision D-027). Les additionner sans distinguer les
         * deux doublerait ce que la plateforme a reellement retenu.
         */
        $commission = DB::table('commissions')
            ->where('organization_id', $organizationId)
            ->selectRaw('COALESCE(SUM(
                CASE WHEN parent_commission_id IS NULL
                     THEN commission_amount
                     ELSE -commission_amount END
            ), 0) AS total')
            ->value('total');

        return [
            'tickets' => (int) ($tickets->sold ?? 0),
            // Ce qui a transite, pas ce que l'organisation a garde.
            'gross' => (int) ($tickets->gross ?? 0),
            'commission' => (int) $commission,
            'refunded' => (int) DB::table('refund_requests')
                ->where('organization_id', $organizationId)
                ->where('status', RefundStatus::Approved->value)
                ->sum('amount'),
        ];
    }

    /**
     * @return array{campaigns: int, donations: int, collected: int}
     */
    private function fundraising(int $organizationId): array
    {
        $donations = DB::table('donations')
            ->where('organization_id', $organizationId)
            ->where('status', DonationStatus::Confirmed->value)
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(subtotal), 0) AS collected')
            ->first();

        // Section 47 : les remboursements se deduisent du collecte, ici comme
        // sur la page publique.
        $refunded = (int) DB::table('donation_refunds')
            ->where('organization_id', $organizationId)
            ->sum('amount');

        return [
            'campaigns' => (int) DB::table('campaigns')->where('organization_id', $organizationId)->count(),
            'donations' => (int) ($donations->count ?? 0),
            'collected' => max(0, (int) ($donations->collected ?? 0) - $refunded),
        ];
    }
}
