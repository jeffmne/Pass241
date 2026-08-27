<?php

use App\Actions\Payments\ConfirmPayment;
use App\Enums\ScanResult;
use App\Enums\TicketStatus;
use App\Models\AccessScan;
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
use Laravel\Sanctum\PersonalAccessToken;

/*
 * API de l'application agent (sections 27.3, 27.5, 27.11).
 *
 * Ce qui est verifie ici, dans l'ordre d'importance : qu'un agent n'atteint
 * que ses evenements, qu'aucun secret ne descend sur le telephone, et que la
 * remontee des scans supporte les conditions reelles d'une entree — reseau qui
 * coupe, lot retransmis, echec partiel.
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

    $this->issue = function (int $quantity = 1) {
        return forOrganization($this->organization, function () use ($quantity) {
            $subtotal = 10_000 * $quantity;

            $order = Order::factory()->for($this->event)->create([
                'subtotal' => $subtotal, 'amount' => $subtotal,
            ]);

            $order->items()->create([
                'ticket_type_id' => $this->ticketType->id, 'ticket_type_name' => 'VIP',
                'quantity' => $quantity, 'unit_price' => 10_000, 'subtotal' => $subtotal,
            ]);

            app(ConfirmPayment::class)->handle($order->load('items'));

            return Ticket::query()->acrossOrganizations()->where('order_id', $order->id)->get();
        });
    };

    $this->login = function (?User $user = null, string $device = 'device-A') {
        return $this->postJson('/api/agent/connexion', [
            'email' => ($user ?? $this->agent)->email,
            'password' => 'password',
            'device_id' => $device,
        ]);
    };

    app(OrganizationContext::class)->forget();
});

/*
 * ---------------------------------------------------------------------------
 * Connexion
 * ---------------------------------------------------------------------------
 */

it('delivre un jeton a un agent affecte', function () {
    ($this->login)()
        ->assertOk()
        ->assertJsonStructure(['token', 'agent' => ['id', 'name', 'email'], 'server_time']);
});

it('renvoie l heure du serveur pour detecter une horloge deregle', function () {
    $response = ($this->login)()->assertOk();

    // La regle du double scan repose sur l'horodatage de l'appareil
    // (section 31.1) : l'application compare et avertit si l'ecart est
    // anormal.
    expect($response->json('server_time'))->not->toBeNull();
});

it('refuse un compte affecte a aucun evenement', function () {
    $stranger = User::factory()->create(['email' => 'personne@pass241.test']);

    /*
     * C'est ce qui separe l'application agent du reste : un compte
     * d'organisateur sans affectation ne s'y connecte pas.
     */
    ($this->login)($stranger)
        ->assertStatus(403)
        ->assertJsonPath('errors.email.0', 'Ce compte n\'est affecte a aucun evenement.');
});

it('ne revele pas si une adresse existe', function () {
    $inconnu = $this->postJson('/api/agent/connexion', [
        'email' => 'inexistant@pass241.test', 'password' => 'password', 'device_id' => 'd',
    ]);

    $mauvaisMotDePasse = $this->postJson('/api/agent/connexion', [
        'email' => $this->agent->email, 'password' => 'faux', 'device_id' => 'd',
    ]);

    // Deux messages identiques : les distinguer revelerait quelles adresses
    // sont enregistrees.
    expect($inconnu->json('errors.email.0'))->toBe($mauvaisMotDePasse->json('errors.email.0'));
});

it('remplace le jeton precedent du meme appareil', function () {
    ($this->login)();
    ($this->login)();

    // Une reconnexion ne doit pas empiler des acces oublies sur un appareil.
    expect($this->agent->fresh()->tokens()->count())->toBe(1);
});

it('limite les tentatives par couple compte et appareil', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/agent/connexion', [
            'email' => $this->agent->email, 'password' => 'faux', 'device_id' => 'device-A',
        ]);
    }

    $this->postJson('/api/agent/connexion', [
        'email' => $this->agent->email, 'password' => 'faux', 'device_id' => 'device-A',
    ])->assertStatus(429);

    /*
     * L'autre appareil du meme agent reste utilisable : limiter par email seul
     * permettrait de fermer la porte d'un agent legitime en echouant
     * volontairement depuis un autre telephone.
     */
    ($this->login)(device: 'device-B')->assertOk();
});

/*
 * ---------------------------------------------------------------------------
 * Perimetre de l'agent
 * ---------------------------------------------------------------------------
 */

it('ne liste que les evenements auxquels l agent est affecte', function () {
    forOrganization($this->organization, fn () => Event::factory()->published()->create([
        'name' => 'Evenement sans agent',
    ]));

    $token = ($this->login)()->json('token');

    $response = $this->withToken($token)->getJson('/api/agent/evenements')->assertOk();

    expect($response->json('events'))->toHaveCount(1)
        ->and($response->json('events.0.name'))->toBe('Concert Afro 2026')
        // Le point d'entree vient de l'affectation, pas d'un choix dans
        // l'application (section 30).
        ->and($response->json('events.0.entry_point.name'))->toBe('Entree principale');
});

