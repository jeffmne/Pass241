<?php

use App\Actions\Access\ValidateTicket;
use App\Actions\Orders\ConfirmPayment;
use App\Enums\AuditAction;
use App\Enums\ScanResult;
use App\Enums\TicketStatus;
use App\Models\AccessScan;
use App\Models\AuditLog;
use App\Models\EntryPoint;
use App\Models\Event;
use App\Models\EventAgent;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * Tableau de bord, historique et recherche manuelle (sections 27.14, 27.15).
 *
 * Ce qui est verifie ici, dans l'ordre d'importance : qu'un agent ne lit que
 * les controles qui le concernent, qu'une entree accordee sans QR laisse une
 * trace qui la distingue d'un scan, et que la recherche manuelle ne devient
 * jamais une liste de participants telechargeable.
 */

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->ticketType, $this->entryPoint] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create([
            'name' => 'Concert Afro 2026',
            'starts_at' => now()->addHours(3),
            'ends_at' => now()->addHours(9),
        ]);

        return [
            $event,
            TicketType::factory()->for($event)->create(['name' => 'VIP', 'price' => 10_000, 'quantity' => 100]),
            EntryPoint::create(['event_id' => $event->id, 'name' => 'Entree principale']),
        ];
    });

    $this->agent = User::factory()->create(['email' => 'agent@pass241.test']);

    forOrganization($this->organization, fn () => EventAgent::create([
        'event_id' => $this->event->id,
        'user_id' => $this->agent->id,
        'entry_point_id' => $this->entryPoint->id,
    ]));

    $this->issue = function (int $quantity = 1, string $holder = 'Jean Dupont') {
        return forOrganization($this->organization, function () use ($quantity, $holder) {
            $subtotal = 10_000 * $quantity;
            [$prenom, $nom] = array_pad(explode(' ', $holder, 2), 2, '');

            // Le nom porte sur le billet vient de l'acheteur (`buyerName`) :
            // c'est lui que l'agent lit a l'ecran et cherche a la porte.
            $order = Order::factory()->for($this->event)->create([
                'subtotal' => $subtotal,
                'amount' => $subtotal,
                'first_name' => $prenom,
                'last_name' => $nom,
            ]);

            $order->items()->create([
                'ticket_type_id' => $this->ticketType->id, 'ticket_type_name' => 'VIP',
                'quantity' => $quantity, 'unit_price' => 10_000, 'subtotal' => $subtotal,
            ]);

            app(ConfirmPayment::class)->handle($order->load('items'));

            return Ticket::query()->acrossOrganizations()->where('order_id', $order->id)->get();
        });
    };

    $this->token = function (?User $user = null, string $device = 'device-A') {
        return $this->postJson('/api/agent/connexion', [
            'email' => ($user ?? $this->agent)->email,
            'password' => 'password',
            'device_id' => $device,
        ])->json('token');
    };

    $this->url = fn (string $path) => '/api/agent/evenements/'.$this->event->id.$path;

    app(OrganizationContext::class)->forget();
});

/*
 * ---------------------------------------------------------------------------
 * Tableau de bord (section 27.14)
 * ---------------------------------------------------------------------------
 */

it('compte les controles de toute l entree, et ceux de l agent a part', function () {
    $tickets = ($this->issue)(3);
    $autre = User::factory()->create();

    forOrganization($this->organization, fn () => EventAgent::create([
        'event_id' => $this->event->id, 'user_id' => $autre->id,
    ]));

    // Une porte, puis l'autre.
    app(ValidateTicket::class)->handle(
        event: $this->event, token: $tickets[0]->qrToken(), agent: $this->agent, deviceId: 'device-A'
    );
    app(ValidateTicket::class)->handle(
        event: $this->event, token: $tickets[1]->qrToken(), agent: $autre, deviceId: 'device-B'
    );

    $response = $this->withToken(($this->token)())->getJson(($this->url)('/tableau-de-bord'))->assertOk();

    /*
     * C'est toute la raison d'etre de cette route : le telephone connait ses
     * deux scans, jamais ceux de la porte d'a cote. « Combien de personnes sont
     * entrees » n'a de reponse que cote serveur.
     */
    expect($response->json('counters.scanned'))->toBe(2)
        ->and($response->json('counters.valid'))->toBe(2)
        ->and($response->json('mine.scanned'))->toBe(1)
        ->and($response->json('tickets.admitted'))->toBe(2)
        ->and($response->json('tickets.expected'))->toBe(3)
        ->and($response->json('tickets.remaining'))->toBe(1);
});

