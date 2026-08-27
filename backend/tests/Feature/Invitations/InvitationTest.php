<?php

use App\Actions\Invitations\AcceptInvitation;
use App\Actions\Invitations\CancelInvitation;
use App\Actions\Invitations\CreateInvitations;
use App\Actions\Orders\CreateOrder;
use App\Enums\AuditAction;
use App\Enums\InvitationStatus;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Ticketing\StockLedger;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * Invitations (section 25).
 *
 * Ce qui est verifie ici, dans l'ordre d'importance : qu'une invitation RETIENT
 * UNE PLACE des sa creation, qu'elle n'en retient plus des qu'elle expire ou
 * qu'on l'annule, et qu'accepter produit exactement le meme billet qu'un achat
 * — sans jamais faire croire qu'une vente a eu lieu.
 */

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->ticketType] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create([
            'name' => 'Concert Afro 2026',
            'starts_at' => now()->addWeeks(2),
            'ends_at' => now()->addWeeks(2)->addHours(5),
        ]);

        return [$event, TicketType::factory()->for($event)->create([
            'name' => 'Standard', 'price' => 10_000, 'quantity' => 10,
        ])];
    });

    $this->invite = function (array $guests = [['name' => 'Marie Nzeng']], ?DateTimeInterface $expiresAt = null) {
        return forOrganization($this->organization, fn () => app(CreateInvitations::class)->handle(
            event: $this->event,
            ticketType: $this->ticketType,
            guests: $guests,
            expiresAt: $expiresAt ?? now()->addDays(3),
            actor: $this->owner,
        ));
    };

    $this->available = fn () => forOrganization(
        $this->organization,
        fn () => app(StockLedger::class)->available($this->ticketType->fresh())
    );

    app(OrganizationContext::class)->forget();
});

/*
 * ---------------------------------------------------------------------------
 * La place mise de cote
 * ---------------------------------------------------------------------------
 */

it('retient une place des la creation', function () {
    expect(($this->available)())->toBe(10);

    ($this->invite)([['name' => 'Marie Nzeng'], ['name' => 'Paul Obame']]);

    /*
     * C'est toute la raison d'etre du mecanisme : sans cela, un organisateur
     * qui invite cinquante personnes verrait son evenement se vendre pendant
     * que ses invites reflechissent, et cinquante personnes se presenteraient
     * a une porte qui n'a plus de place.
     */
    expect(($this->available)())->toBe(8);
});

it('rend la place a l echeance, sans qu aucune tache ne s execute', function () {
    ($this->invite)([['name' => 'Marie Nzeng']], now()->addMinutes(30));

    expect(($this->available)())->toBe(9);

    $this->travel(31)->minutes();

    // Decision D-017 : « reserve » se lit en base, et la condition sur la date
    // d'expiration fait expirer la reservation d'elle-meme.
    expect(($this->available)())->toBe(10);
});

it('refuse d inviter plus de places qu il n en reste', function () {
    forOrganization($this->organization, fn () => $this->ticketType->update(['quantity' => 2]));

    expect(fn () => ($this->invite)([
        ['name' => 'Un'], ['name' => 'Deux'], ['name' => 'Trois'],
    ]))->toThrow(RuntimeException::class, 'Il ne reste que 2 places');

    // Tout ou rien : aucune invitation ne doit subsister d'un lot refuse.
    expect(Invitation::query()->acrossOrganizations()->count())->toBe(0)
        ->and(Order::query()->acrossOrganizations()->count())->toBe(0);
});

it('compte les invitations dans le stock vu par un acheteur', function () {
    forOrganization($this->organization, fn () => $this->ticketType->update(['quantity' => 1]));

    ($this->invite)([['name' => 'Marie Nzeng']]);

    // L'acheteur qui arrive apres l'invitation trouve un tarif complet : la
    // place est prise, meme si personne ne l'a encore payee.
    expect(fn () => app(CreateOrder::class)->handle(
        event: $this->event->fresh(),
        quantities: [$this->ticketType->id => 1],
        firstName: 'Awa', lastName: 'Ndong', email: null,
        provider: PaymentProvider::Airtel, payerPhone: '074123456',
    ))->toThrow(RuntimeException::class);
});

