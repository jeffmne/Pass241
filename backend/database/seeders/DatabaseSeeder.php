<?php

namespace Database\Seeders;

use App\Actions\Organizations\CreateOrganization;
use App\Enums\CampaignStatus;
use App\Enums\EventCategory;
use App\Enums\EventStatus;
use App\Enums\OrganizationType;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Database\Seeders\Support\PosterPlaceholder;
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

        $this->seedDemoEvents();
        $this->seedDemoCampaign();

        /*
         * Une soiree deja passee, avec ses entrees. Separee des deux
         * evenements ci-dessus, qui se jouent dans deux mois : un ecran
         * d'entrees ne se juge pas sur des compteurs a zero, et un evenement
         * a venir ne peut pas avoir de controles credibles.
         */
        $this->call(DemoControlledEventSeeder::class);

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
                $event->poster_path = PosterPlaceholder::for($slug, $name);
                $event->save();

                $event->location()->create([
                    'name' => $venue,
                    'address' => 'Boulevard Triomphal',
                    'city' => 'Libreville',
                ]);

                // Trois tarifs, dont un presque epuise : c'est le cas ou le
                // hold de la section 17.1 se declenche, et le seul moyen de le
                // voir a l'oeuvre sans monter un scenario a la main.
                $event->ticketTypes()->createMany([
                    [
                        'name' => 'Standard',
                        'description' => 'Acces general a l\'evenement.',
                        'price' => 10_000,
                        'quantity' => 300,
                        'max_per_order' => 5,
                        'position' => 0,
                    ],
                    [
                        'name' => 'VIP',
                        'description' => 'Acces general, espace reserve et boisson de bienvenue.',
                        'price' => 50_000,
                        'quantity' => 40,
                        'max_per_order' => 4,
                        'position' => 1,
                    ],
                    [
                        'name' => 'Derniere place',
                        'description' => 'Il n\'en reste qu\'une.',
                        'price' => 15_000,
                        'quantity' => 1,
                        'max_per_order' => 1,
                        'position' => 2,
                    ],
                ]);
            });
        }

        $this->command?->info('Evenements de demonstration : /e/concert-afro-2026 et /e/gala-des-entrepreneurs');
    }

    /**
     * Une collecte publiee, pour que la page publique soit consultable des la
     * premiere installation (sections 34, 36).
     *
     * Elle affiche un montant collecte a zero, et c'est exact : aucun don ne
     * peut encore exister. Le seeder n'invente aucun chiffre — ecrire un
     * montant collecte de demonstration reviendrait a stocker ce que la regle
     * 80.1 interdit de stocker.
     */
    private function seedDemoCampaign(): void
    {
        $slug = 'operation-cartables-2026';

        if (Campaign::query()->acrossOrganizations()->where('slug', $slug)->exists()) {
            return;
        }

        $owner = User::where('email', 'awa@pass241.test')->first();

        if (! $owner?->current_organization_id) {
            return;
        }

        app(OrganizationContext::class)->runFor($owner->currentOrganization, function () use ($owner, $slug) {
            $campaign = new Campaign([
                'name' => 'Operation cartables 2026',
                'description' => "Cent cartables complets pour la rentree des eleves de l'ecole "
                    ."publique de Nzeng-Ayong.\n\n"
                    .'Collecte de demonstration, creee par le seeder local. Elle sert a verifier '
                    .'la page publique, la barre de progression et le QR de partage.',
                'goal_amount' => 1_000_000,
                'beneficiary_label' => 'Ecole publique de Nzeng-Ayong',
                'ends_at' => now(config('app.display_timezone'))->addMonths(2)->setTime(23, 59)->utc(),
            ]);

            $campaign->slug = $slug;
            $campaign->status = CampaignStatus::Published;
            $campaign->published_at = now();
            $campaign->created_by = $owner->getKey();
            // Le modele exige une image pour publier : une collecte de demo
            // sans image serait un etat que l'application refuserait de
            // produire elle-meme.
            $campaign->poster_path = PosterPlaceholder::for($slug, 'Operation cartables 2026');
            $campaign->save();
        });

        $this->command?->info('Collecte de demonstration : /collecte/'.$slug);
    }
}
