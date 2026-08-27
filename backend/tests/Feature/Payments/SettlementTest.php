<?php

use App\Actions\Payments\ConfirmPayment;
use App\Actions\Organizations\SetPayoutDestination;
use App\Actions\Settlements\SettleFunds;
use App\Enums\AuditAction;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PayoutState;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\TicketType;
use App\Notifications\SettlementSent;
use App\Services\Payments\PaymentCapabilities;
use App\Services\Payments\PaymentManager;
use App\Services\Settlement\SettlementLedger;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * Reversement du net a l'organisateur (section 21.2, decision D-026).
 *
 * C'est ici que de l'argent SORT. Une erreur ne se rattrape pas : un virement
 * mobile money ne se rappelle pas. Les tests portent donc moins sur le chemin
 * nominal que sur tout ce qui doit empecher un second virement.
 */

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    // Un evenement DEJA TERMINE : le reversement n'intervient qu'apres
    // (section 24.1 ferme les remboursements 48h avant).
    [$this->event, $this->ticketType] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create([
            'name' => 'Concert termine',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDays(2),
        ]);

        return [$event, TicketType::factory()->for($event)->create([
            'name' => 'Standard', 'price' => 10_000, 'quantity' => 100,
        ])];
    });

    app(SetPayoutDestination::class)->handle(
        $this->organization, '074000000', PaymentProvider::Airtel, $this->owner,
    );

    $this->organization->refresh();

    // Vend et confirme `n` billets.
    $this->sell = function (int $quantity = 1): Order {
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
 * Calcul du net
 * ---------------------------------------------------------------------------
 */

it('reverse le net des commissions et jamais les frais du prestataire', function () {
    ($this->sell)(2);

    $settlement = app(SettleFunds::class)->handle($this->event);

    // 2 billets a 10 000 = 20 000 de brut, 5 % = 1 000 de commission.
    // Les 400 de frais prestataire ont ete avances par l'acheteur : ils ne
    // transitent pas par l'organisateur (decision D-023).
    expect($settlement->gross_amount)->toBe(20_000)
        ->and($settlement->commission_amount)->toBe(1_000)
        ->and($settlement->net_amount)->toBe(19_000);
});

it('ne compte que les commandes reellement payees', function () {
    ($this->sell)(1);

    forOrganization($this->organization, function () {
        $order = Order::factory()->for($this->event)->create([
            'subtotal' => 50_000, 'amount' => 50_000, 'status' => OrderStatus::Failed,
        ]);
        $order->items()->create([
            'ticket_type_id' => $this->ticketType->id,
            'ticket_type_name' => 'Standard',
            'quantity' => 5, 'unit_price' => 10_000, 'subtotal' => 50_000,
        ]);
    });

    $totals = app(SettlementLedger::class)->totalsFor($this->event);

    // Une commande en echec n'a rien encaisse : la reverser creerait de
    // l'argent qui n'existe pas.
    // `transactions` et non `orders` : le grand livre ne sait plus s'il compte
    // des commandes ou des dons (decision D-041).
    expect($totals['gross'])->toBe(10_000)
        ->and($totals['transactions'])->toBe(1);
});

it('ne cree aucun reversement quand rien n a ete vendu', function () {
    expect(app(SettleFunds::class)->handle($this->event))->toBeNull()
        ->and(Settlement::query()->acrossOrganizations()->count())->toBe(0);
});

it('respecte le montant minimal de reversement', function () {
    config()->set('payments.payouts.minimum_amount', 50_000);

    ($this->sell)(1);

    // Sous le seuil, les frais fixes du prestataire mangeraient l'essentiel
    // du virement.
    expect(app(SettleFunds::class)->handle($this->event))->toBeNull();
});

/*
 * ---------------------------------------------------------------------------
 * Le reversement n'intervient qu'apres l'evenement
 * ---------------------------------------------------------------------------
 */

it('ne reverse rien avant la fin de l evenement', function () {
    forOrganization($this->organization, fn () => $this->event->forceFill([
        'starts_at' => now()->addDays(10),
        'ends_at' => now()->addDays(11),
    ])->save());

    ($this->sell)(1);

    // C'est tout l'interet du choix : la fenetre de remboursement se ferme 48h
    // AVANT l'evenement (section 24.1). Reverser apres garantit qu'aucun
    // remboursement n'exigera de recuperer des fonds deja partis.
    expect(app(SettleFunds::class)->handle($this->event->fresh()))->toBeNull();
});

it('programme le virement sans le liberer avant le delai', function () {
    config()->set('payments.payouts.delay_hours', 24);

    forOrganization($this->organization, fn () => $this->event->forceFill([
        'ends_at' => now()->subHours(2),
    ])->save());

    ($this->sell)(1);

    $settlement = app(SettleFunds::class)->handle($this->event->fresh()->load('organization'));

    /*
     * Le prestataire simule sait programmer un virement : l'ordre part des la
     * fin de l'evenement, mais les fonds ne sont liberes qu'a l'echeance et
     * restent d'ici la dans SES livres. C'est ce qui evite a Pass241 d'etre
     * depositaire des fonds (section 21.2, capacite `scheduledPayout`).
     *
     * Les 22 heures restantes absorbent les incidents du jour meme :
     * annulation sur place, salle fermee, reclamation a la sortie.
     */
    expect($settlement->status)->toBe(PayoutState::Scheduled)
        ->and($settlement->isSettled())->toBeFalse()
        ->and($settlement->scheduled_for->isFuture())->toBeTrue();
});

it('attend l echeance quand le prestataire ne sait pas programmer', function () {
    config()->set('payments.payouts.delay_hours', 24);

    // Prestataire sans virement programme : il faut attendre, et les fonds
    // dorment entre-temps sur le compte marchand de la plateforme. C'est
    // exactement ce que `holdsFunds()` signale.
    $ledger = new SettlementLedger(new class extends PaymentManager
    {
        public function capabilities(?string $name = null): PaymentCapabilities
        {
            return new PaymentCapabilities(payout: true, scheduledPayout: false);
        }
    });

    forOrganization($this->organization, fn () => $this->event->forceFill([
        'ends_at' => now()->subHours(2),
    ])->save());

    expect($ledger->isDue($this->event->fresh()))->toBeFalse()
        ->and($ledger->instructionDelayHours())->toBe(24);
});

/*
 * ---------------------------------------------------------------------------
 * Ce qui empeche un virement
 * ---------------------------------------------------------------------------
 */

it('ne reverse rien sans numero de destination', function () {
    $this->organization->forceFill([
        'payout_phone' => null,
        'payout_operator' => null,
    ])->save();

    ($this->sell)(1);

    expect(app(SettleFunds::class)->handle($this->event->fresh()->load('organization')))->toBeNull();
});

it('ne reverse rien a une organisation suspendue', function () {
    ($this->sell)(1);

    $this->organization->forceFill(['suspended_at' => now()])->save();

    expect(app(SettleFunds::class)->handle($this->event->fresh()->load('organization')))->toBeNull();
});

it('ne reverse rien a un compte refuse par le super admin', function () {
    ($this->sell)(1);

    $this->organization->forceFill(['kyc_status' => KycStatus::Rejected])->save();

    expect(app(SettleFunds::class)->handle($this->event->fresh()->load('organization')))->toBeNull();
});

/*
 * ---------------------------------------------------------------------------
 * Un seul virement, jamais deux
 * ---------------------------------------------------------------------------
 */

it('ne cree jamais deux reversements pour le meme evenement', function () {
    ($this->sell)(2);

    $first = app(SettleFunds::class)->handle($this->event);
    $second = app(SettleFunds::class)->handle($this->event);

    // Un virement rejoue envoie l'argent deux fois, et une somme envoyee en
    // double ne se recupere pas.
    expect($second->id)->toBe($first->id)
        ->and(Settlement::query()->acrossOrganizations()->count())->toBe(1);
});

it('ne renvoie jamais un ordre deja accepte par le prestataire', function () {
    ($this->sell)(2);

    $settlement = app(SettleFunds::class)->handle($this->event);
    $reference = $settlement->provider_reference;

    expect($settlement->isAccepted())->toBeTrue();

    app(SettleFunds::class)->handle($this->event);

    // Meme reference de transaction : aucun second ordre n'est parti.
    expect($settlement->fresh()->provider_reference)->toBe($reference);
});

it('refuse de reverser un montant modifie apres coup', function () {
    ($this->sell)(2);

    $settlement = app(SettleFunds::class)->handle($this->event);

    // Le montant vient d'une ligne de commission figee et n'est plus
    // modifiable : sans cela, une correction en base pourrait changer la somme
    // virée sans laisser de trace.
    expect(fn () => forOrganization(
        $this->organization,
        fn () => $settlement->update(['net_amount' => 999_999])
    ))->toThrow(RuntimeException::class, 'figes');
});

it('refuse de rediriger un reversement vers un autre numero', function () {
    ($this->sell)(2);

    $settlement = app(SettleFunds::class)->handle($this->event);

    expect(fn () => forOrganization(
        $this->organization,
        fn () => $settlement->update(['recipient_phone' => '066999999'])
    ))->toThrow(RuntimeException::class, 'figes');
});

it('refuse la suppression d un reversement', function () {
    ($this->sell)(2);

    $settlement = app(SettleFunds::class)->handle($this->event);

    expect(fn () => forOrganization($this->organization, fn () => $settlement->delete()))
        ->toThrow(RuntimeException::class, 'ne se supprime pas');
});

/*
 * ---------------------------------------------------------------------------
 * Instantane et tracabilite
 * ---------------------------------------------------------------------------
 */

it('fige le beneficiaire au moment du reversement', function () {
    ($this->sell)(2);

    $settlement = app(SettleFunds::class)->handle($this->event);

    app(SetPayoutDestination::class)->handle(
        $this->organization->fresh(), '062111111', PaymentProvider::Moov, $this->owner,
    );

    // Si le numero change le lendemain, ce reversement doit continuer a dire
    // vers ou l'argent est parti : c'est la trace qu'on cherche quand on
    // soupconne un detournement.
    expect($settlement->fresh()->recipient_phone)->toBe('074000000')
        ->and($settlement->fresh()->recipient_operator)->toBe(PaymentProvider::Airtel);
});

it('journalise la creation et l aboutissement du reversement', function () {
    ($this->sell)(2);

    app(SettleFunds::class)->handle($this->event);

    expect(AuditLog::action(AuditAction::SettlementCreated)->count())->toBe(1)
        ->and(AuditLog::action(AuditAction::SettlementSucceeded)->count())->toBe(1);
});

it('masque le numero du beneficiaire dans le journal', function () {
    ($this->sell)(2);

    app(SettleFunds::class)->handle($this->event);

    $log = AuditLog::action(AuditAction::SettlementCreated)->firstOrFail();

    // Le journal est consulte et exporte : il doit permettre de reconnaitre un
    // numero, pas de le recopier (section 52).
    expect($log->metadata['recipient'])->toEndWith('0000')
        ->and($log->metadata['recipient'])->not->toContain('074');
});

/*
 * ---------------------------------------------------------------------------
 * Commande planifiee
 * ---------------------------------------------------------------------------
 */

it('reverse les evenements termines depuis la commande', function () {
    ($this->sell)(3);

    $this->artisan('pass241:settle-events')
        ->assertSuccessful();

    $settlement = Settlement::query()->acrossOrganizations()->firstOrFail();

    expect($settlement->net_amount)->toBe(28_500)
        ->and($settlement->status)->toBe(PayoutState::Succeeded);
});

it('ne repasse pas sur un evenement deja reverse', function () {
    ($this->sell)(2);

    $this->artisan('pass241:settle-events')->assertSuccessful();
    $this->artisan('pass241:settle-events')->assertSuccessful();

    expect(Settlement::query()->acrossOrganizations()->count())->toBe(1);
});

it('ne reconcilie rien quand aucun reversement n est en attente', function () {
    $this->artisan('pass241:reconcile-settlements')
        ->expectsOutputToContain('Aucun reversement en attente')
        ->assertSuccessful();
});

it('previent l organisateur que son reversement est parti', function () {
    ($this->sell)(2);

    app(SettleFunds::class)->handle($this->event);

    // C'est la question la plus posee au support d'une billetterie : un
    // message suffit a l'eviter (section 69).
    Notification::assertSentTo(
        $this->organization,
        SettlementSent::class,
    );
});

it('ne previent pas quand rien n a ete reverse', function () {
    app(SettleFunds::class)->handle($this->event);

    Notification::assertNotSentTo(
        $this->organization,
        SettlementSent::class,
    );
});