/*
 * ---------------------------------------------------------------------------
 * Ce que l'invitation N'EST PAS
 * ---------------------------------------------------------------------------
 */

it('n est jamais une vente', function () {
    ($this->invite)();

    $order = Order::query()->acrossOrganizations()->firstOrFail();

    /*
     * Une invitation ne rapporte rien, et ne peut rien rapporter : la base le
     * garantit. Sans cette regle, un billet offert finirait par apparaitre
     * dans un chiffre d'affaires.
     */
    expect($order->origin)->toBe(OrderOrigin::Invitation)
        ->and($order->amount)->toBe(0)
        ->and($order->subtotal)->toBe(0)
        ->and($order->payment_provider)->toBeNull()
        ->and($order->payer_phone)->toBeNull();
});

it('refuse en base une invitation payante', function () {
    ($this->invite)();

    $order = Order::query()->acrossOrganizations()->firstOrFail();

    // La contrainte vit en base et non dans le code : elle tient aussi pour un
    // import, une commande artisan ou une correction manuelle.
    expect(fn () => forOrganization($this->organization, fn () => $order->forceFill([
        'amount' => 5_000, 'subtotal' => 5_000,
    ])->save()))->toThrow(QueryException::class);
});

it('n enregistre aucune commission a l acceptation', function () {
    $invitation = ($this->invite)()->first();

    forOrganization($this->organization, fn () => app(AcceptInvitation::class)->handle($invitation));

    /*
     * Une commission a zero franc encombrerait un journal financier qui doit
     * rester lisible, et laisserait croire qu'une vente a eu lieu.
     */
    expect(Commission::query()->acrossOrganizations()->count())->toBe(0);
});

/*
 * ---------------------------------------------------------------------------
 * Acceptation
 * ---------------------------------------------------------------------------
 */

it('emet un billet identique a celui d un achat', function () {
    $invitation = ($this->invite)([['name' => 'Marie Nzeng']])->first();

    $ticket = forOrganization($this->organization, fn () => app(AcceptInvitation::class)->handle($invitation));

    expect($ticket->holder_name)->toBe('Marie Nzeng')
        ->and($ticket->status)->toBe(TicketStatus::Active)
        ->and($ticket->reference)->toStartWith('T-')
        // Meme jeton signe, donc meme QR, donc meme controle a l'entree : un
        // invite passe la porte comme un acheteur (section 18).
        ->and($ticket->qrToken())->not->toBeEmpty()
        ->and($invitation->fresh()->status)->toBe(InvitationStatus::Accepted);
});

it('marque la place comme definitivement prise', function () {
    $invitation = ($this->invite)([['name' => 'Marie Nzeng']], now()->addMinutes(10))->first();

    forOrganization($this->organization, fn () => app(AcceptInvitation::class)->handle($invitation));

    $this->travel(11)->minutes();

    // Acceptee, la place ne revient plus a l'echeance : le billet existe.
    expect(($this->available)())->toBe(9);
});

it('rend le meme billet si le lien est rouvert', function () {
    $invitation = ($this->invite)()->first();

    $first = forOrganization($this->organization, fn () => app(AcceptInvitation::class)->handle($invitation));
    $second = forOrganization($this->organization, fn () => app(AcceptInvitation::class)->handle($invitation->fresh()));

    /*
     * Un invite rouvre son lien le jour de l'evenement pour retrouver son QR.
     * Emettre un second billet lui donnerait deux places pour une invitation.
     */
    expect($second->id)->toBe($first->id)
        ->and(Ticket::query()->acrossOrganizations()->count())->toBe(1);
});

it('refuse une invitation expiree', function () {
    $invitation = ($this->invite)([['name' => 'Marie Nzeng']], now()->addMinutes(5))->first();

    $this->travel(6)->minutes();

    expect(fn () => forOrganization($this->organization, fn () => app(AcceptInvitation::class)->handle($invitation)))
        ->toThrow(RuntimeException::class, 'expire');

    expect(Ticket::query()->acrossOrganizations()->count())->toBe(0);
});