it('classe les refus selon ce que l agent a vu a l ecran', function () {
    $tickets = ($this->issue)(2);

    app(ValidateTicket::class)->handle(
        event: $this->event, token: $tickets[0]->qrToken(), agent: $this->agent, deviceId: 'device-A'
    );
    // Le meme billet, une seconde fois.
    app(ValidateTicket::class)->handle(
        event: $this->event, token: $tickets[0]->qrToken(), agent: $this->agent,
        deviceId: 'device-A', scannedAt: now()->addMinute()
    );
    app(ValidateTicket::class)->handle(
        event: $this->event, token: 'faux-jeton', agent: $this->agent, deviceId: 'device-A'
    );

    $response = $this->withToken(($this->token)())->getJson(($this->url)('/tableau-de-bord'))->assertOk();

    expect($response->json('counters.valid'))->toBe(1)
        ->and($response->json('counters.already_used'))->toBe(1)
        ->and($response->json('counters.invalid'))->toBe(1)
        // Le detail par code reste disponible : « rembourse » et « QR non
        // reconnu » sont deux refus, mais pas la meme soiree.
        ->and($response->json('counters.by_result.'.ScanResult::Invalid->value))->toBe(1);
});

it('ne compte pas dans les billets attendus ceux qui ne se presenteront pas', function () {
    $tickets = ($this->issue)(2);

    forOrganization($this->organization, fn () => $tickets[0]->forceFill([
        'status' => TicketStatus::Refunded->value,
    ])->save());

    $response = $this->withToken(($this->token)())->getJson(($this->url)('/tableau-de-bord'))->assertOk();

    /*
     * Un billet rembourse ne franchira pas la porte. L'inclure ferait afficher
     * a l'agent un compte de billets restants qui ne tombera jamais a zero.
     */
    expect($response->json('tickets.expected'))->toBe(1);
});

it('refuse le tableau de bord d un evenement non affecte', function () {
    $autre = forOrganization($this->organization, fn () => Event::factory()->published()->create());

    // 404 et non 403 : un evenement non affecte n'existe pas pour cet agent.
    $this->withToken(($this->token)())
        ->getJson('/api/agent/evenements/'.$autre->id.'/tableau-de-bord')
        ->assertNotFound();
});

/*
 * ---------------------------------------------------------------------------
 * Historique (section 27.15)
 * ---------------------------------------------------------------------------
 */

it('rend les controles de l agent du plus recent au plus ancien', function () {
    $tickets = ($this->issue)(2);

    // Le point d'entree est celui de l'affectation, comme le pose la remontee
    // de scans : c'est lui que l'historique doit rendre.
    app(ValidateTicket::class)->handle(
        event: $this->event, token: $tickets[0]->qrToken(), agent: $this->agent,
        entryPointId: $this->entryPoint->id, deviceId: 'device-A', scannedAt: now()->subMinutes(5)
    );
    app(ValidateTicket::class)->handle(
        event: $this->event, token: $tickets[1]->qrToken(), agent: $this->agent,
        entryPointId: $this->entryPoint->id, deviceId: 'device-A', scannedAt: now()
    );

    $response = $this->withToken(($this->token)())->getJson(($this->url)('/controles'))->assertOk();

    expect($response->json('scans'))->toHaveCount(2)
        ->and($response->json('scans.0.reference'))->toBe($tickets[1]->reference)
        ->and($response->json('scans.0.label'))->toBe('Billet valide')
        ->and($response->json('scans.0.holder_name'))->not->toBeNull()
        ->and($response->json('scans.0.entry_point'))->toBe('Entree principale');
});

