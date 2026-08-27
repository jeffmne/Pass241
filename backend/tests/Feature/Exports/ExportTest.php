<?php

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Support\Tenancy\OrganizationContext;

/*
 * Exports (section 59).
 *
 * Trois choses se verifient ici, et la deuxieme est celle qui protege les
 * donateurs : le fichier contient ce qu'il doit, il ne contient PAS ce qu'un
 * ecran refuserait de montrer, et chaque telechargement laisse une trace.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->campaign] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create(['name' => 'Concert Afro 2026']);

        $type = TicketType::factory()->for($event)->create([
            'name' => 'Standard', 'price' => 10_000, 'quantity' => 100,
        ]);

        $order = Order::factory()->for($event)->paid()->create([
            'first_name' => 'Marie', 'last_name' => 'Nzeng',
            'email' => 'marie@exemple.ga', 'payer_phone' => '074000001',
            'subtotal' => 10_000, 'amount' => 10_000,
        ]);

        $item = $order->items()->create([
            'ticket_type_id' => $type->id, 'ticket_type_name' => 'Standard',
            'quantity' => 1, 'unit_price' => 10_000, 'subtotal' => 10_000,
        ]);

        Ticket::create([
            'event_id' => $event->id, 'order_id' => $order->id,
            'order_item_id' => $item->id, 'ticket_type_id' => $type->id,
            'reference' => 'T-TEST-0001', 'holder_name' => 'Marie Nzeng',
        ]);

        $campaign = Campaign::factory()->published()->create(['name' => 'Operation cartables']);

        Donation::factory()->for($campaign)->confirmed()->create([
            'donor_name' => 'Paul Obame', 'payer_phone' => '074000002',
            'subtotal' => 25_000, 'amount' => 25_000,
        ]);

        Donation::factory()->for($campaign)->confirmed()->anonymous()->create([
            'donor_name' => 'Sylvie Mba', 'payer_phone' => '074000003',
            'subtotal' => 15_000, 'amount' => 15_000,
        ]);

        return [$event, $campaign];
    });

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $this->csv = function (string $url) {
        $response = $this->get($url);

        $response->assertOk();

        ob_start();
        $response->baseResponse->sendContent();

        return ob_get_clean();
    };
});

/*
 * ---------------------------------------------------------------------------
 * Ce que les fichiers contiennent
 * ---------------------------------------------------------------------------
 */

it('exporte les participants d un evenement', function () {
    $csv = ($this->csv)(route('exports.participants', [$this->event, 'csv']));

    expect($csv)->toContain('Reference billet')
        ->and($csv)->toContain('T-TEST-0001')
        ->and($csv)->toContain('Marie Nzeng')
        ->and($csv)->toContain('Standard');
});

it('exporte les commandes avec leurs trois montants', function () {
    $csv = ($this->csv)(route('exports.orders', [$this->event, 'csv']));

    // Decision D-023 : le total debite contient les frais du prestataire. Les
    // confondre ferait croire a l'organisateur qu'il a encaisse davantage.
    expect($csv)->toContain('Sous-total')
        ->and($csv)->toContain('Frais de paiement')
        ->and($csv)->toContain('Total debite');
});

it('exporte les collectes avec leur progression calculee', function () {
    $csv = ($this->csv)(route('exports.campaigns', 'csv'));

    // 40 000 collectes sur la campagne de test.
    expect($csv)->toContain('Operation cartables')
        ->and($csv)->toContain('40000');
});

it('produit un fichier Excel lisible', function () {
    $response = $this->get(route('exports.donations', [$this->campaign, 'xlsx']));

    $response->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    ob_start();
    $response->baseResponse->sendContent();
    $content = ob_get_clean();

    // Un XLSX est une archive ZIP : sa signature suffit a prouver que le flux
    // est bien forme, sans deballer le fichier.
    expect(substr($content, 0, 2))->toBe('PK');
});

/*
 * ---------------------------------------------------------------------------
 * Ce que les fichiers ne contiennent PAS (sections 41, 52)
 * ---------------------------------------------------------------------------
 */

