<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Ticketing\StockHoldService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Redis;

/*
 * Stock et reservation temporaire (section 17.1).
 *
 * Regle centrale a proteger : le hold ne concerne QUE le dernier billet.
 * Bloquer du stock abondant ferait afficher « complet » alors qu'il reste des
 * places, ce qui coute des ventes.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');
    actAsOrganization($this->organization);

    $this->event = Event::factory()->published()->create();
    $this->holds = app(StockHoldService::class);
});

afterEach(function () {
    // Redis n'est pas rembobine par RefreshDatabase : sans ce nettoyage, un
    // hold pose dans un test survivrait au suivant.
    Redis::flushdb();
});

it('compte le stock restant a partir des seules commandes payees', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['quantity' => 10]);

    $paid = Order::factory()->for($this->event)->paid()->create();
    $paid->items()->create([
        'ticket_type_id' => $ticketType->id,
        'ticket_type_name' => $ticketType->name,
        'quantity' => 3,
        'unit_price' => $ticketType->price,
        'subtotal' => $ticketType->price * 3,
    ]);

    // Une commande en attente n'a rien consomme : elle peut encore echouer.
    $pending = Order::factory()->for($this->event)->create();
    $pending->items()->create([
        'ticket_type_id' => $ticketType->id,
        'ticket_type_name' => $ticketType->name,
        'quantity' => 4,
        'unit_price' => $ticketType->price,
        'subtotal' => $ticketType->price * 4,
    ]);

    expect($ticketType->soldCount())->toBe(3)
        ->and($ticketType->remainingStock())->toBe(7);
});

it('ne pose aucun hold tant qu il reste plusieurs billets', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['quantity' => 50]);

    expect($this->holds->tryHold($ticketType, 'PS241-AAAA-111'))->toBeTrue()
        ->and($this->holds->isHeld($ticketType))->toBeFalse();
});

it('pose un hold sur le dernier billet', function () {
    $ticketType = TicketType::factory()->for($this->event)->lastOne()->create();

    expect($this->holds->tryHold($ticketType, 'PS241-AAAA-111'))->toBeTrue()
        ->and($this->holds->isHeld($ticketType))->toBeTrue()
        ->and($this->holds->holder($ticketType))->toBe('PS241-AAAA-111');
});

it('refuse un second acheteur sur le dernier billet', function () {
    $ticketType = TicketType::factory()->for($this->event)->lastOne()->create();

    expect($this->holds->tryHold($ticketType, 'PS241-AAAA-111'))->toBeTrue()
        ->and($this->holds->tryHold($ticketType, 'PS241-BBBB-222'))->toBeFalse();
});

it('retire le dernier billet de la disponibilite affichee pendant un hold', function () {
    $ticketType = TicketType::factory()->for($this->event)->lastOne()->create();

    expect($ticketType->availableStock())->toBe(1);

    $this->holds->tryHold($ticketType, 'PS241-AAAA-111');

    // Afficher « 1 restant » a un second acheteur qui ne pourra pas aller au
    // bout serait pire que d'afficher « complet ».
    expect($ticketType->availableStock())->toBe(0)
        ->and($ticketType->isSoldOut())->toBeTrue()
        ->and($ticketType->remainingStock())->toBe(1);
});

it('libere le hold et rend le billet a nouveau disponible', function () {
    $ticketType = TicketType::factory()->for($this->event)->lastOne()->create();

    $this->holds->tryHold($ticketType, 'PS241-AAAA-111');
    $this->holds->release($ticketType, 'PS241-AAAA-111');

    expect($this->holds->isHeld($ticketType))->toBeFalse()
        ->and($ticketType->availableStock())->toBe(1)
        ->and($this->holds->tryHold($ticketType, 'PS241-BBBB-222'))->toBeTrue();
});

it('ne laisse pas une commande liberer le hold d une autre', function () {
    $ticketType = TicketType::factory()->for($this->event)->lastOne()->create();

    $this->holds->tryHold($ticketType, 'PS241-AAAA-111');

    // Scenario reel : le hold de A expire, B pose le sien, puis le job
    // d'expiration de A s'execute avec du retard. Il ne doit pas liberer le
    // billet que B est en train de payer.
    $this->holds->release($ticketType, 'PS241-VIEUX-JOB');

    expect($this->holds->isHeld($ticketType))->toBeTrue()
        ->and($this->holds->holder($ticketType))->toBe('PS241-AAAA-111');
});

it('donne au hold exactement la duree du delai de paiement', function () {
    // Un hold plus court libererait le billet alors que l'acheteur saisit
    // encore son code ; plus long, il le garderait bloque apres l'echec.
    expect($this->holds->ttlSeconds())->toBe((int) config('payments.timeout_seconds'));
});

it('expire le hold tout seul', function () {
    $ticketType = TicketType::factory()->for($this->event)->lastOne()->create();

    $this->holds->tryHold($ticketType, 'PS241-AAAA-111');

    $ttl = Redis::ttl('pass241:hold:ticket_type:'.$ticketType->id);

    // Le TTL Redis est ce qui evite un processus de nettoyage : sans lui, du
    // stock resterait bloque si ce processus tombait.
    expect($ttl)->toBeGreaterThan(0)
        ->and($ttl)->toBeLessThanOrEqual($this->holds->ttlSeconds());
});

it('borne la quantite commandable par la limite et par le stock', function () {
    $ticketType = TicketType::factory()->for($this->event)->create([
        'quantity' => 3,
        'max_per_order' => 10,
    ]);

    // La limite par commande ne sert a rien si le stock est plus bas.
    expect($ticketType->maxPurchasableQuantity())->toBe(3);

    $ticketType->update(['quantity' => 100, 'max_per_order' => 4]);

    expect($ticketType->fresh()->maxPurchasableQuantity())->toBe(4);
});

it('ferme la vente hors de la periode annoncee', function () {
    $notYet = TicketType::factory()->for($this->event)->notYetOnSale()->create();
    $ended = TicketType::factory()->for($this->event)->salesEnded()->create();

    expect($notYet->isPurchasable())->toBeFalse()
        ->and($notYet->unavailabilityReason())->toStartWith('Vente a partir du')
        ->and($ended->isPurchasable())->toBeFalse()
        ->and($ended->unavailabilityReason())->toBe('La vente est terminee.');
});

it('ferme la vente quand l evenement n est pas publie', function () {
    $draft = Event::factory()->create();
    $ticketType = TicketType::factory()->for($draft)->create();

    expect($ticketType->isPurchasable())->toBeFalse()
        ->and($ticketType->unavailabilityReason())->toBe('La vente n\'est pas ouverte.');
});

it('accepte un billet gratuit', function () {
    $ticketType = TicketType::factory()->for($this->event)->free()->create();

    expect($ticketType->isFree())->toBeTrue()
        ->and($ticketType->price)->toBeXaf()
        ->and($ticketType->formattedPrice())->toBe('Gratuit')
        ->and($ticketType->isPurchasable())->toBeTrue();
});

it('refuse un prix negatif au niveau de la base', function () {
    expect(fn () => TicketType::factory()->for($this->event)->create(['price' => -1]))
        ->toThrow(QueryException::class);
});