it('ne montre pas a un agent les controles des autres portes', function () {
    $tickets = ($this->issue)(2);
    $autre = User::factory()->create();

    forOrganization($this->organization, fn () => EventAgent::create([
        'event_id' => $this->event->id, 'user_id' => $autre->id,
    ]));

    app(ValidateTicket::class)->handle(
        event: $this->event, token: $tickets[0]->qrToken(), agent: $autre, deviceId: 'device-B'
    );

    $response = $this->withToken(($this->token)())->getJson(($this->url)('/controles'))->assertOk();

    /*
     * Un telephone d'agent se perd a l'entree d'un concert. Il n'a pas a
     * contenir la liste nominative de tous ceux qui sont entres ailleurs
     * (sections 27.5, 27.16) — les compteurs globaux, eux, ne nomment personne.
     */
    expect($response->json('scans'))->toBeEmpty();
});

it('corrige un verdict retourne par l arbitrage du double scan', function () {
    $ticket = ($this->issue)(1)->first();
    $autre = User::factory()->create();

    forOrganization($this->organization, fn () => EventAgent::create([
        'event_id' => $this->event->id, 'user_id' => $autre->id,
    ]));

    // L'agent scanne et lit « entree autorisee » sur son telephone.
    app(ValidateTicket::class)->handle(
        event: $this->event, token: $ticket->qrToken(), agent: $this->agent,
        deviceId: 'device-A', scannedAt: now()
    );

    // Une autre porte synchronise plus tard un scan PLUS ANCIEN : c'est lui qui
    // gagne (section 31.1).
    app(ValidateTicket::class)->handle(
        event: $this->event, token: $ticket->qrToken(), agent: $autre,
        deviceId: 'device-B', scannedAt: now()->subMinutes(3)
    );

    $response = $this->withToken(($this->token)())->getJson(($this->url)('/controles'))->assertOk();

    /*
     * L'application ne peut pas deviner ce retournement : seul le serveur
     * arbitre. Relire l'historique est ce qui remet le telephone d'accord avec
     * la verite, plutot que de laisser l'agent sur un « entree autorisee »
     * perime.
     */
    expect($response->json('scans.0.result'))->toBe(ScanResult::ConflictLost->value)
        ->and($response->json('scans.0.accepted'))->toBeFalse()
        ->and($response->json('scans.0.conflict_with'))->not->toBeNull();
});

it('pagine l historique par curseur decroissant', function () {
    $tickets = ($this->issue)(3);

    foreach ($tickets as $index => $ticket) {
        app(ValidateTicket::class)->handle(
            event: $this->event, token: $ticket->qrToken(), agent: $this->agent,
            deviceId: 'device-A', scannedAt: now()->addSeconds($index)
        );
    }

    $token = ($this->token)();

    $first = $this->withToken($token)->getJson(($this->url)('/controles?limit=2'))->assertOk();

    expect($first->json('scans'))->toHaveCount(2)
        ->and($first->json('has_more'))->toBeTrue();

    $second = $this->withToken($token)
        ->getJson(($this->url)('/controles?limit=2&before='.$first->json('next_before')))
        ->assertOk();

    expect($second->json('scans'))->toHaveCount(1)
        ->and($second->json('has_more'))->toBeFalse();
});

/*
 * ---------------------------------------------------------------------------
 * Recherche manuelle (section 27.15)
 * ---------------------------------------------------------------------------
 */

it('retrouve un billet par sa reference et par le nom du porteur', function () {
    $ticket = ($this->issue)(1, 'Marie Nzeng')->first();
    $token = ($this->token)();

    $parReference = $this->withToken($token)
        ->getJson(($this->url)('/recherche?q='.substr($ticket->reference, 0, 6)))
        ->assertOk();

    $parNom = $this->withToken($token)
        ->getJson(($this->url)('/recherche?q=nzeng'))
        ->assertOk();

    expect($parReference->json('tickets.0.reference'))->toBe($ticket->reference)
        ->and($parReference->json('tickets.0.allows_entry'))->toBeTrue()
        // Recherche insensible a la casse : personne ne saisit les majuscules
        // d'un nom a bout de bras dans une file.
        ->and($parNom->json('tickets.0.reference'))->toBe($ticket->reference);
});

