<?php

namespace Database\Seeders;

use App\Actions\Access\AssignAgent;
use App\Actions\Access\ValidateTicket;
use App\Actions\Payments\ConfirmPayment;
use App\Enums\EventCategory;
use App\Enums\EventStatus;
use App\Models\EntryPoint;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Database\Seeders\Support\PosterPlaceholder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Une soiree deja controlee, pour voir le module fonctionner (sections 27, 30).
 *
 * Les deux autres evenements de demonstration se jouent dans deux mois : ils
 * servent la page publique et le parcours d'achat. Celui-ci a DEJA EU LIEU, et
 * c'est ce qui le rend utile — un ecran d'entrees se juge sur une soiree
 * terminee, pas sur des compteurs a zero.
 *
 * TOUT PASSE PAR LES ACTIONS REELLES : `ConfirmPayment` emet les billets,
 * `AssignAgent` cree les agents, `ValidateTicket` enregistre chaque controle
 * avec son verrou et son arbitrage. Ecrire les lignes directement en base
 * serait plus court, mais produirait des donnees que l'application elle-meme
 * n'aurait jamais pu creer — et masquerait le jour ou une regle change.
 *
 * La soiree contient volontairement ses accidents : un QR illisible admis a la
 * reference, un faux QR refuse, un billet presente deux fois, et un double scan
 * hors connexion arbitre a l'envers de l'ordre d'arrivee (section 31.1). Une
 * demonstration ou tout se passe bien ne montre rien.
 *
 * IRREVERSIBLE PAR NATURE : un scan ne se supprime pas (declencheur
 * PostgreSQL). Ce seeder ne se rejoue donc jamais sur un evenement existant.
 */
class DemoControlledEventSeeder extends Seeder
{
    private const SLUG = 'nuit-blanche-libreville';

    private const OWNER = 'awa@pass241.test';

    /** Agents de la soiree. Mot de passe `password`, comme les autres comptes locaux. */
    private const AGENTS = [
        ['Jean Moussavou', 'jean@pass241.test', 'Entree principale'],
        ['Sylvie Ovono', 'sylvie@pass241.test', 'Entree VIP'],
    ];

    public function run(): void
    {
        if (Event::query()->acrossOrganizations()->where('slug', self::SLUG)->exists()) {
            return;
        }

        /*
         * Sans cle de signature, aucun QR ne peut etre emis : le seeder
         * s'arrete plutot que d'echouer au milieu, et dit quoi faire. C'est le
         * cas d'une installation neuve ou `pass241:ticket-key` n'a pas encore
         * ete lancee.
         */
        if ((string) config('app.ticket_token_key') === '') {
            $this->command?->warn(
                'Soiree de demonstration ignoree : aucune cle de signature de billet. '
                .'Lancer `php artisan pass241:ticket-key`, puis rejouer le seeder.'
            );

            return;
        }

        $owner = User::query()->where('email', self::OWNER)->first();

        if (! $owner?->current_organization_id) {
            return;
        }

        app(OrganizationContext::class)->runFor($owner->currentOrganization, function () use ($owner) {
            $event = $this->createPastEvent($owner);
            $tickets = $this->sellTickets($event);
            [$doors, $agents] = $this->staffTheDoors($event, $owner);

            $this->playTheEvening($event, $tickets, $doors, $agents);
        });

        $this->command?->info(
            'Soiree deja controlee : /evenements — « Nuit blanche de Libreville ». '
            .'Agents : jean@pass241.test / sylvie@pass241.test (mot de passe : password)'
        );
    }

