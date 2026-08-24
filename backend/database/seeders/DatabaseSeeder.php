<?php

namespace Database\Seeders;

use App\Actions\Organizations\CreateOrganization;
use App\Enums\OrganizationType;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent : les roles et permissions sont rejoues a chaque
        // deploiement sans dupliquer ni ecraser les attributions existantes.
        $this->call(RolesAndPermissionsSeeder::class);

        if (! app()->environment('local')) {
            return;
        }

        $this->seedLocalAccounts();
    }

    /**
     * Comptes de developpement. Deux organisations distinctes, pour que
     * l'isolation multi-tenant soit verifiable a la main dans le navigateur et
     * pas seulement dans la suite de tests (section 9).
     */
    private function seedLocalAccounts(): void
    {
        $createOrganization = app(CreateOrganization::class);

        $admin = User::firstOrCreate(
            ['email' => 'admin@pass241.test'],
            ['name' => 'Super Admin', 'password' => 'password', 'email_verified_at' => now()],
        );
        $admin->forceFill(['is_super_admin' => true])->save();

        foreach ([
            ['Awa Ndong', 'awa@pass241.test', 'Association des jeunes de Libreville', OrganizationType::Association],
            ['Paul Obame', 'paul@pass241.test', 'Studio Obame Events', OrganizationType::Company],
        ] as [$name, $email, $organizationName, $type]) {
            if (User::where('email', $email)->exists()) {
                continue;
            }

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'email_verified_at' => now(),
            ]);

            $createOrganization->handle(owner: $user, name: $organizationName, type: $type);
        }

        $this->command?->info('Comptes locaux : awa@pass241.test / paul@pass241.test / admin@pass241.test (mot de passe : password)');
    }
}