it('refuse une invitation annulee', function () {
    $invitation = ($this->invite)()->first();

    forOrganization($this->organization, fn () => app(CancelInvitation::class)->cancel($invitation, $this->owner));

    expect(fn () => forOrganization($this->organization, fn () => app(AcceptInvitation::class)->handle($invitation->fresh())))
        ->toThrow(RuntimeException::class, 'annulee');
});

/*
 * ---------------------------------------------------------------------------
 * Annulation et expiration
 * ---------------------------------------------------------------------------
 */

it('rend la place en annulant', function () {
    $invitation = ($this->invite)()->first();

    expect(($this->available)())->toBe(9);

    forOrganization($this->organization, fn () => app(CancelInvitation::class)->cancel($invitation, $this->owner));

    expect(($this->available)())->toBe(10)
        ->and($invitation->fresh()->status)->toBe(InvitationStatus::Cancelled)
        ->and(Order::query()->acrossOrganizations()->firstOrFail()->status)->toBe(OrderStatus::Cancelled);
});

it('refuse d annuler une invitation deja acceptee', function () {
    $invitation = ($this->invite)()->first();

    forOrganization($this->organization, fn () => app(AcceptInvitation::class)->handle($invitation));

    /*
     * Le billet existe, il a pu etre transmis, imprime, presente a une porte.
     * Le retirer ici serait annuler une place que quelqu'un croit avoir : cela
     * passe par le remboursement, qui laisse une trace (section 24).
     */
    expect(fn () => forOrganization($this->organization, fn () => app(CancelInvitation::class)
        ->cancel($invitation->fresh(), $this->owner)))
        ->toThrow(RuntimeException::class, 'deja ete acceptee');
});

it('ferme les invitations echues sans rien liberer de plus', function () {
    ($this->invite)([['name' => 'Marie Nzeng']], now()->addMinutes(5));

    $this->travel(6)->minutes();

    // La place est deja rendue avant que la commande ne tourne : celle-ci ne
    // fait que mettre les mots justes sur un fait acquis.
    expect(($this->available)())->toBe(10);

    $this->artisan('pass241:expire-invitations')->assertSuccessful();

    expect(Invitation::query()->acrossOrganizations()->firstOrFail()->status)
        ->toBe(InvitationStatus::Expired);
});

/*
 * ---------------------------------------------------------------------------
 * Tracabilite et isolation
 * ---------------------------------------------------------------------------
 */

it('journalise la creation et l acceptation', function () {
    $invitation = ($this->invite)()->first();

    forOrganization($this->organization, fn () => app(AcceptInvitation::class)->handle($invitation));

    $actions = AuditLog::query()->pluck('action')->map(fn ($a) => $a->value);

    // « Qui a fait entrer cette personne gratuitement ? » doit avoir une
    // reponse six mois plus tard (section 68).
    expect($actions)->toContain(AuditAction::InvitationCreated->value)
        ->and($actions)->toContain(AuditAction::InvitationAccepted->value);
});

it('donne un jeton imprevisible et unique a chaque invitation', function () {
    $invitations = ($this->invite)([['name' => 'Un'], ['name' => 'Deux']]);

    $tokens = $invitations->pluck('token');

    // Le jeton EST l'acces : il ne doit ni se deviner, ni se repeter.
    expect($tokens->unique())->toHaveCount(2)
        ->and(strlen($tokens->first()))->toBeGreaterThanOrEqual(32);
});

it('n expose jamais une invitation d une autre organisation', function () {
    [, $other] = organizerWithOrganization('Autre association');

    $foreign = forOrganization($other, function () {
        $event = Event::factory()->published()->create();
        $type = TicketType::factory()->for($event)->create(['quantity' => 5, 'price' => 1000]);

        return app(CreateInvitations::class)->handle(
            event: $event,
            ticketType: $type,
            guests: [['name' => 'Invite etranger']],
            expiresAt: now()->addDay(),
        )->first();
    });

    ($this->invite)();

    $visible = forOrganization($this->organization, fn () => Invitation::query()->pluck('id'));

    expect($visible)->not->toContain($foreign->id)
        ->and($visible)->toHaveCount(1);
});