    /**
     * L'evenement, tel qu'il serait apres sa date.
     *
     * Statut `COMPLETED` et dates passees : c'est l'etat dans lequel un
     * organisateur consulte ses entrees, le lendemain matin.
     */
    private function createPastEvent(User $owner): Event
    {
        $tz = config('app.display_timezone');

        $event = new Event([
            'name' => 'Nuit blanche de Libreville',
            'description' => "Une soiree de demonstration, deja passee et deja controlee.\n\n"
                .'Elle sert a regarder le module de controle d\'acces avec de vraies '
                .'entrees : compteurs, journal, refus, et une admission accordee sans '
                .'lecture du QR Code.',
            'programme' => "20h00 Ouverture des portes\n21h00 Premiere partie\n02h00 Fin",
            'practical_info' => 'Deux entrees : principale et VIP.',
            'categories' => [EventCategory::Music->value],
            // 20h00 a Libreville, pas 20h00 UTC : les donnees de demo doivent
            // se lire comme les saisirait un organisateur.
            'starts_at' => now($tz)->subDays(9)->setTime(20, 0)->utc(),
            'ends_at' => now($tz)->subDays(8)->setTime(2, 0)->utc(),
            'max_capacity' => 400,
        ]);

        $event->slug = self::SLUG;
        $event->status = EventStatus::Completed;
        $event->published_at = now()->subDays(45);
        $event->created_by = $owner->getKey();
        $event->poster_path = PosterPlaceholder::for(self::SLUG, 'Nuit blanche');
        $event->save();

        $event->location()->create([
            'name' => 'Jardin botanique',
            'address' => 'Boulevard de l\'Independance',
            'city' => 'Libreville',
        ]);

        $event->ticketTypes()->createMany([
            [
                'name' => 'Standard',
                'description' => 'Acces general a la soiree.',
                'price' => 10_000,
                'quantity' => 200,
                'max_per_order' => 5,
                'position' => 0,
            ],
            [
                'name' => 'VIP',
                'description' => 'Acces general, espace reserve et boisson de bienvenue.',
                'price' => 50_000,
                'quantity' => 30,
                'max_per_order' => 4,
                'position' => 1,
            ],
        ]);

        return $event->refresh();
    }

    /**
     * Les billets vendus, emis par le chemin normal.
     *
     * `ConfirmPayment` fait le reste : verification du stock, emission, et
     * commission au taux fige. Aucune adresse email n'est renseignee — un
     * seeder n'a pas a envoyer vingt PDF a chaque installation, et l'achat sans
     * email est de toute facon le cas courant (section 15).
     *
     * @return Collection<int, Ticket>
     */
    private function sellTickets(Event $event): Collection
    {
        $types = TicketType::query()
            ->where('event_id', $event->getKey())
            ->get()
            ->keyBy('name');

        $buyers = [
            ['Marie', 'Nzeng', 'Standard', 2],
            ['Paul', 'Obame', 'Standard', 1],
            ['Sylvie', 'Mba', 'Standard', 3],
            ['Eric', 'Ondo', 'Standard', 1],
            ['Chantal', 'Bouassa', 'Standard', 2],
            ['Serge', 'Nguema', 'Standard', 1],
            ['Nadia', 'Koumba', 'Standard', 2],
            ['Yves', 'Bekale', 'Standard', 1],
            ['Grace', 'Ivanga', 'Standard', 2],
            ['Landry', 'Mintsa', 'VIP', 2],
            ['Estelle', 'Ndong', 'VIP', 1],
            ['Bruno', 'Ella', 'VIP', 2],
            ['Carine', 'Moussounda', 'VIP', 1],
            ['Herve', 'Ndoutoume', 'VIP', 1],
        ];

        foreach ($buyers as [$firstName, $lastName, $typeName, $quantity]) {
            $type = $types[$typeName];
            $subtotal = $type->price * $quantity;

            $order = Order::factory()->for($event)->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => null,
                'amount' => $subtotal,
            ]);

            $order->items()->create([
                'ticket_type_id' => $type->getKey(),
                'ticket_type_name' => $type->name,
                'quantity' => $quantity,
                'unit_price' => $type->price,
                'subtotal' => $subtotal,
            ]);

