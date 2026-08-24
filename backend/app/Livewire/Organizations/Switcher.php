<?php

namespace App\Livewire\Organizations;

use App\Enums\AuditAction;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\OrganizationContext;
use Livewire\Component;

/**
 * Changement d'organisation active.
 *
 * Le composant ne fait jamais confiance a l'identifiant recu : il verifie
 * l'appartenance en base avant de basculer (section 9). Un identifiant
 * d'organisation forge dans la requete Livewire ne donne donc aucun acces.
 */
class Switcher extends Component
{
    public bool $compact = false;

    public function switch(int $organizationId, AuditLogger $audit, OrganizationContext $context): void
    {
        $user = auth()->user();

        $organization = Organization::find($organizationId);

        if (! $organization || ! $user->switchOrganization($organization)) {
            // Ni message detaille ni distinction entre « n'existe pas » et
            // « pas membre » : les deux cas sont indiscernables de l'exterieur.
            abort(403, 'Organisation inaccessible.');
        }

        $context->set($organization);
        setPermissionsTeamId($organization->getKey());

        $audit->log(
            AuditAction::OrganizationContextSwitched,
            $organization,
            organizationId: $organization->getKey(),
        );

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.organizations.switcher', [
            'user' => $user,
            'current' => app(OrganizationContext::class)->current(),
            'organizations' => $user->organizations()->orderBy('name')->get(),
        ]);
    }
}
