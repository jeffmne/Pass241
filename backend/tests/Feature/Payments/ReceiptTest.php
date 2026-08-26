<?php

use App\Actions\Orders\ConfirmPayment;
use App\Actions\Refunds\DecideRefund;
use App\Actions\Refunds\RequestRefund;
use App\Mail\TicketsIssued;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Billing\ReceiptPdf;
use App\Support\Money;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/*
 * Recu de paiement (section 53 adaptee, decision D-029).
 *
 * Piece comptable, pas un billet. Ce qui compte est qu'un montant se retrouve
 * sur un relevé mobile money — d'ou l'attention portee a l'eclatement, et
 * l'absence de la commission, que l'acheteur n'a pas payee.
 */

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->ticketType] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create([
            'name' => 'Concert Afro 2026',
            'starts_at' => now()->addDays(20),
            'ends_at' => now()->addDays(20)->addHours(4),
        ]);

        return [$event, TicketType::factory()->for($event)->create([
            'name' => 'VIP', 'price' => 10_000, 'quantity' => 50,
        ])];
    });

    $this->buy = function (int $quantity = 1): Order {
        return forOrganization($this->organization, function () use ($quantity) {
            $subtotal = 10_000 * $quantity;

            $order = Order::factory()->for($this->event)->create([
                'subtotal' => $subtotal,
                'amount' => $subtotal + 200 * $quantity,
                'psp_fee_amount' => 200 * $quantity,
                'email' => 'awa@example.test',
            ]);

            $order->items()->create([
                'ticket_type_id' => $this->ticketType->id,
                'ticket_type_name' => 'VIP',
                'quantity' => $quantity,
                'unit_price' => 10_000,
                'subtotal' => $subtotal,
            ]);

            return app(ConfirmPayment::class)->handle($order->load('items'));
        });
    };

    app(OrganizationContext::class)->forget();

    $this->html = function (Order $order): string {
        $order = Order::publicByReference($order->reference);
        $receipts = app(ReceiptPdf::class);

        return view('pdf.receipt', [
            'order' => $order,
            'event' => $order->event,
            'organization' => $order->event->organization,
            'reference' => $receipts->reference($order),
            'refunds' => $order->refundRequests()->acrossOrganizations()
                ->where('status', 'APPROVED')->get(),
            'tz' => config('app.display_timezone'),
        ])->render();
    };
});

it('presente l organisateur comme vendeur, jamais Pass241', function () {
    $order = ($this->buy)(1);

    $html = ($this->html)($order);

    /*
     * La plateforme encaisse pour le compte de l'organisateur et n'est jamais
     * depositaire des fonds (section 21.2). Un recu qui presenterait Pass241
     * comme vendeur dirait le contraire de ce que le produit est.
     */
    expect($html)->toContain('Vendeur')
        ->and($html)->toContain('Association test')
        ->and($html)->toContain('pour le compte de Association test');
});

it('detaille les frais de paiement sur leur propre ligne', function () {
    $order = ($this->buy)(1);

    $html = ($this->html)($order);

    // Sans cette ligne, l'acheteur debite de 10 200 pour un billet a 10 000 ne
    // peut pas reconcilier avec son relevé, et l'ecart se lit comme une
    // tromperie (decision D-023).
    expect($html)->toContain('Frais de paiement')
        ->and($html)->toContain(Money::format(10_000))
        ->and($html)->toContain(Money::format(200))
        ->and($html)->toContain(Money::format(10_200));
});

it('n imprime jamais la commission Pass241', function () {
    ($this->buy)(1);

    $html = ($this->html)(Order::query()->acrossOrganizations()->firstOrFail());

    // Elle se joue entre la plateforme et l'organisateur : l'acheteur ne l'a
    // pas payee, et l'imprimer lui ferait croire qu'elle lui a ete prelevee.
    expect($html)->not->toContain('Commission')
        ->and($html)->not->toContain('commission');
});

it('masque le numero de paiement', function () {
    $order = ($this->buy)(1);

    $html = ($this->html)($order);

    // Section 52 : un recu se transmet a un tresorier, se joint a une note de
    // frais, et finit archive.
    expect($html)->not->toContain($order->payer_phone)
        ->and($html)->toContain(mb_substr($order->payer_phone, -4));
});

it('porte une reference stable derivee de la commande', function () {
    $order = ($this->buy)(1);
    $receipts = app(ReceiptPdf::class);

    // Deterministe : le meme recu regenere six mois plus tard porte le meme
    // numero, sans colonne ni sequence a maintenir.
    expect($receipts->reference($order))->toBe('RC-'.mb_substr($order->reference, 6))
        ->and($receipts->reference($order->fresh()))->toBe($receipts->reference($order));
});

it('fait apparaitre un remboursement ulterieur', function () {
    $order = ($this->buy)(2);

    $request = app(RequestRefund::class)->handle($order->fresh()->load('event'));
    forOrganization($this->organization, fn () => app(DecideRefund::class)
        ->approve($request, $this->owner));

    $html = ($this->html)($order);

    // Un recu qui continuerait a attester un paiement integral apres
    // remboursement serait faux.
    expect($html)->toContain('Remboursements')
        ->and($html)->toContain($request->reference)
        ->and($html)->toContain('Reste acquis');
});

/*
 * ---------------------------------------------------------------------------
 * Acces
 * ---------------------------------------------------------------------------
 */

it('telecharge le recu sur un lien signe', function () {
    $order = ($this->buy)(1);

    $this->get($order->receiptUrl())
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertDownload('recu-'.app(ReceiptPdf::class)->reference($order).'.pdf');
});

it('refuse un lien de recu non signe', function () {
    $order = ($this->buy)(1);

    // Connaitre la reference ne suffit pas : on ne doit pas pouvoir parcourir
    // les recus en devinant (decision D-004).
    $this->get('/commande/'.$order->reference.'/recu')->assertForbidden();
});

it('n emet aucun recu sur une commande jamais payee', function () {
    $order = forOrganization($this->organization, fn () => Order::factory()
        ->for($this->event)->create(['subtotal' => 10_000, 'amount' => 10_000]));

    // Attester un paiement qui n'a pas eu lieu serait un faux.
    $this->get(URL::signedRoute('orders.receipt', ['reference' => $order->reference]))
        ->assertNotFound();
});

it('emet encore un recu apres remboursement', function () {
    $order = ($this->buy)(1);

    $request = app(RequestRefund::class)->handle($order->fresh()->load('event'));
    forOrganization($this->organization, fn () => app(DecideRefund::class)
        ->approve($request, $this->owner));

    // Une commande remboursee n'est plus payee, mais elle l'a ete : le recu
    // atteste un paiement qui a bien eu lieu.
    $this->get($order->fresh()->receiptUrl())->assertOk();
});

it('joint le recu a l email, une seule fois', function () {
    ($this->buy)(2);

    Mail::assertSent(TicketsIssued::class, function ($mail) {
        $names = collect($mail->attachments())
            ->map(fn ($attachment) => $attachment->as);

        // Deux billets, un seul recu : il porte sur la commande entiere, le
        // dupliquer par billet n'aurait aucun sens comptable.
        expect($names->filter(fn ($n) => str_starts_with($n, 'recu-')))->toHaveCount(1)
            ->and($names->filter(fn ($n) => str_starts_with($n, 'billet-')))->toHaveCount(2);

        return true;
    });
});
