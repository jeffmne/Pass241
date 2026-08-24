<?php

namespace App\Actions\Organizations;

use App\Enums\AuditAction;
use App\Enums\KycStatus;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cree une organisation et y installe son proprietaire.
 *
 * Chemin unique : l'inscription d'un nouvel organisateur comme la creation
 * d'une organisation supplementaire passent toutes deux par ici. Dupliquer
 * cette sequence ailleurs ferait courir le risque qu'une organisation existe
 * un jour sans appartenance ou sans role — donc inaccessible a son proprietaire
 * (section 9).
 */
class CreateOrganization
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(
        User $owner,
        string $name,
        OrganizationType $type,
        ?string $contactEmail = null,
        ?string $contactPhone = null,
        bool $makeCurrent = true,
    ): Organization {
        return DB::transaction(function () use ($owner, $name, $type, $contactEmail, $contactPhone, $makeCurrent) {
            $organization = new Organization([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'type' => $type,
                'contact_email' => $contactEmail ?? $owner->email,
                'contact_phone' => $contactPhone ?? $owner->phone,
            ]);

            $organization->owner_id = $owner->getKey();

            // Section 10.1 : aucune verification exigee pour demarrer. Seul le
            // reversement attendra que ce statut passe a VERIFIED.
            $organization->kyc_status = KycStatus::Pending;
            $organization->save();

            OrganizationMember::create([
                'organization_id' => $organization->getKey(),
                'user_id' => $owner->getKey(),
                'joined_at' => now(),
            ]);

            // Le role est attribue DANS cette organisation : la ligne de
            // model_has_roles porte l'organisation, pas le role lui-meme.
            setPermissionsTeamId($organization->getKey());
            $owner->assignRole(RoleName::Organizer->value);

            if ($makeCurrent) {
                $owner->forceFill(['current_organization_id' => $organization->getKey()])->save();
                $this->context->set($organization);
            }

            $this->audit->log(
                AuditAction::OrganizationCreated,
                $organization,
                ['type' => $type->value],
                actor: $owner,
                organizationId: $organization->getKey(),
            );

            return $organization;
        });
    }

    /**
     * Le slug est l'identifiant public de l'organisation. Deux organisateurs
     * choisissent souvent le meme nom (« BDE », « Association des jeunes ») :
     * l'unicite doit etre garantie ici, pas laissee a la contrainte de base
     * qui produirait une erreur illisible en pleine inscription.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organisation';
        $slug = $base;
        $suffix = 2;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