it('refuse la synchronisation d un evenement non affecte', function () {
    $other = forOrganization($this->organization, fn () => Event::factory()->published()->create());

    $token = ($this->login)()->json('token');

    /*
     * 404 et non 403 : du point de vue de l'agent, un evenement qui ne lui est
     * pas affecte n'existe pas. Repondre 403 lui apprendrait quels evenements
     * existent ailleurs sur la plateforme.
     */
    $this->withToken($token)
        ->getJson('/api/agent/evenements/'.$other->id.'/billets')
        ->assertNotFound();
});

it('refuse tout acces sans jeton', function () {
    $this->getJson('/api/agent/evenements')->assertUnauthorized();
});

/*
 * ---------------------------------------------------------------------------
 * Donnees de controle
 * ---------------------------------------------------------------------------
 */

it('envoie des empreintes et jamais les jetons', function () {
    $tickets = ($this->issue)(2);
    $token = ($this->login)()->json('token');

    $response = $this->withToken($token)
        ->getJson('/api/agent/evenements/'.$this->event->id.'/billets')
        ->assertOk();

    $payload = $response->getContent();

    /*
     * Un telephone d'agent se perd et se vole a l'entree d'un concert. Avec
     * les jetons, le voleur repart avec de quoi fabriquer tous les QR de
     * l'evenement ; avec les empreintes, il ne peut que reconnaitre un billet.
     */
    foreach ($tickets as $ticket) {
        expect($payload)->toContain($ticket->tokenHash())
            ->and($payload)->not->toContain($ticket->qrToken());
    }
});

it('pagine par curseur et signale la suite', function () {
    ($this->issue)(3);
    $token = ($this->login)()->json('token');

    $response = $this->withToken($token)
        ->getJson('/api/agent/evenements/'.$this->event->id.'/billets')
        ->assertOk();

    expect($response->json('tickets'))->toHaveCount(3)
        ->and($response->json('has_more'))->toBeFalse()
        ->and($response->json('next_cursor'))->toBeNull()
        ->and($response->json('synced_at'))->not->toBeNull();
});

it('ne renvoie que ce qui a change depuis la derniere synchronisation', function () {
    ($this->issue)(1);
    $token = ($this->login)()->json('token');

    /*
     * Une porte qui se reconnecte au milieu de la soiree ne retelecharge pas
     * tout : sans cela, chaque reconnexion couterait plusieurs mega-octets sur
     * une connexion mobile (section 72).
     */
    $response = $this->withToken($token)->getJson(
        '/api/agent/evenements/'.$this->event->id.'/billets?'
        .http_build_query(['since' => now()->addMinute()->toDateTimeString()])
    )->assertOk();

    expect($response->json('tickets'))->toBeEmpty();
});

/*
 * ---------------------------------------------------------------------------
 * Remontee des scans
 * ---------------------------------------------------------------------------
 */

it('accepte un lot de scans et rend le verdict de chacun', function () {
    $tickets = ($this->issue)(2);
    $token = ($this->login)()->json('token');

    $response = $this->withToken($token)->postJson(
        '/api/agent/evenements/'.$this->event->id.'/scans',
        [
            'device_id' => 'device-A',
            'scans' => $tickets->map(fn (Ticket $t) => [
                'token' => $t->qrToken(),
                'scanned_at' => now()->toIso8601String(),
            ])->all(),
        ]
    )->assertOk();

    expect($response->json('results'))->toHaveCount(2)
        ->and($response->json('results.0.accepted'))->toBeTrue()
        // Les deux lignes que l'agent lit sur son telephone (section 28).
        ->and($response->json('results.0.label'))->toBe('Billet valide')
        ->and($response->json('results.0.instruction'))->toBe('Entree autorisee')
        ->and($response->json('results.0.holder_name'))->not->toBeNull();
});

it('rend le meme verdict quand un lot est retransmis', function () {
    $tickets = ($this->issue)(1);
    $token = ($this->login)()->json('token');
    $at = now()->toIso8601String();

    $body = [
        'device_id' => 'device-A',
        'scans' => [['token' => $tickets->first()->qrToken(), 'scanned_at' => $at]],
    ];

    $first = $this->withToken($token)->postJson('/api/agent/evenements/'.$this->event->id.'/scans', $body);
    $again = $this->withToken($token)->postJson('/api/agent/evenements/'.$this->event->id.'/scans', $body);

    /*
     * Une synchronisation interrompue est retransmise en entier : sans
     * idempotence, chaque coupure reseau creerait des doublons dans
     * l'historique de controle.
     */
    expect($again->json('results.0.scan_id'))->toBe($first->json('results.0.scan_id'))
        ->and(AccessScan::query()->acrossOrganizations()->count())->toBe(1);
});

