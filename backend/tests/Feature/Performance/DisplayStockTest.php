<?php

use App\Actions\Orders\CreateOrder;
use App\Enums\PaymentProvider;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('charge les stocks de vingt tarifs en un seul agregat puis rend sans requete', function () {
    Queue::fake();
    [, $organization] = organizerWithOrganization('Stock test');
    $event = forOrganization($organization, function () {
        $event = Event::factory()->published()->create();
        TicketType::factory()->count(20)->for($event)->create(['quantity' => 10]);

        return $event;
    });
    $type = $event->publicTicketTypes()->first();
    app(CreateOrder::class)->handle($event, [$type->id => 2], 'Awa', 'Ndong', null, PaymentProvider::Airtel, '074000000');

    DB::enableQueryLog();
    DB::flushQueryLog();
    $types = $event->publicTicketTypes();
    $queries = count(DB::getQueryLog());

    foreach ($types as $item) {
        $item->isPurchasable();
        $item->unavailabilityReason();
        $item->maxPurchasableQuantity();
        $item->availableStock();
    }

    expect($queries)->toBe(2)
        ->and(count(DB::getQueryLog()))->toBe($queries)
        ->and($types->firstWhere('id', $type->id)->availableStock())->toBe(8);
    DB::disableQueryLog();

    // Une collection de presentation ancienne ne sert jamais a reserver.
    $this->travel(6)->minutes();
    expect($event->publicTicketTypes()->firstWhere('id', $type->id)->availableStock())->toBe(10);
});