it('ne descend jamais de jeton dans la recherche', function () {
    $ticket = ($this->issue)(1)->first();

    $response = $this->withToken(($this->token)())
        ->getJson(($this->url)('/recherche?q='.$ticket->reference))
        ->assertOk();

    // Meme regle que la synchronisation : avec un jeton, un telephone vole
    // permet de fabriquer des QR ; sans lui, il ne permet que de reconnaitre.
    expect($response->getContent())->not->toContain($ticket->qrToken());
});

it('refuse une recherche trop courte et neutralise les jokers', function () {
    ($this->issue)(2);
    $token = ($this->token)();

    $this->withToken($token)->getJson(($this->url)('/recherche?q=ab'))
        ->assertStatus(422)
        ->assertJsonPath('errors.q.0', 'Saisissez au moins trois caracteres.');

    /*
     * Sans neutralisation, « %%% » renverrait la liste entiere des
     * participants — exactement ce que la longueur minimale cherche a empecher.
     */
    $joker = $this->withToken($token)->getJson(($this->url)('/recherche?q=%25%25%25'))->assertOk();

    expect($joker->json('tickets'))->toBeEmpty();
});

it('ne cherche que dans l evenement de l agent', function () {
    $ticket = ($this->issue)(1, 'Marie Nzeng')->first();

    $autreEvenement = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create(['name' => 'Gala']);
        TicketType::factory()->for($event)->create(['name' => 'Standard', 'price' => 5_000, 'quantity' => 10]);

        return $event;
    });

    $autreAgent = User::factory()->create(['email' => 'gala@pass241.test']);

    forOrganization($this->organization, fn () => EventAgent::create([
        'event_id' => $autreEvenement->id, 'user_id' => $autreAgent->id,
    ]));

    $response = $this->withToken(($this->token)($autreAgent, 'device-C'))
        ->getJson('/api/agent/evenements/'.$autreEvenement->id.'/recherche?q=nzeng')
        ->assertOk();

    // L'isolation ne s'arrete pas a l'organisation : un agent affecte a un
    // autre evenement de la MEME organisation ne voit pas ces participants.
    expect($response->json('tickets'))->toBeEmpty()
        ->and($ticket->holder_name)->toBe('Marie Nzeng');
});

/*
 * ---------------------------------------------------------------------------
 * Admission manuelle (section 27.15)
 * ---------------------------------------------------------------------------
 */

it('laisse entrer a la reference quand le QR ne se lit pas', function () {
    $ticket = ($this->issue)(1)->first();

    $response = $this->withToken(($this->token)())->postJson(($this->url)('/scans'), [
        'device_id' => 'device-A',
        'scans' => [['reference' => $ticket->reference, 'scanned_at' => now()->toIso8601String()]],
    ])->assertOk();

    expect($response->json('results.0.accepted'))->toBeTrue()
        ->and($response->json('results.0.manual'))->toBeTrue()
        ->and($ticket->fresh()->status)->toBe(TicketStatus::Used);

    $scan = AccessScan::query()->acrossOrganizations()->firstOrFail();

    /*
     * La trace est ce qui compte : une admission manuelle et un scan signe se
     * ressembleraient parfaitement sans elle, alors que l'un prouve une
     * signature et l'autre une reference lue a l'oeil.
     */
    expect($scan->manual)->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::TicketAdmittedManually->value)->exists())
        ->toBeTrue();
});

