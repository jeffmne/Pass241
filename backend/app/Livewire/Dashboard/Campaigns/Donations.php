<?php

namespace App\Livewire\Dashboard\Campaigns;

use App\Enums\PermissionName;
use App\Models\Campaign;
use App\Models\Donation;
use App\Services\Fundraising\CampaignProgress;
use App\Services\Fundraising\CampaignStatistics;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

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
    use WithPagination;

    public Campaign $campaign;

    public function mount(Campaign $campaign): void
    {
        abort_unless(auth()->user()->can(PermissionName::CampaignsView->value), 403);

        $this->campaign = $campaign;
    }

    public function render(CampaignStatistics $statistics, CampaignProgress $progress)
    {
        $donations = Donation::query()
            ->where('campaign_id', $this->campaign->getKey())
            ->confirmed()
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.dashboard.campaigns.donations', [
            'donations' => $donations,
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
