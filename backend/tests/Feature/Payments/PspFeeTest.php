<?php

use App\Enums\PaymentProvider;
use App\Livewire\Public\Checkout;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Payments\PspFeeCalculator;
use App\Support\Money;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/*
 * Frais du prestataire de paiement (decision D-023).
 *
 * A la charge de l'acheteur, affiches separement avant le paiement. Ils ne
 * touchent ni la commission Pass241 ni le net de l'organisateur : un billet a
 * 10 000 FCFA rapporte toujours 500 a la plateforme et 9 500 a l'organisateur,
 * quel que soit le prestataire.
 */

beforeEach(function () {
    $this->fees = app(PspFeeCalculator::class);
});

it('calcule les frais en pourcentage du prix des billets', function () {
    config()->set('payments.fees.fake', ['basis_points' => 200, 'fixed' => 0]);

    $fee = $this->fees->feeFor(10_000, 'fake');

    expect($fee->amount)->toBe(200)
        ->and($this->fees->totalFor(10_000, 'fake'))->toBe(10_200);
});

it('ajoute la part fixe du bareme', function () {
    config()->set('payments.fees.fake', ['basis_points' => 200, 'fixed' => 100]);

    // La part fixe est ce qui rend un petit montant proportionnellement cher :
    // 300 francs sur un billet a 1 000, soit 30 %.
    expect($this->fees->feeFor(10_000, 'fake')->amount)->toBe(300)
        ->and($this->fees->feeFor(1_000, 'fake')->amount)->toBe(120);
});

it('arrondit les frais au franc superieur', function () {
    config()->set('payments.fees.fake', ['basis_points' => 175, 'fixed' => 0]);

    // 3 333 x 1,75 % = 58,3275. Arrondi au plus proche, ce serait 58, et il
    // manquerait un franc sur le compte marchand a chaque transaction — un
    // decouvert que quelqu'un devrait combler. L'arrondi va donc au superieur.
    expect($this->fees->feeFor(3_333, 'fake')->amount)->toBe(59);
});

it('ne facture aucun frais sur un montant nul', function () {
    config()->set('payments.fees.fake', ['basis_points' => 200, 'fixed' => 500]);

    // Une commande gratuite ne passe pas par le prestataire : il n'y a rien a
    // lui payer, part fixe comprise.
    expect($this->fees->feeFor(0, 'fake')->amount)->toBe(0)
        ->and($this->fees->totalFor(0, 'fake'))->toBe(0);
});

it('fige le bareme applique', function () {
    config()->set('payments.fees.fake', ['basis_points' => 250, 'fixed' => 50]);

    $fee = $this->fees->feeFor(10_000, 'fake');

    // Le bareme voyage avec le montant jusqu'a la commande, ou il est fige :
    // une transaction doit rester explicable apres un changement de
    // prestataire.
    expect($fee->basisPoints)->toBe(250)
        ->and($fee->fixed)->toBe(50)
        ->and($fee->amount)->toBe(300);
});

it('refuse d encaisser avec un prestataire dont le bareme est inconnu', function () {
    // Sans cette regle, brancher un vrai prestataire sans renseigner ses frais
    // les ferait absorber en silence par Pass241, transaction apres
    // transaction, sans que rien ne le signale.
    expect(fn () => $this->fees->feeFor(10_000, 'prestataire-non-configure'))
        ->toThrow(RuntimeException::class, 'Aucun bareme de frais configure');
});

it('n utilise jamais de flottant', function () {
    config()->set('payments.fees.fake', ['basis_points' => 250, 'fixed' => 0]);

    // Meme raison que pour la commission (decision D-003) : 2,5 % ne se
    // represente pas exactement en binaire, et l'erreur finirait par produire
    // un franc d'ecart sur certaines transactions.
    $fee = $this->fees->feeFor(999_999, 'fake');

    expect($fee->amount)->toBeInt()
        ->and($fee->amount)->toBe(25_000);
});

/*
 * ---------------------------------------------------------------------------
 * Integrite en base
 * ---------------------------------------------------------------------------
 */

it('refuse en base une commande dont l eclatement ne tombe pas juste', function () {
    [, $organization] = organizerWithOrganization('Association test');

    $event = forOrganization($organization, fn () => Event::factory()->published()->create());

    // Un total qui ne se reconcilie pas avec ses parts est une erreur
    // comptable qui passerait inapercue dans un rapport : la base la refuse,
    // quelle que soit la couche applicative qui l'a laissee passer.
    expect(fn () => forOrganization($organization, fn () => DB::transaction(
        fn () => Order::factory()->for($event)->create([
            'subtotal' => 10_000,
            'psp_fee_amount' => 200,
            'amount' => 99_999,
        ])
    )))->toThrow(QueryException::class);
});

