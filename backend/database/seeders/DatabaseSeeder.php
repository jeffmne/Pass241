<?php

namespace Database\Seeders;

use App\Actions\Donations\CreateDonation;
use App\Actions\Organizations\CreateOrganization;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Polls\PublishPoll;
use App\Actions\Polls\SaveCandidate;
use App\Actions\Polls\SavePoll;
use App\Actions\Polls\SavePollCategory;
use App\Actions\Voting\CastFreeBallot;
use App\Data\PollDraft;
use App\Enums\CampaignStatus;
use App\Enums\EventCategory;
use App\Enums\EventStatus;
use App\Enums\OrganizationType;
use App\Enums\PaymentProvider;
use App\Enums\ResultsVisibility;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Event;
use App\Models\Poll;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Database\Seeders\Support\PosterPlaceholder;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        $this->seedDemoPoll();

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
                    // Un troisieme type « Derniere place » a ete retire de la
                    // demonstration : a cote de « Standard » et « VIP », qui
                    // sont des categories, il se lisait comme une troisieme
                    // categorie alors qu'il ne decrivait qu'un stock residuel.
                    // Un acheteur cherchait ce qu'elle donnait de plus.
                ]);
            });
        }

        $this->command?->info('Evenements de demonstration : /e/concert-afro-2026 et /e/gala-des-entrepreneurs');
    }

    /**
     * Une collecte publiee, pour que la page publique soit consultable des la
     * premiere installation (sections 34, 36).
     *
     * Le montant collecte n'est ecrit nulle part : il est la somme des dons
     * confirmes, eux-memes crees par les actions reelles (voir plus bas). Le
     * seeder n'invente aucun chiffre — en poser un reviendrait a stocker ce que
     * la regle 80.1 interdit de stocker.
     *
     * Rejouable : la campagne n'est creee qu'une fois, et les dons ne sont
     * ajoutes que s'il n'y en a aucun.
     */
    private function seedDemoCampaign(): void
    {
        $slug = 'operation-cartables-2026';

        $owner = User::where('email', 'awa@pass241.test')->first();

        if (! $owner?->current_organization_id) {
            return;
        }

        $existing = Campaign::query()->acrossOrganizations()->where('slug', $slug)->first();

        if ($existing) {
            app(OrganizationContext::class)->runFor(
                $owner->currentOrganization,
                fn () => $this->seedDemoDonations($existing)
            );

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

            $this->seedDemoDonations($campaign);
        });

        $this->command?->info('Collecte de demonstration : /collecte/'.$slug);
    }

    /**
     * Un scrutin publie, pour que la page de vote soit consultable des la
     * premiere installation (sections 86.4, 86.5).
     *
     * TOUT PASSE PAR LES ACTIONS REELLES — `SavePoll`, `SavePollCategory`,
     * `SaveCandidate`, `PublishPoll`, puis `CastFreeBallot` pour les voix. Meme
     * parti pris que la collecte : ecrire les lignes a la main produirait des
     * donnees qu'aucun chemin de l'application ne sait fabriquer, et masquerait
     * le jour ou ce chemin casse.
     *
     * AUCUN TOTAL DE VOTES N'EST ECRIT, et il n'y aurait nulle part ou l'ecrire :
     * le classement est la somme des bulletins confirmes, agregee en SQL. C'est
     * la regle du module, et le seeder n'y fait pas exception.
     *
     * Deux categories volontairement dissemblables : l'une montre ses resultats
     * en direct, l'autre les garde scelles jusqu'a la cloture. C'est ce qui
     * permet de verifier a la main qu'un classement scelle ne transite pas dans
     * la page.
     *
     * Rejouable : le scrutin n'est cree qu'une fois.
     */
    private function seedDemoPoll(): void
    {
        $slug = 'miss-estuaire-2026';

        $owner = User::where('email', 'awa@pass241.test')->first();

        if (! $owner?->current_organization_id) {
            return;
        }

        if (Poll::query()->acrossOrganizations()->where('slug', $slug)->exists()) {
            return;
        }

        app(OrganizationContext::class)->runFor($owner->currentOrganization, function () use ($owner, $slug) {
            $tz = config('app.display_timezone');

            $poll = app(SavePoll::class)->create(new PollDraft(
                name: 'Miss Estuaire 2026',
                startsAt: now($tz)->subDays(3)->utc(),
                endsAt: now($tz)->addMonth()->setTime(23, 59)->utc(),
                description: "Concours de demonstration, cree par le seeder local.\n\n"
                    .'Il sert a verifier la page publique de vote, le plafond par votant, '
                    .'et la difference entre un classement ouvert et un classement scelle.',
                resultsVisibility: ResultsVisibility::AfterClose,
            ), $owner);

            // Le slug de fabrique suit le nom ; on le fige sur la valeur
            // attendue pour que le lien annonce en console soit stable.
            $poll->forceFill(['slug' => $slug])->save();

            // Le modele exige une image pour publier : un scrutin de demo sans
            // image serait un etat que l'application refuserait de produire.
            $poll->forceFill([
                'poster_path' => PosterPlaceholder::for($slug, 'Miss Estuaire 2026'),
            ])->save();

            $categories = app(SavePollCategory::class);
            $candidates = app(SaveCandidate::class);

            $miss = $categories->create($poll, [
                'name' => 'Miss Estuaire',
                'description' => 'Le titre principal. Un seul vote par personne.',
                'is_paid' => false,
                'unit_price' => 0,
                'max_votes_per_voter' => 1,
                'results_visibility' => ResultsVisibility::Live,
                'is_active' => true,
                'position' => 0,
            ], $owner);

            $public = $categories->create($poll, [
                'name' => 'Prix du public',
                'description' => 'Classement scelle jusqu\'a la cloture.',
                'is_paid' => false,
                'unit_price' => 0,
                'max_votes_per_voter' => 3,
                'results_visibility' => ResultsVisibility::AfterClose,
                'is_active' => true,
                'position' => 1,
            ], $owner);

            $names = ['Awa Ndong', 'Sylvie Mba', 'Grace Ondo', 'Laure Bekale'];

            foreach ([$miss, $public] as $category) {
                foreach ($names as $index => $name) {
                    $candidates->create($category, [
                        'name' => $name,
                        'number' => (string) ($index + 1),
                        'biography' => 'Candidate de demonstration.',
                        'is_active' => true,
                        'position' => $index,
                    ], $owner);
                }
            }

            app(PublishPoll::class)->publish($poll->refresh(), $owner);

            $this->seedDemoBallots($poll->refresh());
        });

        $this->command?->info('Scrutin de demonstration : /vote/'.$slug);
    }

    /**
     * Quelques voix, pour que le classement ne soit pas vide.
     *
     * Chaque votant est une empreinte differente — un numero different — parce
     * que le plafond par personne est reel : reutiliser le meme numero ferait
     * refuser les bulletins suivants, et c'est exactement ce qu'on veut qu'il
     * fasse.
     */
    private function seedDemoBallots(Poll $poll): void
    {
        $cast = app(CastFreeBallot::class);

        foreach ($poll->categories()->with('candidates')->get() as $category) {
            $candidates = $category->candidates->values();

            // Un nombre de voix decroissant, pour que le classement ait un ordre
            // lisible plutot qu'une egalite generale.
            foreach ($candidates as $rank => $candidate) {
                $voices = max(1, 7 - ($rank * 2));

                for ($i = 0; $i < $voices; $i++) {
                    $request = Request::create('/vote/'.$poll->slug, 'POST', server: [
                        'REMOTE_ADDR' => '41.158.'.$rank.'.'.$i,
                        'HTTP_USER_AGENT' => 'Seeder',
                    ]);

                    $cast->handle(
                        $candidate,
                        1,
                        sprintf('07%d%02d%02d%02d', $rank + 1, $i, $rank, $i),
                        $request,
                    );
                }
            }
        }
    }

    /**
     * Quelques dons de demonstration, etales dans le temps.
     *
     * TOUT PASSE PAR LES ACTIONS REELLES, comme pour les billets : le don est
     * cree par `CreateDonation` puis confirme par `ConfirmPayment`. Ecrire les
     * lignes a la main produirait des donnees qu'aucun chemin de l'application
     * ne sait fabriquer — et masquerait le jour ou ce chemin casse.
     *
     * Le temps est deplace autour de chaque don : `confirmed_at` est fige par
     * un declencheur des la confirmation (sections 44 a 46), il n'y a donc
     * aucun moyen de l'antidater apres coup. C'est exactement la protection
     * qu'on veut, et elle s'applique aussi au seeder.
     */
    private function seedDemoDonations(Campaign $campaign): void
    {
        if (Donation::query()->acrossOrganizations()->where('campaign_id', $campaign->getKey())->exists()) {
            return;
        }

        $donations = [
            ['Marie Nzeng', 25_000, false, 9, '074000011'],
            ['Paul Obame', 10_000, false, 7, '074000012'],
            [null, 50_000, true, 5, '074000013'],
            ['Yves Bekale', 5_000, false, 3, '074000014'],
            ['Sandrine Moussavou', 15_000, false, 1, '074000015'],
            [null, 2_000, true, 0, '074000016'],
        ];

        foreach ($donations as [$name, $amount, $anonymous, $daysAgo, $phone]) {
            Carbon::setTestNow(now()->subDays($daysAgo)->setTime(14, 30));

            $donation = app(CreateDonation::class)->handle(
                campaign: $campaign,
                amount: $amount,
                provider: PaymentProvider::Airtel,
                payerPhone: $phone,
                donorName: $name,
                isAnonymous: $anonymous,
            );

            app(ConfirmPayment::class)->handle($donation, 'DEMO-'.$donation->reference);
        }

        Carbon::setTestNow();
    }
}
