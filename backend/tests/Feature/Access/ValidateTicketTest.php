<?php

use App\Actions\Access\ValidateTicket;
use App\Actions\Payments\ConfirmPayment;
use App\Enums\AuditAction;
use App\Enums\ScanResult;
use App\Enums\TicketStatus;
use App\Models\AccessScan;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * Controle d'un billet a l'entree (sections 28, 31.1).
 *
 * C'est le point ou l'on decide si quelqu'un entre. Ce qui est verifie ici :
 * qu'un billet ne passe jamais deux fois, qu'un scan hors connexion plus
 * ancien l'emporte quel que soit l'ordre d'arrivee, et que TOUT est journalise
 * — refus compris.
 */

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->ticketType] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create([
            'name' => 'Concert Afro 2026',
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(8),
        ]);

        return [$event, TicketType::factory()->for($event)->create([
            'name' => 'VIP', 'price' => 10_000, 'quantity' => 50,
        ])];
    });

    $this->agent = User::factory()->create();

    $this->issue = function (?Event $event = null): Ticket {
        $event ??= $this->event;

        return forOrganization($this->organization, function () use ($event) {
            $type = TicketType::query()->where('event_id', $event->getKey())->firstOrFail();

            $order = Order::factory()->for($event)->create([
                'subtotal' => $type->price, 'amount' => $type->price,
            ]);

            $order->items()->create([
                'ticket_type_id' => $type->id, 'ticket_type_name' => $type->name,
                'quantity' => 1, 'unit_price' => $type->price, 'subtotal' => $type->price,
            ]);

            app(ConfirmPayment::class)->handle($order->load('items'));

            return Ticket::query()->acrossOrganizations()
                ->where('order_id', $order->id)->firstOrFail();
        });
    };

    $this->scan = function (Ticket|string $ticket, array $options = []) {
        return app(ValidateTicket::class)->handle(
            event: $options['event'] ?? $this->event,
            token: is_string($ticket) ? $ticket : $ticket->qrToken(),
            agent: $options['agent'] ?? $this->agent,
            entryPointId: $options['entryPoint'] ?? null,
            deviceId: $options['device'] ?? 'device-A',
            scannedAt: $options['at'] ?? now(),
            clockOffsetSeconds: $options['offset'] ?? null,
        );
    };

    app(OrganizationContext::class)->forget();
});

/*
 * ---------------------------------------------------------------------------
 * Les quatre reponses de la section 28
 * ---------------------------------------------------------------------------
 */

it('accepte un billet valide et le marque utilise', function () {
    $ticket = ($this->issue)();

    $scan = ($this->scan)($ticket);

    expect($scan->result)->toBe(ScanResult::Accepted)
        ->and($scan->allowedEntry())->toBeTrue()
        ->and($ticket->fresh()->status)->toBe(TicketStatus::Used)
        ->and($ticket->fresh()->used_at)->not->toBeNull();
});

it('refuse un billet deja utilise', function () {
    $ticket = ($this->issue)();

    ($this->scan)($ticket, ['at' => now()]);
    $second = ($this->scan)($ticket, ['at' => now()->addMinute(), 'device' => 'device-B']);

    // Un seul passage par billet : c'est toute la raison d'etre du module.
    expect($second->result)->toBe(ScanResult::AlreadyUsed)
        ->and($second->allowedEntry())->toBeFalse();
});

it('refuse un QR non reconnu et le journalise quand meme', function () {
    $scan = ($this->scan)('1.faux-jeton.signature-bidon');

    /*
     * Le scan est journalise SANS billet, et c'est le cas le plus interessant :
     * il signale une contrefacon. Ne rien enregistrer priverait l'organisateur
     * de la seule trace qu'on a essaye d'entrer avec un faux.
     */
    expect($scan->result)->toBe(ScanResult::Invalid)
        ->and($scan->ticket_id)->toBeNull()
        ->and(AccessScan::query()->acrossOrganizations()->count())->toBe(1);
});

it('refuse un billet rembourse', function () {
    $ticket = ($this->issue)();

    /*
     * Le billet est marque rembourse directement : le parcours de demande est
     * couvert par RefundTest, et il est ici hors sujet — l'evenement commence
     * dans deux heures, donc la fenetre de 48h est fermee, ce qui est le
     * comportement attendu (section 24.1).
     */
    forOrganization($this->organization, fn () => $ticket->forceFill([
        'status' => TicketStatus::Refunded->value,
    ])->save());

    expect(($this->scan)($ticket->fresh())->result)->toBe(ScanResult::Refunded);
});

it('refuse un billet authentique emis pour un autre evenement', function () {
    $other = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create(['name' => 'Autre soiree']);
        TicketType::factory()->for($event)->create(['name' => 'Standard', 'price' => 5_000, 'quantity' => 10]);

        return $event;
    });

    $foreign = ($this->issue)($other);

    /*
     * Sa signature est valide : sans cette verification, un billet authentique
     * d'un autre evenement ouvrirait n'importe quelle porte de la plateforme.
     */
    $scan = ($this->scan)($foreign);

    expect($scan->result)->toBe(ScanResult::WrongEvent)
        ->and($foreign->fresh()->status)->toBe(TicketStatus::Active);
});