it('affiche les frais au checkout avant le paiement', function () {
    config()->set('payments.fees.fake', ['basis_points' => 200, 'fixed' => 0]);

    [, $organization] = organizerWithOrganization('Association test');

    [$event] = forOrganization($organization, function () {
        $event = Event::factory()->published()->create();
        TicketType::factory()->for($event)->create([
            'name' => 'Standard', 'price' => 10_000, 'quantity' => 50,
        ]);

        return [$event->fresh()];
    });

    app(OrganizationContext::class)->forget();

    $ticketType = TicketType::query()->acrossOrganizations()->firstOrFail();

    // Section 80.5 : l'acheteur doit lire le montant reel AVANT de valider sur
    // son telephone. Le decouvrir sur son releve se lit comme une tromperie,
    // meme quand la somme est juste.
    Livewire\Livewire::test(Checkout::class, ['event' => $event])
        ->set('quantities.'.$ticketType->id, 2)
        ->assertSee('Frais de paiement')
        ->assertSee('Total a payer')
        // 2 x 10 000 de billets, plus 2 % de frais.
        ->assertSee(Money::format(20_000), escape: false)
        ->assertSee(Money::format(400), escape: false)
        ->assertSee(Money::format(20_400), escape: false);
});

/*
 * ---------------------------------------------------------------------------
 * Un bareme par operateur — PVit
 * ---------------------------------------------------------------------------
 */

it('applique le bareme de l\'operateur, et non celui du prestataire', function () {
    /*
     * PVit ne prend pas le meme taux selon l'operateur : 3,525 % sur Airtel
     * Money, 2,5 % sur Moov Money. Un bareme unique par prestataire —
     * l'hypothese d'origine de ce service — surfacturerait les uns ou
     * sous-facturerait les autres, et la sous-facturation se paie en decouvert
     * sur le compte marchand.
     *
     * Taux releves sur des CALLBACKS reels, seuls a porter les deux
     * composantes de frais. L'API `get-fees`, elle, annonce 3,5 % sur Airtel
     * et se trompe donc de 0,025 point — systematiquement dans le mauvais
     * sens.
     */
    expect($this->fees->feeFor(10_000, 'pvit', PaymentProvider::Airtel)->amount)->toBe(353)
        ->and($this->fees->feeFor(10_000, 'pvit', PaymentProvider::Moov)->amount)->toBe(250);
});

it('arrondit au franc superieur, le franc CFA n\'ayant pas de subdivision', function () {
    /*
     * PVit rend des frais fractionnaires : 17,5 F sur 500 F Airtel. L'arrondi
     * se fait au SUPERIEUR — un franc de frais en moins est un decouvert sur le
     * compte marchand, qu'il faudrait combler transaction apres transaction.
     */
    expect($this->fees->feeFor(500, 'pvit', PaymentProvider::Airtel)->amount)->toBe(18)
        ->and($this->fees->feeFor(1_500, 'pvit', PaymentProvider::Airtel)->amount)->toBe(53)
        ->and($this->fees->feeFor(1_500, 'pvit', PaymentProvider::Moov)->amount)->toBe(38);
});

it('ne facture JAMAIS moins que ce que PVit preleve reellement', function () {
    /*
     * Le test qui defend le compte marchand.
     *
     * Montants et frais reels releves sur des callbacks PVit le 2026-09-01 :
     * un manque, meme d'un demi-franc, se comble transaction apres
     * transaction. L'excedent, lui, est sans consequence — le payeur avance
     * quelques centimes de plus que le prestataire ne prend.
     */
    $reels = [
        [PaymentProvider::Airtel, 500, 17.625],
        [PaymentProvider::Airtel, 1_000, 35.25],
        [PaymentProvider::Airtel, 2_000, 70.5],
        [PaymentProvider::Airtel, 5_000, 176.25],
        [PaymentProvider::Airtel, 50_000, 1_762.5],
        [PaymentProvider::Moov, 1_000, 25.0],
        [PaymentProvider::Moov, 4_000, 100.0],
    ];

    foreach ($reels as [$operator, $subtotal, $preleve]) {
        expect($this->fees->feeFor($subtotal, 'pvit', $operator)->amount)
            ->toBeGreaterThanOrEqual((int) ceil($preleve));
    }
});

it('retient le bareme le plus cher quand l\'operateur n\'est pas encore choisi', function () {
    /*
     * Un ecran affiche le total avant que l'acheteur n'ait choisi son
     * operateur. Annoncer moins que ce qui sera debite est la seule erreur qui
     * se voit sur le releve du client — on retient donc le plus penalisant.
     */
    expect($this->fees->feeFor(10_000, 'pvit')->amount)->toBe(353);
});

it('fige le bareme employe sur la transaction', function () {
    // Sans cet instantane, une transaction devient inexplicable apres un
    // changement de tarif : on saurait ce qu'on a preleve, sans pouvoir dire
    // pourquoi.
    $fee = $this->fees->feeFor(10_000, 'pvit', PaymentProvider::Moov);

    expect($fee->basisPoints)->toBe(250)
        ->and($fee->fixed)->toBe(0);
});

it('refuse d\'encaisser avec un operateur sans bareme', function () {
    // Echec bruyant plutot qu'absorption silencieuse : c'est la regle de ce
    // service, et elle vaut aussi a l'echelon de l'operateur.
    $this->fees->feeFor(10_000, 'pvit', PaymentProvider::from('AIRTEL'));

    config(['payments.fees.pvit.operators' => ['MOOV' => ['basis_points' => 250, 'fixed' => 0]]]);

    $this->fees->feeFor(10_000, 'pvit', PaymentProvider::Airtel);
})->throws(RuntimeException::class);
