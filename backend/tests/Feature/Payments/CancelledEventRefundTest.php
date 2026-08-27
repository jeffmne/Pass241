<?php

use App\Actions\Events\PublishEvent;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Refunds\DecideRefund;
use App\Actions\Refunds\RequestRefund;
use App\Enums\OrderStatus;
use App\Enums\RefundOrigin;
use App\Enums\RefundStatus;
use App\Enums\TicketStatus;
use App\Mail\RefundDecided;
use App\Models\Commission;
use App\Models\Event;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * Annulation d'evenement par l'organisateur (sections 24, 24.1,
 * decision D-028).
 *
 * Chemin distinct de la demande client : ici l'acheteur n'a rien demande et
 * n'y est pour rien. Il recoit TOUT, frais de paiement compris — c'est
 * l'organisateur qui les supporte.
 */

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->ticketType] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create([
            'name' => 'Concert annule',
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(5)->addHours(4),
        ]);

        return [$event, TicketType::factory()->for($event)->create([
            'name' => 'Standard', 'price' => 10_000, 'quantity' => 100,
        ])];
    });

    $this->buy = function (int $quantity = 1, ?string $email = 'awa@example.test'): Order {
        return forOrganization($this->organization, function () use ($quantity, $email) {
            $subtotal = 10_000 * $quantity;

            $order = Order::factory()->for($this->event)->create([
                'subtotal' => $subtotal,
                'amount' => $subtotal + 200 * $quantity,
                'psp_fee_amount' => 200 * $quantity,
                'email' => $email,
            ]);

            $order->items()->create([
                'ticket_type_id' => $this->ticketType->id,
                'ticket_type_name' => $this->ticketType->name,
                'quantity' => $quantity,
                'unit_price' => 10_000,
                'subtotal' => $subtotal,
            ]);

            return app(ConfirmPayment::class)->handle($order->load('items'));
        });
    };

    $this->cancel = fn () => forOrganization(
        $this->organization,
        fn () => app(PublishEvent::class)->cancel(
            $this->event->fresh(), $this->owner, 'Salle indisponible.',
        ),
    );
});

it('rembourse les frais de paiement quand l organisateur annule', function () {
    $order = ($this->buy)(2);

    ($this->cancel)();

    $request = RefundRequest::query()->acrossOrganizations()->firstOrFail();

    /*
     * 2 billets a 10 000 plus 400 de frais : l'acheteur a paye 20 400, il
     * recoit 20 400.
     *
     * Lui faire supporter le cout d'une decision qui n'est pas la sienne
     * serait indefendable — c'est l'organisateur qui les supporte
     * (decision D-028).
     */
    expect($request->origin)->toBe(RefundOrigin::EventCancelled)
        ->and($request->amount)->toBe(20_400)
        ->and($request->includes_psp_fee)->toBeTrue()
        ->and($request->amount)->toBe($order->amount);
});

it('cree la demande deja approuvee, sans rien demander a personne', function () {
    ($this->buy)(1);

    ($this->cancel)();

    $request = RefundRequest::query()->acrossOrganizations()->firstOrFail();

    // Il n'y a rien a arbitrer : l'evenement n'aura pas lieu.
    expect($request->status)->toBe(RefundStatus::Approved)
        ->and($request->decided_by)->toBe($this->owner->id);
});

it('ignore le delai de 48 heures', function () {
    forOrganization($this->organization, fn () => $this->event->forceFill([
        'starts_at' => now()->addHours(3),
        'ends_at' => now()->addHours(7),
    ])->save());

    ($this->buy)(1);

    ($this->cancel)();

    // Le delai protege l'organisateur des changements d'avis tardifs ; il n'a
    // aucun sens quand c'est lui qui annule.
    expect(RefundRequest::query()->acrossOrganizations()->count())->toBe(1);
});

it('rend les billets inutilisables et solde la commande', function () {
    ($this->buy)(2);

    ($this->cancel)();

    $order = Order::query()->acrossOrganizations()->firstOrFail();

    expect($order->status)->toBe(OrderStatus::Refunded)
        ->and(Ticket::query()->acrossOrganizations()
            ->where('status', TicketStatus::Refunded->value)->count())->toBe(2);
});

it('ne rend la commission que sur la part billets', function () {
    ($this->buy)(2);

    ($this->cancel)();

    $adjustment = Commission::query()->acrossOrganizations()
        ->whereNotNull('parent_commission_id')->firstOrFail();

    // Les 400 de frais n'ont jamais transite par l'organisateur : il n'y a
    // aucune commission a leur rendre (decision D-023).
    expect($adjustment->gross_amount)->toBe(20_000)
        ->and($adjustment->commission_amount)->toBe(1_000);
});

it('previent chaque acheteur par email', function () {
    ($this->buy)(1, 'awa@example.test');
    ($this->buy)(1, 'paul@example.test');

    ($this->cancel)();

    Mail::assertSent(RefundDecided::class, fn ($mail) => $mail->hasTo('awa@example.test'));
    Mail::assertSent(RefundDecided::class, fn ($mail) => $mail->hasTo('paul@example.test'));
});

it('n envoie aucun email a un acheteur sans adresse', function () {
    ($this->buy)(1, null);

    ($this->cancel)();

    // Section 15.1 : l'email reste facultatif. Son absence ne doit rien
    // empecher — le remboursement part quand meme.
    Mail::assertNothingSent();

    expect(RefundRequest::query()->acrossOrganizations()->count())->toBe(1);
});

it('ne rembourse pas deux fois un billet deja rembourse', function () {
    $order = ($this->buy)(2);

    // Un billet a deja ete rembourse a la demande du client.
    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();
    app(OrganizationContext::class)->forget();

    $request = app(RequestRefund::class)->handle($order->fresh()->load('event'), $ticket);
    forOrganization($this->organization, fn () => app(DecideRefund::class)
        ->approve($request, $this->owner));

    ($this->cancel)();

    $cancellation = RefundRequest::query()->acrossOrganizations()
        ->where('origin', RefundOrigin::EventCancelled->value)->firstOrFail();

    // Seul le billet restant est rembourse : 10 000 plus sa part de frais.
    expect($cancellation->amount)->toBe(10_200);
});

it('ne rembourse rien sur une commande impayee', function () {
    forOrganization($this->organization, function () {
        $order = Order::factory()->for($this->event)->create([
            'subtotal' => 10_000, 'amount' => 10_200, 'psp_fee_amount' => 200,
            'status' => OrderStatus::Failed,
        ]);
        $order->items()->create([
            'ticket_type_id' => $this->ticketType->id,
            'ticket_type_name' => 'Standard',
            'quantity' => 1, 'unit_price' => 10_000, 'subtotal' => 10_000,
        ]);
    });

    ($this->cancel)();

    // Rien n'a ete encaisse : rembourser creerait de l'argent qui n'existe pas.
    expect(RefundRequest::query()->acrossOrganizations()->count())->toBe(0);
});
