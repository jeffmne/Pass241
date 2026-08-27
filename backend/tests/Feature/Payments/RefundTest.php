<?php

use App\Actions\Payments\ConfirmPayment;
use App\Actions\Organizations\SetPayoutDestination;
use App\Actions\Refunds\DecideRefund;
use App\Actions\Refunds\RequestRefund;
use App\Actions\Settlements\SettleFunds;
use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\RefundStatus;
use App\Enums\TicketStatus;
use App\Livewire\Dashboard\Refunds;
use App\Mail\RefundDecided;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\Event;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Commission\CommissionCalculator;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * Remboursements (section 24.1, decision D-027).
 *
 * Modele hybride : le client DEMANDE, l'organisateur DECIDE. Le client ne
 * declenche jamais un remboursement lui-meme.
 *
 * La fenetre de 48h avant l'evenement n'est pas une politique commerciale,
 * c'est la pierre angulaire du modele financier : le reversement intervient
 * apres l'evenement, donc aucun remboursement n'exige jamais de recuperer des
 * fonds deja partis.
 */

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->ticketType] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create([
            'name' => 'Concert a venir',
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(10)->addHours(4),
        ]);

        return [$event, TicketType::factory()->for($event)->create([
            'name' => 'Standard', 'price' => 10_000, 'quantity' => 100,
        ])];
    });

    app(SetPayoutDestination::class)->handle(
        $this->organization, '074000000', PaymentProvider::Airtel, $this->owner,
    );

    $this->buy = function (int $quantity = 1): Order {
        return forOrganization($this->organization, function () use ($quantity) {
            $subtotal = 10_000 * $quantity;

            $order = Order::factory()->for($this->event)->create([
                'subtotal' => $subtotal,
                'amount' => $subtotal + 200 * $quantity,
                'psp_fee_amount' => 200 * $quantity,
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

    app(OrganizationContext::class)->forget();
});

/*
 * ---------------------------------------------------------------------------
 * La demande
 * ---------------------------------------------------------------------------
 */

it('ouvre une demande sans rien rembourser', function () {
    $order = ($this->buy)(1);

    $request = app(RequestRefund::class)->handle($order);

    // Le client demande, il ne rembourse pas : aucun argent ne bouge ici.
    expect($request->status)->toBe(RefundStatus::Requested)
        ->and($request->amount)->toBe(10_000)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();

    expect($ticket->status)->toBe(TicketStatus::Active);
});

it('ne rembourse jamais les frais de paiement', function () {
    $order = ($this->buy)(1);

    $request = app(RequestRefund::class)->handle($order);

    // Les frais ont ete verses a l'operateur, qui ne les rend pas. Les
    // rembourser quand meme reviendrait a les faire payer par Pass241 a chaque
    // changement d'avis (decision D-023).
    expect($request->amount)->toBe(10_000)
        ->and($request->psp_fee_amount)->toBe(200)
        ->and($order->amount)->toBe(10_200);
});

it('refuse une demande hors du delai de 48 heures', function () {
    forOrganization($this->organization, fn () => $this->event->forceFill([
        'starts_at' => now()->addHours(12),
        'ends_at' => now()->addHours(16),
    ])->save());

    $order = ($this->buy)(1);

    // Verifie cote serveur, sans attendre l'organisateur. C'est ce delai qui
    // garantit qu'aucun remboursement n'exigera de recuperer des fonds deja
    // reverses (decision D-026).
    expect(fn () => app(RequestRefund::class)->handle($order->fresh()->load('event')))
        ->toThrow(RuntimeException::class, '48 heures avant');
});

it('refuse une seconde demande tant que la premiere est en cours', function () {
    $order = ($this->buy)(2);

    app(RequestRefund::class)->handle($order);

    // Sans cette regle, un client pourrait empiler dix demandes pour forcer la
    // main a l'organisateur.
    expect(fn () => app(RequestRefund::class)->handle($order->fresh()->load('event')))
        ->toThrow(RuntimeException::class, 'deja en cours');
});

it('refuse une demande sur le billet d une autre commande', function () {
    $first = ($this->buy)(1);
    ($this->buy)(1);

    $foreign = Ticket::query()->acrossOrganizations()
        ->where('order_id', '!=', $first->id)->firstOrFail();

    // Sans cette verification, une reference glissee dans la requete
    // permettrait de demander le remboursement du billet de quelqu'un d'autre.
    expect(fn () => app(RequestRefund::class)->handle($first->fresh()->load('event'), $foreign))
        ->toThrow(RuntimeException::class, 'n\'appartient pas');
});

it('refuse une demande sur une commande impayee', function () {
    $order = forOrganization($this->organization, fn () => Order::factory()
        ->for($this->event)->create(['subtotal' => 10_000, 'amount' => 10_000]));

    expect(fn () => app(RequestRefund::class)->handle($order->load('event')))
        ->toThrow(RuntimeException::class, 'pas ete payee');
});

/*
 * ---------------------------------------------------------------------------
 * La decision
 * ---------------------------------------------------------------------------
 */

it('rend le billet inutilisable quand la demande est acceptee', function () {
    $order = ($this->buy)(1);
    $request = app(RequestRefund::class)->handle($order);

    app(DecideRefund::class)->approve($request, $this->owner);

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();

    expect($ticket->status)->toBe(TicketStatus::Refunded)
        ->and($ticket->allowsEntry())->toBeFalse()
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded);
});

it('ne rembourse rien quand la demande est refusee', function () {
    $order = ($this->buy)(1);
    $request = app(RequestRefund::class)->handle($order);

    app(DecideRefund::class)->reject($request, $this->owner, 'Evenement maintenu.');

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();

    expect($request->fresh()->status)->toBe(RefundStatus::Rejected)
        ->and($ticket->status)->toBe(TicketStatus::Active)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('exige un motif pour refuser', function () {
    $order = ($this->buy)(1);
    $request = app(RequestRefund::class)->handle($order);

    // Sans motif, le client ne peut ni comprendre ni contester (section 24.1).
    expect(fn () => app(DecideRefund::class)->reject($request, $this->owner, '  '))
        ->toThrow(RuntimeException::class, 'motive');
});

it('interdit de revenir sur une decision', function () {
    $order = ($this->buy)(1);
    $request = app(RequestRefund::class)->handle($order);

    app(DecideRefund::class)->reject($request, $this->owner, 'Non.');

    // Un organisateur qui refuse puis accepte puis refuse rendrait tout
    // arbitrage impossible. Un changement d'avis passe par une NOUVELLE
    // demande, qui laisse sa propre trace.
    expect(fn () => app(DecideRefund::class)->approve($request->fresh(), $this->owner))
        ->toThrow(RuntimeException::class, 'deja ete tranchee');
});

it('conserve un billet deja utilise malgre le remboursement', function () {
    $order = ($this->buy)(1);

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();

    forOrganization($this->organization, fn () => $ticket->forceFill([
        'status' => TicketStatus::Used->value,
        'used_at' => now(),
    ])->save());

    $request = app(RequestRefund::class)->handle($order->fresh()->load('event'));
    app(DecideRefund::class)->approve($request, $this->owner);

    // Le remboursement regle la question de l'argent ; il n'annule pas une
    // entree deja effectuee.
    expect($ticket->fresh()->status)->toBe(TicketStatus::Used);
});

/*
 * ---------------------------------------------------------------------------
 * Commission au prorata
 * ---------------------------------------------------------------------------
 */

it('ajuste la commission par une ligne nouvelle et non par une modification', function () {
    $order = ($this->buy)(2);
    $request = app(RequestRefund::class)->handle($order);

    app(DecideRefund::class)->approve($request, $this->owner);

    $original = Commission::query()->acrossOrganizations()->originals()->firstOrFail();
    $adjustment = Commission::query()->acrossOrganizations()
        ->whereNotNull('parent_commission_id')->firstOrFail();

    // La table est en ajout seul : une correction s'ajoute au passe, elle ne
    // le reecrit pas (section 46). L'origine dit ce qui a ete preleve,
    // l'ajustement ce qui a ete rendu.
    expect($original->gross_amount)->toBe(20_000)
        ->and($original->commission_amount)->toBe(1_000)
        ->and($adjustment->parent_commission_id)->toBe($original->id)
        ->and($adjustment->gross_amount)->toBe(20_000)
        ->and($adjustment->commission_amount)->toBe(1_000);
});

it('rembourse au taux fige de la transaction et non au taux du jour', function () {
    $order = ($this->buy)(1);
    $request = app(RequestRefund::class)->handle($order);

    // Le taux change entre l'achat et le remboursement.
    config()->set('commission.basis_points.TICKETING', 900);

    app(DecideRefund::class)->approve($request, $this->owner);

    $adjustment = Commission::query()->acrossOrganizations()
        ->whereNotNull('parent_commission_id')->firstOrFail();

    // Rembourser au taux d'aujourd'hui une transaction facturee au taux d'hier
    // produirait un ecart que personne ne saurait expliquer.
    expect($adjustment->rate_basis_points)->toBe(500)
        ->and($adjustment->commission_amount)->toBe(500);
});

it('refuse un ajustement superieur au montant de la transaction', function () {
    $order = ($this->buy)(1);
    app(RequestRefund::class)->handle($order);

    $original = Commission::query()->acrossOrganizations()->originals()->firstOrFail();

    expect(fn () => app(CommissionCalculator::class)
        ->recordRefund($original, 999_999))
        ->toThrow(InvalidArgumentException::class, 'ne reste que');
});

it('empeche un ajustement d ajustement', function () {
    $order = ($this->buy)(1);
    $request = app(RequestRefund::class)->handle($order);
    app(DecideRefund::class)->approve($request, $this->owner);

    $adjustment = Commission::query()->acrossOrganizations()
        ->whereNotNull('parent_commission_id')->firstOrFail();

    // Une chaine d'ajustements rendrait le cumul impossible a verifier.
    expect(fn () => app(CommissionCalculator::class)
        ->recordRefund($adjustment, 100))
        ->toThrow(InvalidArgumentException::class, 'commission d\'origine');
});

/*
 * ---------------------------------------------------------------------------
 * Effet sur le reversement
 * ---------------------------------------------------------------------------
 */

it('deduit le remboursement du reversement a l organisateur', function () {
    $order = ($this->buy)(3);

    // Une demande partielle : un seul billet sur trois.
    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();
    $request = app(RequestRefund::class)->handle($order->fresh()->load('event'), $ticket);

    app(DecideRefund::class)->approve($request, $this->owner);

    forOrganization($this->organization, fn () => $this->event->forceFill([
        'starts_at' => now()->subDays(3),
        'ends_at' => now()->subDays(2),
    ])->save());

    $settlement = app(SettleFunds::class)->handle($this->event->fresh()->load('organization'));

    /*
     * 3 billets a 10 000 = 30 000, moins 5 % = 28 500 de net.
     * Un billet rembourse : 10 000 de brut retires, soit 9 500 de net en moins.
     *
     * Reverser les 28 500 complets enverrait a l'organisateur de l'argent deja
     * rendu au client.
     */
    expect($settlement->gross_amount)->toBe(20_000)
        ->and($settlement->net_amount)->toBe(19_000);
});

it('ne reverse rien quand tout a ete rembourse', function () {
    $order = ($this->buy)(2);
    $request = app(RequestRefund::class)->handle($order);
    app(DecideRefund::class)->approve($request, $this->owner);

    forOrganization($this->organization, fn () => $this->event->forceFill([
        'starts_at' => now()->subDays(3),
        'ends_at' => now()->subDays(2),
    ])->save());

    expect(app(SettleFunds::class)->handle($this->event->fresh()->load('organization')))->toBeNull();
});

/*
 * ---------------------------------------------------------------------------
 * Tracabilite et expiration
 * ---------------------------------------------------------------------------
 */

it('journalise la demande et la decision', function () {
    $order = ($this->buy)(1);
    $request = app(RequestRefund::class)->handle($order);

    app(DecideRefund::class)->approve($request, $this->owner);

    expect(AuditLog::action(AuditAction::RefundRequested)->count())->toBe(1)
        ->and(AuditLog::action(AuditAction::RefundApproved)->count())->toBe(1);
});

it('ferme sans reponse une demande restee en suspens', function () {
    $order = ($this->buy)(1);
    app(RequestRefund::class)->handle($order);

    // L'evenement approche, personne n'a tranche.
    forOrganization($this->organization, fn () => $this->event->forceFill([
        'starts_at' => now()->addHours(2),
        'ends_at' => now()->addHours(6),
    ])->save());

    $this->artisan('pass241:expire-refund-requests')->assertSuccessful();

    $request = RefundRequest::query()->acrossOrganizations()->firstOrFail();

    // EXPIRED et non REJECTED : le client a demande dans les temps, personne
    // n'a repondu. La distinction compte pour arbitrer un litige.
    expect($request->status)->toBe(RefundStatus::Expired)
        ->and($request->decided_by)->toBeNull()
        ->and(AuditLog::action(AuditAction::RefundExpired)->count())->toBe(1);
});

/*
 * ---------------------------------------------------------------------------
 * Ecrans
 * ---------------------------------------------------------------------------
 */

it('permet a l acheteur de demander depuis le suivi de commande', function () {
    $order = ($this->buy)(1);

    Livewire\Livewire::test(App\Livewire\Public\OrderStatus::class, ['reference' => $order->reference])
        ->set('refundReason', 'Empeche ce soir-la.')
        ->call('requestRefund')
        ->assertHasNoErrors();

    $request = RefundRequest::query()->acrossOrganizations()->firstOrFail();

    expect($request->reason)->toBe('Empeche ce soir-la.')
        ->and($request->status)->toBe(RefundStatus::Requested);
});

it('explique a l acheteur pourquoi il ne peut plus demander', function () {
    forOrganization($this->organization, fn () => $this->event->forceFill([
        'starts_at' => now()->addHours(6),
        'ends_at' => now()->addHours(10),
    ])->save());

    $order = ($this->buy)(1);

    // Section 24.1 : le bouton reste visible et explique, plutot que de
    // disparaitre sans raison.
    Livewire\Livewire::test(App\Livewire\Public\OrderStatus::class, ['reference' => $order->reference])
        ->assertSee('48 heures avant', escape: false);
});

it('laisse l organisateur trancher depuis son tableau de bord', function () {
    $order = ($this->buy)(1);
    app(RequestRefund::class)->handle($order);

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $request = RefundRequest::query()->firstOrFail();

    Livewire\Livewire::test(Refunds::class)
        ->call('approve', $request->id)
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe(RefundStatus::Approved);
});

it('refuse une decision de refus sans motif depuis l ecran', function () {
    $order = ($this->buy)(1);
    app(RequestRefund::class)->handle($order);

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $request = RefundRequest::query()->firstOrFail();

    Livewire\Livewire::test(Refunds::class)
        ->call('reject', $request->id)
        ->assertHasErrors(['reason.'.$request->id]);

    expect($request->fresh()->status)->toBe(RefundStatus::Requested);
});

it('n atteint jamais la demande d une autre organisation', function () {
    $order = ($this->buy)(1);
    $foreign = app(RequestRefund::class)->handle($order);

    [$otherOwner, $other] = organizerWithOrganization('Autre association');

    actAsOrganization($other);
    $this->actingAs($otherOwner);

    // Regle 80.4 : un identifiant venu du navigateur ne doit jamais atteindre
    // la donnee d'une autre organisation. La portee globale la masque, et la
    // demande devient introuvable — elle n'est pas « refusee », elle n'existe
    // pas de ce point de vue.
    expect(fn () => Livewire\Livewire::test(Refunds::class)
        ->call('approve', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    expect($foreign->fresh()->status)->toBe(RefundStatus::Requested);
});

/*
 * ---------------------------------------------------------------------------
 * Notification du client (section 69)
 * ---------------------------------------------------------------------------
 */

it('previent le client que sa demande est acceptee', function () {
    $order = forOrganization($this->organization, fn () => Order::factory()
        ->for($this->event)->create([
            'subtotal' => 10_000, 'amount' => 10_200,
            'psp_fee_amount' => 200, 'email' => 'awa@example.test',
        ]));

    forOrganization($this->organization, fn () => $order->items()->create([
        'ticket_type_id' => $this->ticketType->id,
        'ticket_type_name' => 'Standard',
        'quantity' => 1, 'unit_price' => 10_000, 'subtotal' => 10_000,
    ]));

    forOrganization($this->organization, fn () => app(ConfirmPayment::class)->handle($order->load('items')));

    $request = app(RequestRefund::class)->handle($order->fresh()->load('event'));
    app(DecideRefund::class)->approve($request, $this->owner);

    Mail::assertSent(RefundDecided::class,
        fn ($mail) => $mail->hasTo('awa@example.test'));
});

it('previent le client que sa demande est refusee, avec le motif', function () {
    $order = ($this->buy)(1);
    forOrganization($this->organization, fn () => $order->forceFill(['email' => 'awa@example.test'])->save());

    $request = app(RequestRefund::class)->handle($order->fresh()->load('event'));
    app(DecideRefund::class)->reject($request, $this->owner, 'Evenement maintenu.');

    // Section 24.1 : un refus se motive. Transmettre le motif ailleurs que
    // dans cet email reviendrait a ne pas le motiver du tout.
    Mail::assertSent(RefundDecided::class, function ($mail) {
        return $mail->request->decision_reason === 'Evenement maintenu.';
    });
});

it('n envoie aucun email a un acheteur sans adresse', function () {
    $order = ($this->buy)(1);
    forOrganization($this->organization, fn () => $order->forceFill(['email' => null])->save());

    $request = app(RequestRefund::class)->handle($order->fresh()->load('event'));
    app(DecideRefund::class)->approve($request, $this->owner);

    Mail::assertNotSent(RefundDecided::class);
});
