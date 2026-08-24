<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles et permissions de la plateforme (section 10).
 *
 * Les roles sont crees une seule fois, sans organisation (`organization_id`
 * null), donc partages par toutes les organisations. C'est l'ATTRIBUTION qui
 * porte l'organisation : dans `model_has_roles`, chaque ligne est rattachee a
 * une organisation precise. Un utilisateur peut ainsi etre ORGANIZER de son
 * association et ACCESS_AGENT chez quelqu'un d'autre, sans qu'aucun droit ne
 * traverse la frontiere (section 9).
 *
 * Idempotent : peut etre rejoue a chaque deploiement sans dupliquer ni
 * ecraser les attributions existantes.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Les roles crees ici sont globaux : le contexte d'organisation doit
        // etre neutralise, sinon Spatie les rattacherait a l'organisation
        // courante.
        setPermissionsTeamId(null);

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (RoleName::cases() as $roleName) {
            $role = Role::findOrCreate($roleName->value, 'web');
            $role->syncPermissions($roleName->defaultPermissions());
        }

        App::make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
