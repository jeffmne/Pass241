<?php

use App\Actions\Orders\ConfirmPayment;
use App\Enums\RoleName;
use App\Enums\ScanResult;
use App\Enums\TicketStatus;
use App\Livewire\Dashboard\Events\Entries;
use App\Models\AccessScan;
use App\Models\EntryPoint;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Ecran des entrees d'un evenement (sections 27.2, 30).
 *
 * Ce qui est verifie ici : que les compteurs disent la verite — y compris sur
 * les entrees accordees sans lecture du QR, qui sont precisement ce qu'on veut
 * pouvoir compter apres coup — et qu'aucun chemin de cet ecran ne permet de
 * corriger un controle.
 */

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->ticketType, $this->entryPoint] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create(['name' => 'Concert Afro 2026']);

        return [
            $event,
            TicketType::factory()->for($event)->create(['name' => 'VIP', 'price' => 10_000, 'quantity' => 100]),
            EntryPoint::create(['event_id' => $event->id, 'name' => 'Entree principale']),
        ];
    });

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $this->issue = function (int $quantity = 1, string $holder = 'Awa Ndong') {
        return forOrganization($this->organization, function () use ($quantity, $holder) {
            $subtotal = 10_000 * $quantity;
            [$prenom, $nom] = array_pad(explode(' ', $holder, 2), 2, '');

            $order = Order::factory()->for($this->event)->create([
                'subtotal' => $subtotal, 'amount' => $subtotal,
                'first_name' => $prenom, 'last_name' => $nom,
            ]);

            $order->items()->create([
                'ticket_type_id' => $this->ticketType->id, 'ticket_type_name' => 'VIP',
                'quantity' => $quantity, 'unit_price' => 10_000, 'subtotal' => $subtotal,
            ]);

            app(ConfirmPayment::class)->handle($order->load('items'));

            return Ticket::query()->acrossOrganizations()->where('order_id', $order->id)->get();
        });
    };

    $this->scan = function (array $attributes = []) {
        return forOrganization($this->organization, fn () => AccessScan::create($attributes + [
            'event_id' => $this->event->id,
            'result' => ScanResult::Accepted->value,
            'scanned_at' => now(),
        ]));
    };

    $this->screen = fn () => Livewire::test(Entries::class, ['event' => $this->event]);
});

/*
 * ---------------------------------------------------------------------------
 * Compteurs
 * ---------------------------------------------------------------------------
 */

it('compte les entrees et le taux de presence sur les billets attendus', function () {
    $tickets = ($this->issue)(4);

    ($this->scan)(['ticket_id' => $tickets[0]->id]);
    ($this->scan)(['ticket_id' => $tickets[1]->id]);

    $stats = ($this->screen)()->viewData('stats');

    expect($stats['admitted'])->toBe(2)
        ->and($stats['expected'])->toBe(4)
        ->and($stats['remaining'])->toBe(2)
        ->and($stats['attendanceRate'])->toBe(50.0);
});

it('exclut des billets attendus ceux qui ne se presenteront pas', function () {
    $tickets = ($this->issue)(2);

    forOrganization($this->organization, fn () => $tickets[0]->forceFill([
        'status' => TicketStatus::Refunded->value,
    ])->save());

    /*
     * Un billet rembourse ne franchira pas la porte. L'inclure afficherait un
     * taux de presence incapable d'atteindre 100 % — un chiffre faux est pire
     * qu'aucun chiffre.
     */
    expect(($this->screen)()->viewData('stats')['expected'])->toBe(1);
});

it('compte a part les entrees accordees sans lecture du QR', function () {
    $tickets = ($this->issue)(2);

    ($this->scan)(['ticket_id' => $tickets[0]->id]);
    ($this->scan)(['ticket_id' => $tickets[1]->id, 'manual' => true]);

    $stats = ($this->screen)()->viewData('stats');

    /*
     * C'est la raison d'etre de ce compteur : une admission a la reference et
     * un scan signe se ressemblent parfaitement sans lui, alors que l'un prouve
     * une signature et l'autre une reference lue a l'oeil (section 27.15).
     */
    expect($stats['admitted'])->toBe(2)
        ->and($stats['manual'])->toBe(1);
});

it('separe les refus des entrees', function () {
    $tickets = ($this->issue)(1);

    ($this->scan)(['ticket_id' => $tickets[0]->id]);
    ($this->scan)(['result' => ScanResult::Invalid->value, 'device_id' => 'device-B']);

    $stats = ($this->screen)()->viewData('stats');

    expect($stats['scanned'])->toBe(2)
        ->and($stats['admitted'])->toBe(1)
        ->and($stats['refused'])->toBe(1)
        ->and($stats['byResult'][ScanResult::Invalid->value])->toBe(1);
});

it('signale les double scans arbitres', function () {
    $tickets = ($this->issue)(1);

    $winner = ($this->scan)(['ticket_id' => $tickets[0]->id, 'device_id' => 'device-A']);

    ($this->scan)([
        'ticket_id' => $tickets[0]->id,
        'device_id' => 'device-B',
        'result' => ScanResult::AlreadyUsed->value,
        'scanned_at' => now()->addMinute(),
        'conflict_with' => $winner->id,
        // L'arbitrage repose sur l'horloge de l'appareil : un ecart anormal ne
        // bloque rien, mais doit se voir a l'ecran.
        'clock_offset_seconds' => -900,
    ]);

    ($this->screen)()
        ->assertSee('Double scan arbitre')
        ->assertSee('Horloge deregle');

    expect(($this->screen)()->viewData('stats')['conflicts'])->toBe(1);
});

