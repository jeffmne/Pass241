<?php

use App\Actions\Payments\ConfirmPayment;
use App\Enums\TicketStatus;
use App\Livewire\Public\MyTickets;
use App\Mail\TicketsIssued;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Ticketing\QrRenderer;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/*
 * Livraison du billet (sections 15.1, 15.2, 69).
 *
 * « L'email ne doit jamais etre le canal unique. » Ces tests verifient surtout
 * cela : le billet est consultable depuis la plateforme, avec ou sans adresse
 * email, sur l'appareil d'achat comme depuis un lien partage.
 */

beforeEach(function () {
    Mail::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->ticketType] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create([
            'name' => 'Concert Afro 2026',
            'slug' => 'concert-afro-2026',
        ]);

        return [$event, TicketType::factory()->for($event)->create([
            'name' => 'VIP', 'price' => 50_000, 'quantity' => 10,
        ])];
    });

    $this->buy = function (?string $email = null): Order {
        return forOrganization($this->organization, function () use ($email) {
            $order = Order::factory()->for($this->event)->create([
                'amount' => 50_000,
                'email' => $email,
                'first_name' => 'Awa',
                'last_name' => 'Ndong',
            ]);

            $order->items()->create([
                'ticket_type_id' => $this->ticketType->id,
                'ticket_type_name' => $this->ticketType->name,
                'quantity' => 1,
                'unit_price' => 50_000,
                'subtotal' => 50_000,
            ]);

            return $order->load('items');
        });
    };

    app(OrganizationContext::class)->forget();
});

it('affiche le billet sur un lien signe', function () {
    app(ConfirmPayment::class)->handle(($this->buy)());

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();

    $this->get($ticket->publicUrl())
        ->assertOk()
        ->assertSee('Concert Afro 2026', escape: false)
        ->assertSee($ticket->reference, escape: false)
        ->assertSee('Awa Ndong', escape: false)
        // Section 15.2 : le rappel de scan fait partie du billet.
        ->assertSee('Scannez ce QR Code', escape: false);
});

it('affiche l ecran de suivi de commande sans contexte d organisation', function () {
    // Regression : les relations imbriquees repassaient par la portee
    // d'organisation, l'evenement revenait null et la page plantait pour
    // l'acheteur — qui n'appartient a aucune organisation.
    $order = ($this->buy)();

    $this->get($order->statusUrl())
        ->assertOk()
        ->assertSee('Concert Afro 2026', escape: false)
        ->assertSee($order->reference, escape: false);
});

it('affiche les billets sur l ecran de suivi une fois le paiement confirme', function () {
    $order = ($this->buy)();
    app(ConfirmPayment::class)->handle($order);

    $this->get($order->statusUrl())
        ->assertOk()
        ->assertSee('Paiement confirme', escape: false)
        ->assertSee('Voir mon billet', escape: false);
});

it('refuse un lien de billet non signe', function () {
    app(ConfirmPayment::class)->handle(($this->buy)());

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();

    // Sans signature, connaitre la reference ne suffit pas : on ne doit pas
    // pouvoir parcourir les billets en devinant (decision D-004).
    $this->get('/billet/'.$ticket->reference)->assertForbidden();
});

it('refuse un lien signe pour un autre billet', function () {
    app(ConfirmPayment::class)->handle(($this->buy)());
    app(ConfirmPayment::class)->handle(($this->buy)());

    $tickets = Ticket::query()->acrossOrganizations()->get();

    // Signature valide, mais fabriquee pour un billet different.
    $forged = str_replace(
        $tickets[0]->reference,
        $tickets[1]->reference,
        $tickets[0]->publicUrl(),
    );

    $this->get($forged)->assertForbidden();
});

it('renvoie 404 sur une reference inconnue', function () {
    $this->get(URL::signedRoute('tickets.show', ['reference' => 'T-ZZZZ-9999']))
        ->assertNotFound();
});

it('n expose jamais le jeton en clair dans la page', function () {
    app(ConfirmPayment::class)->handle(($this->buy)());

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();
    $html = $this->get($ticket->publicUrl())->assertOk()->getContent();

    // Le jeton vit dans le dessin du QR Code, pas dans le texte de la page :
    // le laisser lisible permettrait de le recopier sans photographier l'ecran.
    expect($html)->not->toContain($ticket->qrToken());
});

