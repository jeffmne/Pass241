<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Ticketing\TicketTokenService;

/*
 * Jeton signe du QR Code (section 18).
 *
 * « Ne jamais encoder uniquement un ID incremental. » Ces tests portent
 * autant sur ce que le jeton permet que sur ce qu'il interdit : fabriquer un
 * billet, ou apprendre quelque chose sur son porteur.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');
    actAsOrganization($this->organization);

    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create();
    $order = Order::factory()->for($event)->paid()->create([
        'first_name' => 'Awa',
        'last_name' => 'Ndong',
        'email' => 'awa@example.test',
    ]);

    $item = $order->items()->create([
        'ticket_type_id' => $ticketType->id,
        'ticket_type_name' => $ticketType->name,
        'quantity' => 1,
        'unit_price' => $ticketType->price,
        'subtotal' => $ticketType->price,
    ]);

    $this->ticket = Ticket::create([
        'event_id' => $event->id,
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'ticket_type_id' => $ticketType->id,
        'reference' => 'T-AAAA-BBBB',
        'holder_name' => 'Awa Ndong',
    ]);

    $this->tokens = app(TicketTokenService::class);
});

it('produit un jeton verifiable qui retrouve le billet', function () {
    $token = $this->tokens->issue($this->ticket);

    $verified = $this->tokens->verify($token);

    expect($verified)->not->toBeNull()
        ->and($verified->ticketId)->toBe($this->ticket->id)
        ->and($verified->eventId)->toBe($this->ticket->event_id)
        ->and($verified->orderId)->toBe($this->ticket->order_id)
        ->and($verified->ticketTypeId)->toBe($this->ticket->ticket_type_id);
});

it('n encode jamais de donnee personnelle', function () {
    $token = $this->tokens->issue($this->ticket);

    // Section 18 : un QR Code se photographie, se partage et s'affiche sur un
    // ecran verrouille. Il ne doit rien reveler de son porteur.
    expect($token)->not->toContain('Awa')
        ->and($token)->not->toContain('Ndong')
        ->and($token)->not->toContain('awa@example.test')
        ->and($token)->not->toContain('T-AAAA-BBBB');
});

it('rejette un jeton dont la charge utile a ete modifiee', function () {
    $token = $this->tokens->issue($this->ticket);

    // Tentative la plus evidente : changer l'identifiant du billet pour viser
    // celui de quelqu'un d'autre.
    [$version, $payload, $signature] = explode('.', $token);
    $fields = explode('-', $payload);
    $fields[0] = (string) ($this->ticket->id + 1);

    $forged = $version.'.'.implode('-', $fields).'.'.$signature;

    expect($this->tokens->verify($forged))->toBeNull();
});

it('rejette un jeton dont la signature a ete modifiee', function () {
    $token = $this->tokens->issue($this->ticket);

    [$version, $payload, $signature] = explode('.', $token);
    $forged = $version.'.'.$payload.'.'.strrev($signature);

    expect($this->tokens->verify($forged))->toBeNull();
});

it('rejette un identifiant brut sans signature', function () {
    // C'est exactement ce que la section 18 interdit : « ticketId=123 ».
    expect($this->tokens->verify((string) $this->ticket->id))->toBeNull()
        ->and($this->tokens->verify('1-1-1-1'))->toBeNull();
});

it('rejette un jeton malforme sans jamais lever d exception', function () {
    foreach (['', '...', 'a.b', '1.abc.def', '2.1-1-1-1.zzz', 'null'] as $garbage) {
        expect($this->tokens->verify($garbage))->toBeNull();
    }
});

it('refuse un jeton signe avec une autre cle', function () {
    $token = $this->tokens->issue($this->ticket);

    // Une cle differente ne doit rien valider : c'est ce qui empeche un
    // environnement de test de produire des billets valides en production.
    config()->set('app.ticket_token_key', 'base64:'.base64_encode(random_bytes(32)));

    expect(app(TicketTokenService::class)->verify($token))->toBeNull();
});

it('donne un jeton different a chaque billet', function () {
    $other = Ticket::create([
        'event_id' => $this->ticket->event_id,
        'order_id' => $this->ticket->order_id,
        'order_item_id' => $this->ticket->order_item_id,
        'ticket_type_id' => $this->ticket->ticket_type_id,
        'reference' => 'T-CCCC-DDDD',
        'holder_name' => 'Awa Ndong',
    ]);

    expect($this->ticket->qrToken())->not->toBe($other->qrToken());
});

it('reste stable dans le temps pour un meme billet', function () {
    // Le jeton est imprime sur un PDF que l'acheteur garde des semaines : il
    // ne doit pas changer entre deux affichages.
    expect($this->ticket->qrToken())->toBe($this->ticket->qrToken());
});

it('refuse de signer sans cle configuree', function () {
    config()->set('app.ticket_token_key', '');

    // Mieux vaut une erreur bruyante qu'un billet signe avec une cle vide,
    // que n'importe qui pourrait reproduire.
    expect(fn () => app(TicketTokenService::class)->issue($this->ticket))
        ->toThrow(RuntimeException::class);
});

it('reste assez court pour un QR Code lisible', function () {
    // Chaque caractere ajoute des modules au QR, donc de la difficulte a
    // scanner dans la lumiere d'une entree de concert.
    expect(strlen($this->ticket->qrToken()))->toBeLessThan(64);
});
