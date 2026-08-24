<?php

namespace App\Livewire\Dashboard;

use App\Enums\RoleName;
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

        return view('livewire.dashboard.overview', [
            'organization' => $organization,
            'user' => $user,
            'roles' => $user->roles->pluck('name')
                ->map(fn (string $name) => RoleName::tryFrom($name)?->label() ?? $name)
                ->all(),
            'memberCount' => $organization->members()->active()->count(),
        ]);
    }
}