it('ne propose plus le PDF au telechargement depuis la plateforme', function () {
    // Le bouton de la page billet produit une image, capturee sur le billet
    // affiche. Le PDF ne circule plus que par email : aucune route ne doit
    // subsister, sans quoi les deux versions recommenceraient a diverger.
    expect(Route::has('tickets.pdf'))->toBeFalse();

    app(ConfirmPayment::class)->handle(($this->buy)());

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();

    $this->get('/billet/'.$ticket->reference.'/pdf')->assertNotFound();
});

it('joint un PDF par billet a l email', function () {
    $order = ($this->buy)('awa@example.test');
    $order->items()->create([
        'ticket_type_id' => $this->ticketType->id,
        'ticket_type_name' => $this->ticketType->name,
        'quantity' => 1,
        'unit_price' => 50_000,
        'subtotal' => 50_000,
    ]);

    app(ConfirmPayment::class)->handle($order->fresh()->load('items'));

    Mail::assertSent(TicketsIssued::class, function (TicketsIssued $mail) {
        $names = collect($mail->attachments())->map(fn ($attachment) => $attachment->as);

        // Un fichier par billet : un document unique obligerait le porteur a
        // chercher sa page, et empecherait d'envoyer son billet a chacun.
        expect($names->filter(fn ($n) => str_starts_with($n, 'billet-')))->toHaveCount(2)
            // Le recu, lui, porte sur la commande entiere : un seul suffit
            // (decision D-029).
            ->and($names->filter(fn ($n) => str_starts_with($n, 'recu-')))->toHaveCount(1);

        return true;
    });
});

it('rend le QR en image dans le PDF et non en SVG', function () {
    // Regression : le moteur de rendu n'applique pas les dimensions CSS a un
    // SVG inline. Le QR sortait a sa taille intrinseque, debordait du cadre,
    // disparaissait du document et poussait le billet sur une seconde page —
    // un billet sans QR n'ouvre aucune porte.
    app(ConfirmPayment::class)->handle(($this->buy)());

    $ticket = Ticket::publicByReference(
        Ticket::query()->acrossOrganizations()->firstOrFail()->reference
    );

    $qr = app(QrRenderer::class)->pngDataUri($ticket->qrToken(), 600);

    expect($qr)->toStartWith('data:image/png;base64,');

    $html = view('pdf.ticket', [
        'ticket' => $ticket,
        'event' => $ticket->event,
        'item' => $ticket->orderItem,
        'qr' => $qr,
        'banner' => null,
        'siteHost' => 'pass241.ga',
        'orderReference' => $ticket->order?->reference,
        'tz' => config('app.display_timezone'),
    ])->render();

    expect($html)->toContain('<img src="data:image/png;base64,')
        ->and($html)->not->toContain('<svg');
});

it('imprime tout ce dont un agent a besoin sur le PDF', function () {
    app(ConfirmPayment::class)->handle(($this->buy)());

    $ticket = Ticket::publicByReference(
        Ticket::query()->acrossOrganizations()->firstOrFail()->reference
    );

    // Le PDF lui-meme est compresse : c'est la vue source qu'on verifie, ce
    // qui revient au meme et reste lisible en cas d'echec.
    $html = view('pdf.ticket', [
        'ticket' => $ticket,
        'event' => $ticket->event,
        'item' => $ticket->orderItem,
        'qr' => app(QrRenderer::class)->pngDataUri($ticket->qrToken(), 600),
        'banner' => null,
        'siteHost' => 'pass241.ga',
        'orderReference' => $ticket->order?->reference,
        'tz' => config('app.display_timezone'),
    ])->render();

    foreach ([
        'Billet officiel',            // la mention du modele
        'Concert Afro 2026',          // l'evenement
        'Awa Ndong',                  // le porteur
        $ticket->reference,           // le numero, dicte au support
        'Institut francais',          // le lieu
        'VIP',                        // la categorie
        'Billet valide',              // le statut, lisible d'un coup d'oeil
        'Un seul acces par billet',   // la regle de la section 19
        'Billet nominatif',           // la regle de la section 16
        'data:image/png;base64,',     // le QR Code
    ] as $expected) {
        expect($html)->toContain($expected);
    }
});

