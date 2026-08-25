<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Ticketing\StockLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Stock et reservation (section 17.1, decision D-017).
 *
 *     disponible = quantite − vendus − reserves
 *
 * Regle centrale a proteger : toute commande reserve ses billets, et la
 * reservation expire d'elle-meme avec la commande. Aucun service annexe,
 * aucune tache de fond ne doit etre necessaire pour que ce calcul soit juste.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');
    actAsOrganization($this->organization);

    $this->event = Event::factory()->published()->create();
    $this->ledger = app(StockLedger::class);

    /** Cree une commande portant $quantity billets du type donne. */
    $this->orderFor = function (TicketType $ticketType, int $quantity, string $state = 'pending'): Order {
        $order = Order::factory()->for($this->event)->when(
            $state === 'paid',
            fn ($factory) => $factory->paid(),
            fn ($factory) => $factory->state([
                'status' => OrderStatus::Pending,
                'expires_at' => $state === 'expired' ? now()->subMinute() : now()->addMinutes(5),
            ]),
        )->create();

        $order->items()->create([
            'ticket_type_id' => $ticketType->id,
            'ticket_type_name' => $ticketType->name,
            'quantity' => $quantity,
            'unit_price' => $ticketType->price,
            'subtotal' => $ticketType->price * $quantity,
        ]);

        return $order;
    };
});

it('compte les ventes a partir des seules commandes payees', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['quantity' => 10]);

    ($this->orderFor)($ticketType, 3, 'paid');

    expect($this->ledger->sold($ticketType))->toBe(3);
});

it('retire du stock disponible les billets d une commande en cours de paiement', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['quantity' => 10]);

    ($this->orderFor)($ticketType, 4);

    // C'est le coeur de la decision D-017 : une commande en attente immobilise
    // ses billets, sinon plusieurs acheteurs avanceraient sur le meme stock.
    expect($this->ledger->reserved($ticketType))->toBe(4)
        ->and($this->ledger->available($ticketType))->toBe(6)
        ->and($ticketType->availableStock())->toBe(6);
});

it('additionne ventes et reservations', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['quantity' => 10]);

    ($this->orderFor)($ticketType, 3, 'paid');
    ($this->orderFor)($ticketType, 2);

    expect($this->ledger->available($ticketType))->toBe(5);
});

it('remet en vente les billets d une reservation echue, sans aucun traitement', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['quantity' => 5]);

    ($this->orderFor)($ticketType, 5, 'expired');

    // Aucun job n'a tourne, Redis n'existe plus dans ce calcul : la condition
    // sur la date d'expiration suffit. C'est ce qui rend le stock juste meme
    // si la file d'attente s'arrete.
    expect($this->ledger->reserved($ticketType))->toBe(0)
        ->and($this->ledger->available($ticketType))->toBe(5);
});

it('affiche complet quand les reservations couvrent tout le stock', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['quantity' => 2]);

    ($this->orderFor)($ticketType, 2);

    expect($ticketType->isSoldOut())->toBeTrue()
        ->and($ticketType->isPurchasable())->toBeFalse()
        ->and($ticketType->unavailabilityReason())->toBe('Complet');
});

it('ne compte pas les commandes echouees ou annulees', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['quantity' => 10]);

    $failed = ($this->orderFor)($ticketType, 3);
    $failed->forceFill(['status' => OrderStatus::Failed, 'failed_at' => now()])->save();

    expect($this->ledger->available($ticketType))->toBe(10);
});

it('compte encore les billets d une commande partiellement remboursee', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['quantity' => 10]);

    $order = ($this->orderFor)($ticketType, 4, 'paid');
    $order->forceFill(['status' => OrderStatus::PartiallyRefunded])->save();

    // Le reste de la commande est bien paye : ces billets sont consommes.
    expect($this->ledger->sold($ticketType))->toBe(4);
});

/*
 * ---------------------------------------------------------------------------
 * Verification atomique — le second niveau de protection (section 17.1)
 * ---------------------------------------------------------------------------
 */

it('laisse passer une confirmation quand le stock suffit', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['quantity' => 5]);
    $order = ($this->orderFor)($ticketType, 2);

    DB::transaction(fn () => $this->ledger->commit($ticketType, 2, $order->id));
})->throwsNoExceptions();

it('n oppose pas a une commande la reservation qu elle a elle-meme posee', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['quantity' => 2]);
    $order = ($this->orderFor)($ticketType, 2);

    // Sans l'exclusion, la commande se verrait refuser le stock qu'elle
    // immobilise elle-meme.
    DB::transaction(fn () => $this->ledger->commit($ticketType, 2, $order->id));
})->throwsNoExceptions();

it('refuse une confirmation quand le stock a ete pris entre-temps', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['quantity' => 2]);

    // Commande arrivee a echeance : sa reservation est tombee.
    $late = ($this->orderFor)($ticketType, 2, 'expired');

    // Deux places parties pendant ce temps.
    ($this->orderFor)($ticketType, 2, 'paid');

    // Section 17.1 : un paiement confirme apres l'expiration est accepte si le
    // stock le permet encore — ici il ne le permet plus.
    expect(fn () => DB::transaction(fn () => $this->ledger->commit($ticketType, 2, $late->id)))
        ->toThrow(RuntimeException::class);
});

it('accepte un paiement tardif quand le stock est encore la', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['quantity' => 5]);
    $late = ($this->orderFor)($ticketType, 1, 'expired');

    DB::transaction(fn () => $this->ledger->commit($ticketType, 1, $late->id));
})->throwsNoExceptions();

/*
 * Note : la garde « commit() hors transaction » de StockLedger n'est pas
 * testee ici, et ne peut pas l'etre. RefreshDatabase enveloppe chaque test
 * dans une transaction, donc la condition qu'elle detecte ne peut jamais se
 * produire dans cette suite. La garde reste en place pour la production.
 */

/*
 * ---------------------------------------------------------------------------
 * Disponibilite a la vente
 * ---------------------------------------------------------------------------
 */

it('borne la quantite commandable par la limite et par le stock', function () {
    $ticketType = TicketType::factory()->for($this->event)->create([
        'quantity' => 3,
        'max_per_order' => 10,
    ]);

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