it('enregistre le point d entree de l affectation, jamais celui de la requete', function () {
    $tickets = ($this->issue)(1);
    $token = ($this->login)()->json('token');

    $this->withToken($token)->postJson(
        '/api/agent/evenements/'.$this->event->id.'/scans',
        [
            'device_id' => 'device-A',
            // L'application tente de se declarer a une autre porte.
            'entry_point_id' => 999999,
            'scans' => [['token' => $tickets->first()->qrToken(), 'scanned_at' => now()->toIso8601String()]],
        ]
    )->assertOk();

    $scan = AccessScan::query()->acrossOrganizations()->firstOrFail();

    // C'est l'organisateur qui repartit ses agents (section 30) : une
    // application ne doit pas pouvoir se placer ailleurs.
    expect($scan->entry_point_id)->toBe($this->entryPoint->id);
});

it('n interrompt pas un lot a cause d un scan invalide', function () {
    $tickets = ($this->issue)(1);
    $token = ($this->login)()->json('token');

    $response = $this->withToken($token)->postJson(
        '/api/agent/evenements/'.$this->event->id.'/scans',
        [
            'device_id' => 'device-A',
            'scans' => [
                ['token' => 'jeton-invente', 'scanned_at' => now()->toIso8601String()],
                ['token' => $tickets->first()->qrToken(), 'scanned_at' => now()->toIso8601String()],
            ],
        ]
    )->assertOk();

    // Le billet valide passe malgre le faux qui le precede : sinon
    // l'application retransmettrait indefiniment un lot dont un seul element
    // pose probleme.
    expect($response->json('results.0.result'))->toBe(ScanResult::Invalid->value)
        ->and($response->json('results.1.accepted'))->toBeTrue()
        ->and($tickets->first()->fresh()->status)->toBe(TicketStatus::Used);
});

it('refuse un lot trop volumineux', function () {
    $token = ($this->login)()->json('token');

    $this->withToken($token)->postJson(
        '/api/agent/evenements/'.$this->event->id.'/scans',
        [
            'device_id' => 'device-A',
            'scans' => array_fill(0, 201, ['token' => 'x', 'scanned_at' => now()->toIso8601String()]),
        ]
    )->assertStatus(422)->assertJsonPath('errors.scans.0', 'Lot trop volumineux : envoyez-le en plusieurs fois.');
});

it('conserve l ecart d horloge transmis par l application', function () {
    $tickets = ($this->issue)(1);
    $token = ($this->login)()->json('token');

    $this->withToken($token)->postJson(
        '/api/agent/evenements/'.$this->event->id.'/scans',
        [
            'device_id' => 'device-A',
            'clock_offset_seconds' => -320,
            'scans' => [['token' => $tickets->first()->qrToken(), 'scanned_at' => now()->toIso8601String()]],
        ]
    )->assertOk();

    $scan = AccessScan::query()->acrossOrganizations()->firstOrFail();

    expect($scan->clock_offset_seconds)->toBe(-320)
        ->and($scan->hasSuspiciousClock())->toBeTrue();
});

/*
 * ---------------------------------------------------------------------------
 * Deconnexion et revocation
 * ---------------------------------------------------------------------------
 */

it('revoque le jeton a la deconnexion', function () {
    $token = ($this->login)()->json('token');

    expect(PersonalAccessToken::count())->toBe(1);

    $this->withToken($token)->postJson('/api/agent/deconnexion')->assertNoContent();

    /*
     * On verifie la SUPPRESSION du jeton, pas le refus de la requete suivante :
     * dans un test, le garde Sanctum reste resolu d'une requete a l'autre au
     * sein du meme processus. En production chaque requete demarre une
     * application neuve et le jeton absent produit bien un 401.
     */
    expect(PersonalAccessToken::count())->toBe(0);
});

it('refuse une session web sur l API agent', function () {
    /*
     * Sanctum, faute de jeton, retombe sur la session web — et une session
     * obtient un jeton transitoire qui repond « oui » a TOUTES les abilites,
     * `agent` comprise. Sans garde, l'API agent serait joignable depuis un
     * navigateur connecte au dashboard.
     */
    $this->actingAs($this->agent);

    $this->getJson('/api/agent/evenements')->assertUnauthorized();
});

it('ferme l acces des que l affectation est revoquee', function () {
    $token = ($this->login)()->json('token');

    forOrganization($this->organization, fn () => EventAgent::query()
        ->where('user_id', $this->agent->id)->firstOrFail()->revoke());

    /*
     * Le jeton reste valide mais ne donne plus rien : l'organisateur retire un
     * agent en une action, sans avoir a courir apres son telephone.
     */
    $response = $this->withToken($token)->getJson('/api/agent/evenements')->assertOk();

    expect($response->json('events'))->toBeEmpty();
});