it('n invente jamais de rang ni de place sur le billet', function () {
    // Section 15.2 : ces puces ne sont autorisees que si l'evenement utilise
    // un plan de salle numerote, ce que la section 17 ne prevoit pas. Les
    // afficher indiquerait au porteur une place qui n'existe pas.
    app(ConfirmPayment::class)->handle(($this->buy)());

    $ticket = Ticket::publicByReference(
        Ticket::query()->acrossOrganizations()->firstOrFail()->reference
    );

    $html = view('pdf.ticket', [
        'ticket' => $ticket,
        'event' => $ticket->event,
        'item' => $ticket->orderItem,
        'qr' => 'data:image/png;base64,AAAA',
        'banner' => null,
        'siteHost' => 'pass241.ga',
        'orderReference' => $ticket->order?->reference,
        'tz' => config('app.display_timezone'),
    ])->render();

    expect($html)->not->toContain('Rang')
        ->and($html)->not->toContain('Place');
});

it('avertit sur le PDF qu un billet invalide ne permet pas l entree', function () {
    app(ConfirmPayment::class)->handle(($this->buy)());

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();

    forOrganization($this->organization, fn () => $ticket->forceFill([
        'status' => TicketStatus::Used,
        'used_at' => now(),
    ])->save());

    $ticket = Ticket::publicByReference(
        Ticket::query()->acrossOrganizations()->firstOrFail()->reference
    );

    $html = view('pdf.ticket', [
        'ticket' => $ticket,
        'event' => $ticket->event,
        'item' => $ticket->orderItem,
        'qr' => 'data:image/png;base64,AAAA',
        'banner' => null,
        'siteHost' => 'pass241.ga',
        'orderReference' => $ticket->order?->reference,
        'tz' => config('app.display_timezone'),
    ])->render();

    // L'avertissement est en haut de page : un agent ne doit pas avoir a le
    // chercher sur une feuille tendue a bout de bras.
    expect($html)->toContain('ne permet pas l\'entree');
});

it('signale sur le billet qu il a deja ete utilise', function () {
    app(ConfirmPayment::class)->handle(($this->buy)());

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();

    forOrganization($this->organization, fn () => $ticket->forceFill([
        'status' => TicketStatus::Used,
        'used_at' => now(),
    ])->save());

    // Section 19 : un billet utilise ne peut pas resservir. La page doit le
    // dire clairement, pas laisser croire que tout va bien.
    $this->get($ticket->fresh()->publicUrl())
        ->assertOk()
        ->assertSee('Deja utilise', escape: false)
        ->assertSee('ne permet plus l\'entree', escape: false);
});

/*
 * ---------------------------------------------------------------------------
 * Email de copie (section 69)
 * ---------------------------------------------------------------------------
 */

it('envoie une copie par email quand une adresse a ete renseignee', function () {
    app(ConfirmPayment::class)->handle(($this->buy)('awa@example.test'));

    Mail::assertSent(TicketsIssued::class, fn ($mail) => $mail->hasTo('awa@example.test'));
});

it('n envoie aucun email quand aucune adresse n a ete renseignee', function () {
    // Section 15.1 : un achat par mobile money ne doit jamais etre bloque par
    // l'absence d'email, et le billet est deja livre sans lui.
    app(ConfirmPayment::class)->handle(($this->buy)(null));

    Mail::assertNothingSent();

    expect(Ticket::query()->acrossOrganizations()->count())->toBe(1);
});

it('emet le billet meme si l email ne part pas', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Serveur de messagerie injoignable'));

    // L'email est une commodite : personne ne perd son billet parce qu'un
    // serveur de messagerie est tombe.
    $order = app(ConfirmPayment::class)->handle(($this->buy)('awa@example.test'));

    expect($order->isPaid())->toBeTrue()
        ->and(Ticket::query()->acrossOrganizations()->count())->toBe(1);
});

/*
 * ---------------------------------------------------------------------------
 * Espace « Mes billets » (section 15.1)
 * ---------------------------------------------------------------------------
 */

it('affiche un etat vide sur un appareil sans achat', function () {
    $this->get('/mes-billets')
        ->assertOk()
        ->assertSee('Aucun billet sur cet appareil', escape: false);
});

it('retrouve une commande par sa reference', function () {
    $order = ($this->buy)();
    app(ConfirmPayment::class)->handle($order);

    Livewire\Livewire::test(MyTickets::class)
        ->set('reference', $order->reference)
        ->call('findOrder')
        ->assertHasNoErrors();
});

it('ne revele pas qu une reference inconnue est inconnue', function () {
    Livewire\Livewire::test(MyTickets::class)
        ->set('reference', 'PS241-AAAA-111')
        ->call('findOrder')
        ->assertHasErrors(['reference']);
});
