<?php

namespace App\Livewire\Dashboard\Campaigns;

use App\Actions\Donations\RefundDonation;
use App\Enums\PermissionName;
use App\Livewire\Concerns\RequiresPermission;
use App\Models\Campaign;
use App\Models\Donation;
use App\Services\Fundraising\CampaignProgress;
use App\Services\Fundraising\CampaignStatistics;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Les dons recus par une collecte (sections 44 a 48, 55).
 *
 * EN LECTURE SEULE, DEFINITIVEMENT. Cet ecran n'expose aucune methode
 * d'ecriture, et un test verifie qu'il n'en apparaitra pas : l'organisateur ne
 * peut ni supprimer un don, ni en modifier le montant, ni retirer un donateur
 * de l'historique (sections 44, 45). La base l'interdit deja par declencheur ;
 * l'ecran ne doit meme pas suggerer que ce serait possible.
 *
 * L'ANONYMAT VAUT AUSSI ICI (section 41). L'organisateur voit « Donateur
 * anonyme », exactement comme le public. C'est le point le moins evident de cet
 * ecran, et le plus important : la plateforme conserve l'identite reelle pour
 * le paiement, l'audit et le rapprochement financier, mais elle ne la montre a
 * personne — pas meme a celui qui recoit l'argent. Un anonymat que
 * l'organisateur peut lever n'est pas un anonymat.
 *
 * Aucun filtrage par organisation dans les requetes : il est applique par la
 * portee globale du trait BelongsToOrganization, et la liaison de route sur la
 * campagne a deja verifie l'appartenance (section 9).
 */
#[Layout('components.layouts.app')]
class Donations extends Component
{
    use RequiresPermission;
    use WithPagination;

    public Campaign $campaign;

    /** Don en cours de remboursement, ou null. */
    public ?int $refundingId = null;

    /** Part a rendre, en francs. Vide = la totalite du restant. */
    public ?int $refundAmount = null;

    public string $refundReason = '';

    protected function requiredPermission(): PermissionName
    {
        return PermissionName::CampaignsView;
    }

    public function mount(Campaign $campaign): void
    {
        $this->campaign = $campaign;
    }

    /*
     * -----------------------------------------------------------------------
     * Remboursement (section 47)
     *
     * Ce n'est PAS une correction, et c'est pourquoi il a sa place ici alors
     * que l'ecran refuse toute modification : rembourser n'efface rien. Le don
     * reste confirme et visible, une operation nouvelle s'ajoute a lui, et le
     * montant collecte se recalcule tout seul.
     * -----------------------------------------------------------------------
     */

    public function startRefund(int $donationId): void
    {
        $this->authorizeRefund();

        $this->refundingId = $donationId;
        $this->refundAmount = null;
        $this->refundReason = '';
        $this->resetErrorBag();
    }

    public function cancelRefund(): void
    {
        $this->refundingId = null;
        $this->refundAmount = null;
        $this->refundReason = '';
    }

    public function refund(RefundDonation $refunds): void
    {
        $this->authorizeRefund();

        $this->validate([
            'refundAmount' => ['nullable', 'integer', 'min:1'],
            'refundReason' => ['required', 'string', 'min:5', 'max:500'],
        ], attributes: [
            'refundAmount' => 'montant',
            'refundReason' => 'motif',
        ]);

        // Le don est relu depuis CETTE campagne : un identifiant venu du
        // navigateur ne doit pas atteindre le don d'une autre (regle 80.4).
        $donation = Donation::query()
            ->where('campaign_id', $this->campaign->getKey())
            ->whereKey($this->refundingId)
            ->firstOrFail();

        try {
            $refunds->handle(
                donation: $donation,
                amount: $this->refundAmount,
                reason: trim($this->refundReason),
                actor: auth()->user(),
            );
        } catch (RuntimeException $e) {
            $this->addError('refundAmount', $e->getMessage());

            return;
        }

        $this->cancelRefund();

        $this->dispatch('notify', message: 'Remboursement enregistre. Le montant collecte a ete recalcule.');
    }

    /**
     * Rembourser, c'est faire sortir de l'argent : la permission des finances
     * ne suffit pas a consulter, et consulter ne suffit pas a rembourser
     * (section 10).
     */
    private function authorizeRefund(): void
    {
        abort_unless(
            auth()->user()->can(PermissionName::FinancesRefund->value),
            403,
            'Vous n\'avez pas le droit de rembourser.'
        );
    }

    public function render(CampaignStatistics $statistics, CampaignProgress $progress)
    {
        $donations = Donation::query()
            ->where('campaign_id', $this->campaign->getKey())
            ->confirmed()
            // Section 47 : le montant affiche est le net apres remboursement,
            // ici comme sur la page publique.
            ->withSum('refunds', 'amount')
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.dashboard.campaigns.donations', [
            'donations' => $donations,
            'canRefund' => auth()->user()->can(PermissionName::FinancesRefund->value),
            'stats' => $statistics->forCampaign($this->campaign),
            'progress' => $progress->forCampaign($this->campaign),
            'evolution' => $statistics->evolution($this->campaign),
            /*
             * Le montant collecte est PUBLIC (section 37) : le masquer ici
             * n'aurait aucun sens, il suffit d'ouvrir la page de la collecte.
             * Le NET apres commission, lui, ne l'est pas — il ne s'affiche
             * qu'avec la permission des finances (section 10).
             */
            'canSeeNet' => auth()->user()->can(PermissionName::FinancesView->value),
        ]);
    }
}
