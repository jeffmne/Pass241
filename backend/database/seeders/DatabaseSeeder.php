<?php

namespace Database\Seeders;

use App\Actions\Organizations\CreateOrganization;
use App\Enums\EventCategory;
use App\Enums\EventStatus;
use App\Enums\OrganizationType;
use App\Models\Event;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

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

        $this->seedDemoEvents();

        $this->command?->info('Comptes locaux : awa@pass241.test / paul@pass241.test / admin@pass241.test (mot de passe : password)');
    }

    /**
     * Un evenement publie par organisation, pour que la page publique soit
     * consultable des la premiere installation — et qu'on puisse verifier a la
     * main qu'un organisateur ne voit pas les evenements de l'autre.
     */
    private function seedDemoEvents(): void
    {
        $context = app(OrganizationContext::class);

        $demos = [
            ['awa@pass241.test', 'Concert Afro 2026', 'concert-afro-2026', 'Institut francais du Gabon',
                [EventCategory::Music->value, EventCategory::Culture->value]],
            ['paul@pass241.test', 'Gala des entrepreneurs', 'gala-des-entrepreneurs', 'Hotel Radisson Blu',
                [EventCategory::Gala->value]],
        ];

        foreach ($demos as [$email, $name, $slug, $venue, $categories]) {
            if (Event::query()->acrossOrganizations()->where('slug', $slug)->exists()) {
                continue;
            }

            $owner = User::where('email', $email)->first();

            if (! $owner?->current_organization_id) {
                continue;
            }

            $context->runFor($owner->currentOrganization, function () use ($owner, $name, $slug, $venue, $categories) {
                $event = new Event([
                    'name' => $name,
                    'description' => "Un evenement de demonstration, cree par le seeder local.\n\n"
                        ."Il sert a verifier la page publique, l'apercu de partage et l'isolation "
                        .'entre organisations sans avoir a saisir un formulaire.',
                    'programme' => "19h00 Ouverture des portes\n20h00 Debut\n23h00 Fin",
                    'practical_info' => 'Parking disponible. Presentez votre billet a l\'entree.',
                    'categories' => $categories,
                    // 20h00 a Libreville, pas 20h00 UTC : les donnees de demo
                    // doivent se lire comme les saisirait un organisateur.
                    'starts_at' => now(config('app.display_timezone'))->addMonths(2)->setTime(20, 0)->utc(),
                    'ends_at' => now(config('app.display_timezone'))->addMonths(2)->setTime(23, 30)->utc(),
                    'max_capacity' => 500,
                ]);

                $event->slug = $slug;
                $event->status = EventStatus::Published;
                $event->published_at = now();
                $event->created_by = $owner->getKey();
                // Le modele exige une affiche pour publier : un evenement de
                // demo sans affiche serait un etat que l'application elle-meme
                // refuserait de produire.
                $event->poster_path = $this->generatePosterPlaceholder($slug, $name);
                $event->save();

                $event->location()->create([
                    'name' => $venue,
                    'address' => 'Boulevard Triomphal',
                    'city' => 'Libreville',
                ]);
            });
        }

        $this->command?->info('Evenements de demonstration : /e/concert-afro-2026 et /e/gala-des-entrepreneurs');
    }

    /**
     * Affiche de remplacement, generee localement.
     *
     * Un aplat azurin avec le nom de l'evenement : ce n'est pas du design, mais
     * cela evite deux choses — dependre d'une image telechargee depuis
     * l'exterieur, et publier un evenement sans affiche alors que le modele
     * l'interdit.
     */
    private function generatePosterPlaceholder(string $slug, string $name): string
    {
        $path = "events/demo/{$slug}.png";

        if (Storage::disk('public')->exists($path)) {
            return $path;
        }

        $width = 800;
        $height = 1000;

        $image = imagecreatetruecolor($width, $height);

        // Degrade vertical dans la famille azurine (section 70.1).
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / $height;
            $color = imagecolorallocate(
                $image,
                (int) (47 + (20 - 47) * $ratio),
                (int) (143 + (84 - 143) * $ratio),
                (int) (230 + (155 - 230) * $ratio),
            );
            imagefilledrectangle($image, 0, $y, $width, $y, $color);
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $line = 0;
        foreach (explode("\n", wordwrap($name, 16, "\n", true)) as $text) {
            imagestring($image, 5, 60, 420 + ($line * 40), $text, $white);
            $line++;
        }

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put($path, $binary);

        return $path;
    }
}
