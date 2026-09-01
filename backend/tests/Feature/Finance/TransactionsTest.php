<?php

use App\Enums\AuditAction;
use App\Enums\CommissionType;
use App\Enums\PaymentProvider;
use App\Enums\RoleName;
use App\Livewire\Dashboard\Finances\Transactions as TransactionsScreen;
use App\Livewire\Dashboard\Refunds;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Candidate;
use App\Models\Commission;
use App\Models\Donation;
use App\Models\Event;
use App\Models\Order;
use App\Models\Poll;
use App\Models\PollCategory;
use App\Services\Commission\CommissionCalculator;
use App\Services\Finance\TransactionLedger;
use App\Support\Money;
use Livewire\Livewire;

/*
 * Le grand livre des transactions de l'organisateur (sections 56, 59).
 *
 * CE FICHIER DEFEND UNE SEULE IDEE, DECLINEE : le total affiche est le bon,
 * remboursements deduits.
 *
 * La table `commissions` est en ajout seul. Un remboursement n'y corrige donc
 * pas la ligne d'origine, il en ajoute une — POSITIVE, parce qu'elle decrit un
 * mouvement de retour. Un `SUM` naif compte alors l'encaissement puis le
 * remboursement comme deux recettes, et double le chiffre d'affaires. C'est une
 * erreur silencieuse : elle ne casse rien, elle ment.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->commissions = fn () => app(CommissionCalculator::class);

    [$this->order, $this->donation] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create(['name' => 'Concert Afro 2026']);

        $order = Order::factory()->for($event)->paid()->create([
            'reference' => 'CMD-TEST-0001',
            'subtotal' => 10_000, 'amount' => 10_000,
        ]);

        $campaign = Campaign::factory()->published()->create(['name' => 'Operation cartables']);

        $donation = Donation::factory()->for($campaign)->confirmed()->create([
            'reference' => 'DON-TEST-0001',
            'subtotal' => 20_000, 'amount' => 20_000,
        ]);

        return [$order, $donation];
    });

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    /*
     * Les commissions sont posees par le calculateur, pas par une factory : le
     * taux, l'arrondi et le net doivent etre ceux de l'application. Un montage
     * qui « ressemble » a une commission testerait le montage.
     */
    $this->ticketing = ($this->commissions)()->record(
        $this->order, $this->organization, 10_000, CommissionType::Ticketing,
    );

    ($this->commissions)()->record(
        $this->donation, $this->organization, 20_000, CommissionType::Donation,
    );

    $this->ledger = fn () => app(TransactionLedger::class);

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
 * Les totaux
 * ---------------------------------------------------------------------------
 */

it('additionne les trois montants de la periode', function () {
    $totals = ($this->ledger)()->totals(period: 'tout');

    // 5 % sur la billetterie, 2,5 % sur les collectes (section 21.2).
    expect($totals['gross'])->toBe(30_000)
        ->and($totals['commission'])->toBe(500 + 500)
        ->and($totals['net'])->toBe(9_500 + 19_500)
        ->and($totals['transactions'])->toBe(2)
        ->and($totals['refunded'])->toBe(0);
});

it('deduit les remboursements au lieu de les additionner', function () {
    /*
     * LE TEST QUI JUSTIFIE TOUT CE SERVICE.
     *
     * Sans la regle de signe, ce remboursement de 4 000 F ferait monter le brut
     * a 34 000 au lieu de le faire descendre a 26 000 — et l'organisateur
     * lirait un chiffre d'affaires superieur a ce qu'il a reellement encaisse,
     * exactement le jour ou il vient verifier pourquoi il a recu moins.
     */
    ($this->commissions)()->recordRefund($this->ticketing, 4_000);

    $totals = ($this->ledger)()->totals(period: 'tout');

    expect($totals['gross'])->toBe(26_000)
        ->and($totals['commission'])->toBe(800)
        ->and($totals['net'])->toBe(25_200)
        // Un remboursement n'est pas une vente de plus.
        ->and($totals['transactions'])->toBe(2)
        ->and($totals['refunded'])->toBe(4_000);
});

it('separe les modules quand on filtre', function () {
    $ticketing = ($this->ledger)()->totals(CommissionType::Ticketing, 'tout');
    $donations = ($this->ledger)()->totals(CommissionType::Donation, 'tout');

    expect($ticketing['gross'])->toBe(10_000)
        ->and($ticketing['transactions'])->toBe(1)
        ->and($donations['gross'])->toBe(20_000)
        ->and($donations['transactions'])->toBe(1);
});

