<?php

namespace App\Livewire\Organizations;

use App\Actions\Organizations\CreateOrganization;
use App\Enums\OrganizationType;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Creation d'une organisation supplementaire, ou d'une premiere organisation
 * pour un utilisateur qui n'en a plus aucune (appartenance revoquee).
 *
 * Volontairement hors du middleware de contexte : un utilisateur sans
 * organisation doit pouvoir atteindre cet ecran, sinon il se retrouve enferme
 * dehors sans recours.
 */
#[Layout('components.layouts.guest')]
class Create extends Component
{
    #[Validate('required|string|min:2|max:120')]
    public string $name = '';

    // Pas d'attribut #[Validate] : il l'emporterait sur la regle `in:` definie
    // dans rules(), et une valeur inventee passerait la validation.
    public string $type = 'ASSOCIATION';

    protected function rules(): array
    {
        return [
            'type' => ['required', 'in:'.implode(',', array_column(OrganizationType::cases(), 'value'))],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['name' => 'nom de l\'organisation', 'type' => 'type d\'organisation'];
    }

    public function save(CreateOrganization $createOrganization): void
    {
        $this->validate();

        $createOrganization->handle(
            owner: auth()->user(),
            name: $this->name,
            type: OrganizationType::from($this->type),
        );

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.organizations.create', [
            'organizationTypes' => OrganizationType::cases(),
            'hasOrganizations' => auth()->user()->organizations()->exists(),
        ]);
    }
}