it('n accorde a la reference rien de plus qu au QR', function () {
    $tickets = ($this->issue)(2);
    $token = ($this->token)();

    forOrganization($this->organization, fn () => $tickets[0]->forceFill([
        'status' => TicketStatus::Refunded->value,
    ])->save());

    // Deja passe par le QR.
    app(ValidateTicket::class)->handle(
        event: $this->event, token: $tickets[1]->qrToken(), agent: $this->agent, deviceId: 'device-B'
    );

    $response = $this->withToken($token)->postJson(($this->url)('/scans'), [
        'device_id' => 'device-A',
        'scans' => [
            ['reference' => $tickets[0]->reference, 'scanned_at' => now()->toIso8601String()],
            ['reference' => $tickets[1]->reference, 'scanned_at' => now()->toIso8601String()],
            ['reference' => 'T-ZZZZ-ZZZZ', 'scanned_at' => now()->toIso8601String()],
        ],
    ])->assertOk();

    /*
     * La reference evite d'avoir a lire un QR abime, elle n'ouvre aucune porte
     * de plus. C'est le point ou une admission manuelle deviendrait une faille
     * si elle empruntait un chemin de decision distinct.
     */
    expect($response->json('results.0.result'))->toBe(ScanResult::Refunded->value)
        ->and($response->json('results.1.result'))->toBe(ScanResult::AlreadyUsed->value)
        ->and($response->json('results.2.result'))->toBe(ScanResult::Invalid->value);
});

it('refuse a la reference un billet emis pour un autre evenement', function () {
    $autreEvenement = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create(['name' => 'Gala']);
        TicketType::factory()->for($event)->create(['name' => 'Standard', 'price' => 5_000, 'quantity' => 10]);

        return $event;
    });

    $etranger = forOrganization($this->organization, function () use ($autreEvenement) {
        $type = TicketType::query()->where('event_id', $autreEvenement->id)->firstOrFail();

        $order = Order::factory()->for($autreEvenement)->create(['subtotal' => 5_000, 'amount' => 5_000]);
        $order->items()->create([
            'ticket_type_id' => $type->id, 'ticket_type_name' => 'Standard',
            'quantity' => 1, 'unit_price' => 5_000, 'subtotal' => 5_000,
        ]);

        app(ConfirmPayment::class)->handle($order->load('items'));

        return Ticket::query()->acrossOrganizations()->where('order_id', $order->id)->firstOrFail();
    });

    $response = $this->withToken(($this->token)())->postJson(($this->url)('/scans'), [
        'device_id' => 'device-A',
        'scans' => [['reference' => $etranger->reference, 'scanned_at' => now()->toIso8601String()]],
    ])->assertOk();

    // Une reference connue de la plateforme n'ouvre pas la porte d'a cote.
    expect($response->json('results.0.result'))->toBe(ScanResult::WrongEvent->value)
        ->and($etranger->fresh()->status)->toBe(TicketStatus::Active);
});

it('retient le jeton plutot que la reference quand les deux sont envoyes', function () {
    $ticket = ($this->issue)(1)->first();

    $this->withToken(($this->token)())->postJson(($this->url)('/scans'), [
        'device_id' => 'device-A',
        'scans' => [[
            'token' => $ticket->qrToken(),
            'reference' => $ticket->reference,
            'scanned_at' => now()->toIso8601String(),
        ]],
    ])->assertOk();

    $scan = AccessScan::query()->acrossOrganizations()->firstOrFail();

    /*
     * Sinon une application pourrait degrader volontairement la verification en
     * joignant une reference a un QR parfaitement lisible, et l'entree
     * apparaitrait comme manuelle sans l'etre.
     */
    expect($scan->manual)->toBeFalse();
});

it('refuse un controle sans jeton ni reference', function () {
    $this->withToken(($this->token)())->postJson(($this->url)('/scans'), [
        'device_id' => 'device-A',
        'scans' => [['scanned_at' => now()->toIso8601String()]],
    ])->assertStatus(422);
});

it('reste idempotente sur une admission manuelle retransmise', function () {
    $ticket = ($this->issue)(1)->first();
    $token = ($this->token)();
    $at = now()->toIso8601String();

    $body = [
        'device_id' => 'device-A',
        'scans' => [['reference' => $ticket->reference, 'scanned_at' => $at]],
    ];

    $premier = $this->withToken($token)->postJson(($this->url)('/scans'), $body)->assertOk();
    $second = $this->withToken($token)->postJson(($this->url)('/scans'), $body)->assertOk();

    // Une file locale retransmise apres une coupure ne doit pas creer un second
    // passage, pas plus par reference que par jeton.
    expect($second->json('results.0.scan_id'))->toBe($premier->json('results.0.scan_id'))
        ->and(AccessScan::query()->acrossOrganizations()->count())->toBe(1);
});