it('reunit les trois modules dans un seul livre', function () {
    /*
     * La promesse de l'ecran : un organisateur qui vend des billets, recoit des
     * dons et fait payer des votes lit son total a un seul endroit. Le bulletin
     * passe par le vrai chemin de paiement, qui pose lui-meme sa commission.
     */
    $ballot = forOrganization($this->organization, function () {
        $poll = Poll::factory()->active()->create(['name' => 'Miss Estuaire']);

        $category = PollCategory::factory()->for($poll)->paid(500)->unlimited()->create();
        $candidate = Candidate::factory()->for($category, 'category')->create(['name' => 'Awa Ndong']);

        $ballot = app(\App\Actions\Voting\CreatePaidBallot::class)->handle(
            $candidate, 4, PaymentProvider::Airtel, '074000000',
            Illuminate\Http\Request::create('/v', 'POST', server: ['REMOTE_ADDR' => '41.158.1.1']),
        );

        app(\App\Actions\Payments\ConfirmPayment::class)->handle($ballot, 'REF-VOTE-1');

        return $ballot;
    });

    $all = ($this->ledger)()->totals(period: 'tout');
    $voting = ($this->ledger)()->totals(CommissionType::Voting, 'tout');

    expect($voting['gross'])->toBe(2_000)
        ->and($voting['transactions'])->toBe(1)
        // 10 000 + 20 000 + 2 000, sans que rien n'ait eu a etre additionne a
        // la main entre trois ecrans.
        ->and($all['gross'])->toBe(32_000)
        ->and($all['transactions'])->toBe(3)
        ->and($ballot->fresh()->isPaymentConfirmed())->toBeTrue();
});

it('ecarte de la periode courte ce qui date d\'avant', function () {
    $ancienne = $this->travelTo(now()->subDays(60), fn () => ($this->commissions)()->record(
        forOrganization($this->organization, fn () => Order::factory()
            ->for(Event::factory()->published()->create())
            ->paid()
            ->create(['reference' => 'CMD-VIEILLE', 'subtotal' => 50_000, 'amount' => 50_000])),
        $this->organization,
        50_000,
        CommissionType::Ticketing,
    ));

    expect($ancienne->created_at->isBefore(now()->subDays(31)))->toBeTrue()
        ->and(($this->ledger)()->totals(period: '30j')['gross'])->toBe(30_000)
        ->and(($this->ledger)()->totals(period: 'tout')['gross'])->toBe(80_000);
});

it('ne voit jamais les transactions d\'une autre organisation', function () {
    /*
     * Regle 80.4. Une fuite sur cet ecran serait une fuite de chiffre
     * d'affaires — la donnee qu'un concurrent paierait pour lire.
     */
    [, $autre] = organizerWithOrganization('Autre association');

    forOrganization($autre, function () use ($autre) {
        $order = Order::factory()
            ->for(Event::factory()->published()->create())
            ->paid()
            ->create(['reference' => 'CMD-AUTRE', 'subtotal' => 900_000, 'amount' => 900_000]);

        app(CommissionCalculator::class)->record($order, $autre, 900_000, CommissionType::Ticketing);
    });

    actAsOrganization($this->organization);

    expect(($this->ledger)()->totals(period: 'tout')['gross'])->toBe(30_000);
});

/*
 * ---------------------------------------------------------------------------
 * L'ecran
 * ---------------------------------------------------------------------------
 */

it('affiche le livre et ses totaux', function () {
    Livewire::test(TransactionsScreen::class)
        ->set('period', 'tout')
        ->assertSee('CMD-TEST-0001')
        ->assertSee('DON-TEST-0001')
        ->assertSee('Billetterie')
        ->assertSee('Collecte de fonds');
});

it('repond sur son adresse, mise en page comprise', function () {
    /*
     * Une vraie requete HTTP en plus du test Livewire, et ce n'est pas
     * redondant : `Livewire::test` monte le composant seul, sans la mise en
     * page, sans la navigation ni la sous-navigation. Une erreur dans l'une des
     * deux ne se verrait qu'ici — c'est deja arrive sur l'ecran des resultats
     * d'un scrutin, ou les tests passaient pendant que la page rendait 500.
     */
    $this->get(route('finances.transactions'))
        ->assertOk()
        ->assertSee('CMD-TEST-0001')
        // La sous-navigation des finances, rendue par la mise en page.
        ->assertSee('Remboursements');
});

it('annonce un remboursement au lieu de le fondre dans le total', function () {
    ($this->commissions)()->recordRefund($this->ticketing, 4_000);

    Livewire::test(TransactionsScreen::class)
        ->set('period', 'tout')
        // La ligne d'ajustement est visible ET signee : le montant seul aurait
        // laisse croire a un second encaissement.
        ->assertSee('Remboursement')
        // Par `Money` et non par une chaine ecrite a la main : le separateur
        // des milliers est une espace insecable, invisible dans un test.
        ->assertSee('-'.Money::format(4_000, false));
});

it('retombe sur ses pieds quand l\'URL porte n\'importe quoi', function () {
    // Les deux filtres viennent de l'adresse : un lien mal recopie doit
    // afficher le livre, pas une erreur 500.
    Livewire::test(TransactionsScreen::class, ['module' => 'CASHLESS', 'period' => 'depuis-toujours'])
        ->assertOk()
        ->assertSee('CMD-TEST-0001');
});