it('n ecrit jamais le nom d un donateur anonyme dans le fichier', function () {
    $csv = ($this->csv)(route('exports.donations', [$this->campaign, 'csv']));

    /*
     * C'est la regle la plus importante de cet export. Un fichier se garde, se
     * transfere et s'ouvre dans un tableur : y livrer l'identite serait la
     * porte de sortie de la decision D-044, et pire qu'un affichage, parce que
     * ca survit a la session.
     */
    expect($csv)->toContain('Paul Obame')
        ->and($csv)->toContain('Donateur anonyme')
        ->and($csv)->not->toContain('Sylvie Mba');
});

it('n ecrit jamais le numero de telephone d un donateur', function () {
    $csv = ($this->csv)(route('exports.donations', [$this->campaign, 'csv']));

    // Un numero identifie aussi surement qu'un nom : l'exporter rendrait
    // l'anonymat cosmetique.
    expect($csv)->not->toContain('074000002')
        ->and($csv)->not->toContain('074000003');
});

/*
 * ---------------------------------------------------------------------------
 * Permissions (sections 10, 59)
 * ---------------------------------------------------------------------------
 */

it('refuse l export a qui n a pas le droit d exporter', function () {
    $member = addMemberTo($this->organization, RoleName::CoOrganizer);

    actAsOrganization($this->organization);
    $this->actingAs($member);

    /*
     * Le co-organisateur voit les participants a l'ecran (section 10) mais n'a
     * pas `exports.generate` : il ne peut pas en emporter le fichier. Sortir
     * des donnees est un geste a part — un fichier ne se reprend pas.
     */
    $this->get(route('exports.participants', [$this->event, 'csv']))->assertForbidden();
});

it('refuse un export financier a qui ne voit pas les finances', function () {
    $member = addMemberTo($this->organization, RoleName::CoOrganizer);

    actAsOrganization($this->organization);
    // On lui accorde le droit d'exporter, mais pas celui de voir les finances.
    $member->givePermissionTo(App\Enums\PermissionName::ExportsGenerate->value);
    $this->actingAs($member->fresh());

    $this->get(route('exports.participants', [$this->event, 'csv']))->assertOk();
    $this->get(route('exports.orders', [$this->event, 'csv']))->assertForbidden();
});

it('refuse un format inconnu', function () {
    $this->get('/exports/evenements/'.$this->event->slug.'/participants/pdf')->assertNotFound();
});

/*
 * ---------------------------------------------------------------------------
 * Isolation et journal
 * ---------------------------------------------------------------------------
 */

it('n exporte jamais les donnees d une autre organisation', function () {
    [$intruder, $other] = organizerWithOrganization('Autre association');

    app(OrganizationContext::class)->forget();

    // Regle 80.4 : l'isolation tient des la liaison de route, avant meme que
    // l'export ne s'execute.
    $this->actingAs($intruder)
        ->get(route('exports.participants', [$this->event, 'csv']))
        ->assertNotFound();
});

it('journalise chaque export', function () {
    ($this->csv)(route('exports.participants', [$this->event, 'csv']));

    $log = AuditLog::action(AuditAction::ExportGenerated)->firstOrFail();

    /*
     * Section 68 : c'est la seule trace qui restera le jour ou l'on cherchera
     * d'ou vient un fichier de coordonnees.
     */
    expect($log->user_id)->toBe($this->owner->id)
        ->and($log->organization_id)->toBe($this->organization->id)
        ->and($log->metadata['export'])->toBe('Participants')
        ->and($log->metadata['format'])->toBe('csv')
        ->and($log->ip_address)->not->toBeNull();
});

it('journalise meme si le telechargement est interrompu', function () {
    /*
     * La trace est ecrite AVANT l'envoi du flux : un export interrompu en cours
     * de route laisse quand meme sa trace — c'est meme le cas le plus
     * interessant a retrouver.
     */
    $this->get(route('exports.orders', [$this->event, 'csv']))->assertOk();

    expect(AuditLog::action(AuditAction::ExportGenerated)->count())->toBe(1);
});