            app(ConfirmPayment::class)->handle($order->load('items'));
        }

        return Ticket::query()
            ->where('event_id', $event->getKey())
            ->with('orderItem')
            ->orderBy('id')
            ->get();
    }

    /**
     * Portes et agents.
     *
     * Passe par `AssignAgent`, comme l'ecran de gestion. Les comptes sont crees
     * avant pour qu'ils portent le mot de passe des autres comptes locaux :
     * l'action reutilise un compte existant au lieu d'en generer un (cas 2 de
     * son en-tete), et ne rend alors aucun mot de passe.
     *
     * @return array{0: array<string, EntryPoint>, 1: array<string, User>}
     */
    private function staffTheDoors(Event $event, User $owner): array
    {
        $doors = collect(['Entree principale', 'Entree VIP'])
            ->mapWithKeys(fn (string $name, int $index) => [$name => EntryPoint::create([
                'event_id' => $event->getKey(),
                'name' => $name,
                'description' => $index === 0 ? 'Cote boulevard' : 'Acces reserve',
                'position' => $index,
            ])])
            ->all();

        $agents = [];

        foreach (self::AGENTS as [$name, $email, $door]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => 'password', 'email_verified_at' => now()],
            );

            app(AssignAgent::class)->handle(
                event: $event,
                email: $email,
                name: $name,
                entryPointId: $doors[$door]->getKey(),
                actor: $owner,
            );

            $agents[$door] = $user;
        }

        return [$doors, $agents];
    }

    /**
     * La soiree elle-meme, controle par controle.
     *
     * L'ordre et les horaires comptent : l'ecran des entrees se lit a l'heure
     * du SCAN, pas a celle de la synchronisation, et c'est justement ce que le
     * dernier episode met en evidence.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @param  array<string, EntryPoint>  $doors
     * @param  array<string, User>  $agents
     */
    private function playTheEvening(Event $event, Collection $tickets, array $doors, array $agents): void
    {
        $validate = app(ValidateTicket::class);

        $standard = $tickets->filter(fn (Ticket $t) => $t->orderItem?->ticket_type_name === 'Standard')->values();
        $vip = $tickets->filter(fn (Ticket $t) => $t->orderItem?->ticket_type_name === 'VIP')->values();

        $principale = $doors['Entree principale'];
        $vipDoor = $doors['Entree VIP'];
        $jean = $agents['Entree principale'];
        $sylvie = $agents['Entree VIP'];

        $ouverture = $event->starts_at->copy()->subMinutes(40);
        $at = fn (int $minutes) => $ouverture->copy()->addMinutes($minutes);

        /*
         * 1. Le flux normal. Deux tiers des Standard passent la porte
         *    principale ; les autres ne sont jamais venus, et c'est ce qui rend
         *    le taux de presence credible.
         */
        foreach ($standard->take(10) as $index => $ticket) {
            $validate->handle(
                event: $event,
                token: $ticket->qrToken(),
                agent: $jean,
                entryPointId: $principale->getKey(),
                deviceId: 'demo-porte-principale',
                scannedAt: $at(2 + $index * 4),
            );
        }

        // 2. Les VIP, a leur porte.
        foreach ($vip->take(4) as $index => $ticket) {
            $validate->handle(
                event: $event,
                token: $ticket->qrToken(),
                agent: $sylvie,
                entryPointId: $vipDoor->getKey(),
                deviceId: 'demo-porte-vip',
                scannedAt: $at(6 + $index * 7),
            );
        }

        /*
         * 3. Un QR illisible — ecran casse, papier trempe. L'agent retrouve le
         *    billet a sa reference et laisse entrer (section 27.15). Le controle
         *    est reel, mais il est marque : c'est la seule facon de savoir,
         *    apres coup, comment cette personne est entree.
         */
        if ($vip->count() > 4) {
            $validate->handleByReference(
                event: $event,
                reference: $vip[4]->reference,
                agent: $sylvie,
                entryPointId: $vipDoor->getKey(),
                deviceId: 'demo-porte-vip',
                scannedAt: $at(38),
            );
        }

        // 4. Un faux QR. Journalise sans billet : c'est le refus le plus
        //    interessant, il signale une contrefacon.
        $validate->handle(
            event: $event,
            token: 'faux-qr-de-demonstration',
            agent: $jean,
            entryPointId: $principale->getKey(),
            deviceId: 'demo-porte-principale',
            scannedAt: $at(44),
        );

        // 5. Un billet deja passe, represente a l'autre porte.
        $validate->handle(
            event: $event,
            token: $standard[0]->qrToken(),
            agent: $sylvie,
            entryPointId: $vipDoor->getKey(),
            deviceId: 'demo-porte-vip',
            scannedAt: $at(52),
        );

        /*
         * 6. Le double scan hors connexion (section 31.1).
         *
         * La porte VIP etait hors reseau et remonte son lot en fin de soiree.
         * Son scan est PLUS ANCIEN que celui de la porte principale : c'est lui
         * qui l'emporte, et le controle deja accepte est declasse. L'horloge de
         * cet appareil retardait d'un quart d'heure — l'ecart est conserve, non
         * pour bloquer quoi que ce soit, mais pour pouvoir le demontrer.
         */
        $validate->handle(
            event: $event,
            token: $standard[3]->qrToken(),
            agent: $sylvie,
            entryPointId: $vipDoor->getKey(),
            deviceId: 'demo-porte-vip',
            scannedAt: $at(9),
            clockOffsetSeconds: -900,
        );
    }
}
