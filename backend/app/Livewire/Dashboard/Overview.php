<?php

namespace App\Livewire\Dashboard;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Settlement;
use App\Services\Statistics\TicketingStatistics;
use App\Support\Tenancy\OrganizationContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Vue generale du dashboard organisateur (section 56).
 *
 * A ce stade, seules les fondations existent : l'ecran affiche l'etat du compte
 * et de la verification d'identite. Les indicateurs de billetterie, de
 * collectes et de finances arriveront avec leurs modules respectifs — afficher
 * des chiffres a zero pour des fonctions inexistantes ne renseignerait sur rien.
 */
#[Layout('components.layouts.app')]
class Overview extends Component
{
    public function render()
    {
        $organization = app(OrganizationContext::class)->current();
        $user = auth()->user();

        $statistics = app(TicketingStatistics::class);

        return view('livewire.dashboard.overview', [
            'stats' => $statistics->forOrganization($organization),
            'byType' => $statistics->byTicketType($organization),
            /*
             * Les MONTANTS sont conditionnes par la permission financiere ;
             * les decomptes de billets ne le sont pas (section 10).
             *
             * Un CO_ORGANIZER gere la billetterie sans acces aux finances : il
             * doit savoir combien de billets sont partis pour organiser
             * l'entree, sans voir les recettes. Masquer les deux le rendrait
             * incapable de faire son travail.
             */
            'canSeeMoney' => $user->can(PermissionName::FinancesView->value),
            'organization' => $organization,
            'user' => $user,
            'roles' => $user->roles->pluck('name')
                ->map(fn (string $name) => RoleName::tryFrom($name)?->label() ?? $name)
                ->all(),
            'memberCount' => $organization->members()->active()->count(),
            // Section 21.2 : l'organisateur doit savoir quand son argent
            // arrive. C'est la question la plus posee au support d'une
            // billetterie, et la seule reponse acceptable est une date.
            // `settlable` et non `event` : un reversement porte desormais sur
            // un evenement OU une collecte (decision D-041).
            'settlements' => Settlement::query()
                ->with('settlable')
                ->latest()
                ->limit(5)
                ->get(),
            'upcomingPayout' => $this->upcomingPayout($organization),
        ]);
    }

    /**
     * Prochain reversement attendu, quand il n'a pas encore ete instruit.
     *
     * Calcule a partir du premier evenement termine non encore reverse : c'est
     * lui qui determine la date. Aucun montant n'est affiche ici — il ne sera
     * fige qu'a la creation du reversement, et annoncer un chiffre qui bougera
     * vaut moins que ne rien annoncer.
     */
    private function upcomingPayout(Organization $organization): ?Event
    {
        return Event::query()
            ->whereNotNull('ends_at')
            ->whereDoesntHave('settlement')
            ->where('ends_at', '<=', now())
            ->orderBy('ends_at')
            ->first();
    }
}