/*
 * ---------------------------------------------------------------------------
 * Double scan hors connexion (section 31.1)
 * ---------------------------------------------------------------------------
 */

it('fait gagner le scan au timestamp local le plus ancien', function () {
    $ticket = ($this->issue)();

    // L'appareil B synchronise en premier, mais a scanne plus tard.
    $late = ($this->scan)($ticket, ['device' => 'device-B', 'at' => now()->setTime(20, 3, 45)]);
    $early = ($this->scan)($ticket, ['device' => 'device-A', 'at' => now()->setTime(20, 3, 12)]);

    /*
     * L'ordre d'arrivee sur le reseau ne compte pas : un agent hors connexion
     * qui synchronise une heure plus tard ne perd pas son anteriorite. C'est
     * ce qui rend le mode hors ligne utilisable a une vraie entree.
     */
    expect($early->result)->toBe(ScanResult::Accepted)
        ->and($late->fresh()->result)->toBe(ScanResult::ConflictLost)
        ->and($late->fresh()->conflict_with)->toBe($early->id)
        ->and($ticket->fresh()->used_at->format('H:i:s'))->toBe('20:03:12');
});

it('lie le scan perdant a son vainqueur dans les deux sens', function () {
    $ticket = ($this->issue)();

    $first = ($this->scan)($ticket, ['device' => 'device-A', 'at' => now()->setTime(20, 0, 0)]);
    $second = ($this->scan)($ticket, ['device' => 'device-B', 'at' => now()->setTime(20, 5, 0)]);

    // L'historique doit dire ce qui s'est reellement passe, plutot que de
    // faire disparaitre l'un des deux passages.
    expect($second->conflict_with)->toBe($first->id);

    // La relation se lit dans le contexte de l'organisation : un agent scanne
    // toujours depuis l'organisation de son evenement.
    forOrganization(
        $this->organization,
        fn () => expect($second->fresh()->winner->id)->toBe($first->id),
    );
});

it('ne cree pas de doublon quand la file locale est retransmise', function () {
    $ticket = ($this->issue)();
    $at = now()->setTime(21, 0, 0);

    $first = ($this->scan)($ticket, ['device' => 'device-A', 'at' => $at]);
    $replay = ($this->scan)($ticket, ['device' => 'device-A', 'at' => $at]);

    /*
     * Une synchronisation interrompue est retransmise en entier : sans
     * idempotence, chaque coupure reseau creerait des doublons dans
     * l'historique de controle.
     */
    expect($replay->id)->toBe($first->id)
        ->and(AccessScan::query()->acrossOrganizations()->count())->toBe(1);
});

it('conserve l ecart d horloge de l appareil', function () {
    $ticket = ($this->issue)();

    $scan = ($this->scan)($ticket, ['offset' => 400]);

    /*
     * La regle du double scan repose sur l'horodatage local : un appareil
     * deregle de plusieurs minutes peut faire gagner le mauvais scan. Ce
     * signal ne bloque rien — il permet de le demontrer apres coup.
     */
    expect($scan->clock_offset_seconds)->toBe(400)
        ->and($scan->hasSuspiciousClock())->toBeTrue();
});

/*
 * ---------------------------------------------------------------------------
 * Immuabilite du journal
 * ---------------------------------------------------------------------------
 */

it('fige les faits d un scan', function () {
    $ticket = ($this->issue)();
    $scan = ($this->scan)($ticket);

    // Billet, appareil, agent et horodatage local font foi si quelqu'un
    // conteste une entree.
    expect(fn () => forOrganization($this->organization, fn () => $scan->update([
        'scanned_at' => now()->addHour(),
    ])))->toThrow(RuntimeException::class, 'figes');
});

it('interdit de rendre valide un scan refuse', function () {
    $ticket = ($this->issue)();
    ($this->scan)($ticket);
    $refused = ($this->scan)($ticket, ['device' => 'device-B', 'at' => now()->addMinute()]);

    // Un scan ne change d'issue que pour PERDRE un arbitrage, jamais pour en
    // gagner un : l'inverse permettrait de fabriquer une entree apres coup.
    expect(fn () => forOrganization($this->organization, fn () => $refused->update([
        'result' => ScanResult::Accepted->value,
    ])))->toThrow(RuntimeException::class);
});

it('refuse la suppression d un scan', function () {
    $ticket = ($this->issue)();
    $scan = ($this->scan)($ticket);

    expect(fn () => forOrganization($this->organization, fn () => $scan->delete()))
        ->toThrow(RuntimeException::class, 'ne se supprime pas');
});

it('journalise le passage dans le journal d audit', function () {
    $ticket = ($this->issue)();
    ($this->scan)($ticket);

    expect(AuditLog::action(AuditAction::TicketUsed)->count())->toBe(1);
});