it('refuse l\'ecran a qui n\'a pas acces aux finances', function () {
    $coOrganisateur = addMemberTo($this->organization, RoleName::CoOrganizer);

    $this->actingAs($coOrganisateur);
    actAsOrganization($this->organization);

    Livewire::test(TransactionsScreen::class)->assertForbidden();
});

it('refuse aussi l\'ecran des remboursements a qui n\'a pas acces aux finances', function () {
    /*
     * L'ecran des remboursements ne verifiait que le droit de TRANCHER, jamais
     * celui de REGARDER. Le menu masquait l'entree, mais l'adresse restait
     * atteignable : un co-organisateur y lisait des montants et les numeros de
     * telephone des acheteurs (section 10).
     */
    $coOrganisateur = addMemberTo($this->organization, RoleName::CoOrganizer);

    $this->actingAs($coOrganisateur);
    actAsOrganization($this->organization);

    Livewire::test(Refunds::class)->assertForbidden();
});

it('n\'offre aucun chemin d\'ecriture', function () {
    /*
     * Regle 80.1.6 : l'organisateur ne corrige jamais une ligne d'argent
     * confirmee. Le test porte sur la classe elle-meme plutot que sur son
     * rendu — un bouton peut etre retire d'une vue, une methode publique reste
     * appelable depuis le navigateur.
     */
    $screen = new ReflectionClass(TransactionsScreen::class);

    /*
     * Les methodes venues d'un trait sont ecartees : PHP les aplatit dans la
     * classe, `getDeclaringClass()` les lui attribue donc, et la pagination de
     * Livewire ferait echouer ce test sans rien dire du sujet. Recursif, parce
     * qu'un trait peut lui-meme en utiliser d'autres.
     */
    $fromTraits = function (ReflectionClass $class) use (&$fromTraits): array {
        return collect($class->getTraits())
            ->flatMap(fn (ReflectionClass $trait) => array_merge(
                array_map(fn (ReflectionMethod $m) => $m->getName(), $trait->getMethods()),
                $fromTraits($trait),
            ))
            ->all();
    };

    $inherited = $fromTraits($screen);

    $actions = collect($screen->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method) => $method->getDeclaringClass()->getName() === TransactionsScreen::class)
        ->map(fn (ReflectionMethod $method) => $method->getName())
        ->reject(fn (string $name) => in_array($name, $inherited, true));

    expect($actions->all())
        ->toEqualCanonicalizing(['mount', 'updatingModule', 'updatingPeriod', 'render']);
});

/*
 * ---------------------------------------------------------------------------
 * Le fichier
 * ---------------------------------------------------------------------------
 */

it('exporte le livre avec ses trois montants', function () {
    $csv = ($this->csv)(route('exports.transactions', ['format' => 'csv', 'period' => 'tout']));

    expect($csv)->toContain('Montant brut')
        ->and($csv)->toContain('Commission')
        ->and($csv)->toContain('CMD-TEST-0001')
        ->and($csv)->toContain('DON-TEST-0001');
});

it('sort les remboursements en negatif, pour que la somme d\'une colonne soit juste', function () {
    /*
     * Quelqu'un va selectionner la colonne « Net » dans un tableur et lire le
     * total en bas de son ecran. Ce total doit etre le bon sans qu'il ait a
     * savoir qu'il y avait quelque chose a soustraire.
     */
    ($this->commissions)()->recordRefund($this->ticketing, 4_000);

    $csv = ($this->csv)(route('exports.transactions', ['format' => 'csv', 'period' => 'tout']));

    expect($csv)->toContain('Remboursement')
        ->and($csv)->toContain('-4000')
        ->and($csv)->toContain('-3800');
});

it('applique au fichier les filtres de l\'ecran', function () {
    $csv = ($this->csv)(route('exports.transactions', [
        'format' => 'csv', 'module' => CommissionType::Donation->value, 'period' => 'tout',
    ]));

    expect($csv)->toContain('DON-TEST-0001')
        ->and($csv)->not->toContain('CMD-TEST-0001');
});

it('journalise le telechargement du livre', function () {
    ($this->csv)(route('exports.transactions', ['format' => 'csv', 'period' => 'tout']));

    $log = AuditLog::action(AuditAction::ExportGenerated)->latest('id')->firstOrFail();

    expect($log->user_id)->toBe($this->owner->id)
        ->and($log->organization_id)->toBe($this->organization->id)
        ->and($log->metadata['export'])->toBe('Transactions')
        // Les filtres sont journalises avec le fichier : sans eux, la trace
        // dirait qu'un export de transactions est parti, sans dire lesquelles.
        ->and($log->metadata['period'])->toBe('tout')
        ->and($log->metadata['module'])->toBe('all');
});

it('refuse le fichier a qui n\'a pas acces aux finances', function () {
    $coOrganisateur = addMemberTo($this->organization, RoleName::CoOrganizer);

    $this->actingAs($coOrganisateur);

    $this->get(route('exports.transactions', ['format' => 'csv']))->assertForbidden();

    expect(Commission::query()->count())->toBe(2);
});