it('explique l ecran vide avant le premier scan', function () {
    ($this->issue)(1);

    // Les compteurs a zero ne disent pas ce qu'on attend : l'ecran precise que
    // les entrees arriveront, y compris celles faites hors connexion.
    // Sans `escape: false`, l'apostrophe du libelle serait cherchee sous sa
    // forme encodee, absente du gabarit.
    ($this->screen)()->assertSee('Aucun controle pour l\'instant', escape: false);
});

/*
 * ---------------------------------------------------------------------------
 * Journal et filtres
 * ---------------------------------------------------------------------------
 */

it('range les controles a l heure du scan et non de la synchronisation', function () {
    $tickets = ($this->issue)(2);

    // Le premier scan est remonte en dernier, comme apres une coupure reseau.
    ($this->scan)(['ticket_id' => $tickets[0]->id, 'scanned_at' => now()->subHour()]);
    ($this->scan)(['ticket_id' => $tickets[1]->id, 'scanned_at' => now()]);

    $scans = ($this->screen)()->viewData('scans');

    expect($scans->first()->ticket_id)->toBe($tickets[1]->id);
});

it('filtre par porte, y compris sur celles qui n en ont pas', function () {
    $tickets = ($this->issue)(2);

    ($this->scan)(['ticket_id' => $tickets[0]->id, 'entry_point_id' => $this->entryPoint->id]);
    ($this->scan)(['ticket_id' => $tickets[1]->id]);

    $parPorte = ($this->screen)()->set('entryPoint', (string) $this->entryPoint->id)->viewData('scans');
    $sansPorte = ($this->screen)()->set('entryPoint', 'none')->viewData('scans');

    /*
     * Le point d'entree est facultatif (decision D-034) : un evenement a porte
     * unique n'en declare aucune, et ces controles doivent rester
     * retrouvables.
     */
    expect($parPorte)->toHaveCount(1)
        ->and($parPorte->first()->ticket_id)->toBe($tickets[0]->id)
        ->and($sansPorte)->toHaveCount(1)
        ->and($sansPorte->first()->ticket_id)->toBe($tickets[1]->id);
});

it('filtre sur les seules entrees accordees a la reference', function () {
    $tickets = ($this->issue)(2);

    ($this->scan)(['ticket_id' => $tickets[0]->id]);
    ($this->scan)(['ticket_id' => $tickets[1]->id, 'manual' => true]);

    $scans = ($this->screen)()->set('result', 'manual')->viewData('scans');

    expect($scans)->toHaveCount(1)
        ->and($scans->first()->ticket_id)->toBe($tickets[1]->id);
});

it('retrouve un controle par le nom du porteur ou la reference', function () {
    $cherche = ($this->issue)(1, 'Marie Nzeng')->first();
    $autre = ($this->issue)(1, 'Paul Obame')->first();

    ($this->scan)(['ticket_id' => $cherche->id]);
    ($this->scan)(['ticket_id' => $autre->id, 'device_id' => 'device-B']);

    $parNom = ($this->screen)()->set('search', 'nzeng')->viewData('scans');
    $parReference = ($this->screen)()->set('search', $autre->reference)->viewData('scans');

    expect($parNom)->toHaveCount(1)
        ->and($parNom->first()->ticket_id)->toBe($cherche->id)
        ->and($parReference)->toHaveCount(1)
        ->and($parReference->first()->ticket_id)->toBe($autre->id);
});

it('neutralise les jokers de recherche', function () {
    $tickets = ($this->issue)(2);

    ($this->scan)(['ticket_id' => $tickets[0]->id]);

    // Sans neutralisation, « % » rendrait la liste entiere et donnerait
    // l'illusion d'une recherche qui trouve tout.
    expect(($this->screen)()->set('search', '%')->viewData('scans'))->toHaveCount(0);
});

/*
 * ---------------------------------------------------------------------------
 * Isolation et permissions
 * ---------------------------------------------------------------------------
 */

it('ne montre que les controles de cet evenement', function () {
    $tickets = ($this->issue)(1);
    ($this->scan)(['ticket_id' => $tickets[0]->id]);

    $autre = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create(['name' => 'Gala']);

        AccessScan::create([
            'event_id' => $event->id,
            'result' => ScanResult::Accepted->value,
            'scanned_at' => now(),
            'device_id' => 'device-gala',
        ]);

        return $event;
    });

    expect(($this->screen)()->viewData('scans'))->toHaveCount(1)
        ->and(Livewire::test(Entries::class, ['event' => $autre])->viewData('stats')['scanned'])->toBe(1);
});

it('ferme l ecran a qui ne peut pas consulter les participants', function () {
    $agent = User::factory()->create();

    forOrganization($this->organization, fn () => $this->organization->members()->create([
        'user_id' => $agent->id, 'joined_at' => now(),
    ]));

    actAsOrganization($this->organization);
    $agent->assignRole(RoleName::AccessAgent->value);

    $this->actingAs($agent);

    /*
     * Cet ecran nomme des personnes : c'est `attendees.view` qui le garde, pas
     * le droit de gerer les portes. Un agent scanne, il ne lit pas la liste de
     * ceux qui sont entres.
     */
    $this->get(route('events.entries', $this->event))->assertForbidden();
});

it('n expose aucune methode de correction', function () {
    $ecriture = collect(get_class_methods(Entries::class))
        ->filter(fn (string $method) => (bool) preg_match(
            '/^(save|update|delete|destroy|remove|edit|correct|fix)/i', $method
        ));

    /*
     * Le journal des scans est en ajout seul, protege par declencheur
     * (section 68). Un organisateur qui pourrait effacer une entree pourrait
     * nier une presence : l'ecran ne doit offrir aucun chemin d'ecriture.
     */
    expect($ecriture)->toBeEmpty();
});
