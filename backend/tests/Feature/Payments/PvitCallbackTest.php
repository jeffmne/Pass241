<?php

use App\Enums\BallotStatus;
use App\Enums\PaymentProvider;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\PaymentWebhook;
use App\Models\Poll;
use App\Models\PollCategory;
use App\Services\Payments\Pvit\PvitProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Callback PVit (sections 22, 23, decision D-057).
 *
 * DEUX CHOSES SONT DEFENDUES ICI, ET LA PREMIERE EST LA PLUS IMPORTANTE.
 *
 * 1. PVIT NE SIGNE PAS SES NOTIFICATIONS. Le corps arrive en clair avec un
 *    « status: SUCCESS », et n'importe qui connaissant l'URL peut en forger un.
 *    Il ne doit donc JAMAIS confirmer un paiement a lui seul : l'etat est relu
 *    chez PVit par un appel sortant authentifie, et c'est cette reponse-la qui
 *    tranche.
 *
 * 2. L'ACCUSE DE RECEPTION EST CONTRACTUEL. PVit exige un echo dynamique de
 *    `transactionId` et `code` ; sans lui, il marque la notification en echec
 *    et refuse de valider l'integration, meme quand les paiements aboutissent.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Comite Miss Estuaire');

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    config([
        'payments.pvit.operation_account_code' => 'ACC_TEST',
        'payments.pvit.secret_password' => 'motdepasse',
        'payments.pvit.callback_url_code' => 'CBTEST',
        'payments.pvit.endpoints.renew_secret' => '/v2/SECRET/renew-secret',
        'payments.pvit.endpoints.payment' => '/v2/PAY/rest',
        'payments.pvit.endpoints.status' => '/STATUS/status',
    ]);

    // La cle secrete est mise en cache par le driver : on repart d'un cache
    // vide pour que chaque test controle ce que PVit repond.
    Cache::forget('pvit:secret:ACC_TEST');

    $poll = Poll::factory()->active()->create();
    $category = PollCategory::factory()->for($poll)->paid(500)->create();
    $candidate = Candidate::factory()->for($category, 'category')->create();

    $this->ballot = forOrganization($this->organization, function () use ($poll, $category, $candidate) {
        $ballot = new Ballot;
        $ballot->forceFill([
            'organization_id' => $poll->organization_id,
            'poll_id' => $poll->getKey(),
            'poll_category_id' => $category->getKey(),
            'candidate_id' => $candidate->getKey(),
            'reference' => 'VT-TEST-001',
            'quantity' => 2,
            'unit_price' => 500,
            'subtotal' => 1_000,
            'psp_fee_amount' => 35,
            'amount' => 1_035,
            'status' => BallotStatus::Pending,
            'provider' => PaymentProvider::Airtel,
            'payer_phone' => '074000000',
            'voter_hash' => str_repeat('a', 64),
            'expires_at' => now()->addMinutes(5),
        ])->save();

        return $ballot->refresh();
    });

    /** Le corps d'une notification PVit, tel qu'ils l'envoient. */
    $this->notification = fn (string $status = 'SUCCESS', int $code = 200) => [
        'transactionId' => 'PAY123456789',
        'merchantReferenceId' => 'VTTEST001',
        'status' => $status,
        'amount' => 1_000,
        'fees' => 25,
        'operatorFees' => 10,
        'totalAmount' => 1_035,
        'code' => $code,
        'operator' => 'AIRTEL_MONEY',
    ];

    /** Ce que PVit repondra a la VERIFICATION sortante. */
    $this->pvitRepond = function (string $status): void {
        Http::fake([
            '*renew-secret*' => Http::response(['secret' => 'sk_test_x', 'expires_in' => 3600]),
            '*status*' => Http::response([
                'status' => $status,
                'amount' => 1_000,
                'fees' => 25,
                'operatorFees' => 10,
                'totalAmount' => 1_035,
                'merchant_reference_id' => 'VTTEST001',
            ]),
        ]);
    };
});

/*
 * ---------------------------------------------------------------------------
 * L'accuse de reception
 * ---------------------------------------------------------------------------
 */

it('renvoie l\'accuse de reception au format exige par PVit', function () {
    ($this->pvitRepond)('SUCCESS');

    $this->postJson('/api/webhooks/payments/pvit', ($this->notification)())
        ->assertOk()
        ->assertJson(['transactionId' => 'PAY123456789', 'responseCode' => 200]);
});

it('renvoie le code RECU, jamais un 200 code en dur', function () {
    /*
     * La documentation PVit est explicite : reprendre la valeur transmise, qui
     * peut changer selon le contexte ou leurs evolutions. Un 200 fige passerait
     * les tests d'aujourd'hui et casserait la recette du jour ou ils enverront
     * autre chose.
     */
    ($this->pvitRepond)('SUCCESS');

    $this->postJson('/api/webhooks/payments/pvit', ($this->notification)('SUCCESS', 201))
        ->assertOk()
        ->assertJson(['responseCode' => 201]);
});

it('accuse reception meme d\'un message qu\'il ignore', function () {
    /*
     * Un message sans identifiant n'est pas exploitable, mais il a bien ete
     * RECU — c'est tout ce que l'accuse atteste. Le refuser ferait relivrer en
     * boucle un message qu'on ne traitera de toute facon jamais.
     */
    ($this->pvitRepond)('SUCCESS');

    $this->postJson('/api/webhooks/payments/pvit', ['status' => 'SUCCESS', 'code' => 200])
        ->assertStatus(202)
        ->assertJson(['responseCode' => 200]);
});

/*
 * ---------------------------------------------------------------------------
 * Le corps du callback n'est jamais une preuve
 * ---------------------------------------------------------------------------
 */

it('confirme le paiement quand PVit le confirme a la verification', function () {
    ($this->pvitRepond)('SUCCESS');

    $this->postJson('/api/webhooks/payments/pvit', ($this->notification)())->assertOk();

    expect($this->ballot->fresh()->status)->toBe(BallotStatus::Confirmed);
});

it('NE CONFIRME PAS un callback que la verification dement', function () {
    /*
     * LE TEST QUI JUSTIFIE TOUTE L'ARCHITECTURE DU CALLBACK.
     *
     * Le corps annonce SUCCESS — comme le ferait un faux forge par quelqu'un
     * qui connait l'URL, PVit ne signant rien. La verification sortante, elle,
     * repond PENDING. C'est elle qui fait foi.
     */
    ($this->pvitRepond)('PENDING');

    $this->postJson('/api/webhooks/payments/pvit', ($this->notification)('SUCCESS'))->assertOk();

    expect($this->ballot->fresh()->status)->toBe(BallotStatus::Pending);
});

it('ne confirme rien quand PVit est injoignable', function () {
    // On ne sait pas : la transaction reste en attente et sera reinterrogee.
    // Conclure a l'echec ferait perdre un paiement peut-etre abouti.
    Http::fake([
        '*renew-secret*' => Http::response(['secret' => 'sk_test_x', 'expires_in' => 3600]),
        '*status*' => Http::response([], 503),
    ]);

    $this->postJson('/api/webhooks/payments/pvit', ($this->notification)('SUCCESS'))->assertOk();

    expect($this->ballot->fresh()->status)->toBe(BallotStatus::Pending);
});

it('laisse en attente un statut ambigu, sans le confirmer ni le refuser', function () {
    /*
     * `AMBIGUOUS` est le statut que PVit emploie quand il ne sait pas conclure
     * lui-meme. Le prendre pour un succes crediterait des voix jamais payees ;
     * pour un echec, il rendrait la voix alors que l'argent est peut-etre parti.
     */
    ($this->pvitRepond)('AMBIGUOUS');

    $this->postJson('/api/webhooks/payments/pvit', ($this->notification)('SUCCESS'))->assertOk();

    expect($this->ballot->fresh()->status)->toBe(BallotStatus::Pending);
});

it('refuse le paiement quand PVit le refuse', function () {
    ($this->pvitRepond)('FAILED');

    $this->postJson('/api/webhooks/payments/pvit', ($this->notification)('FAILED'))->assertOk();

    expect($this->ballot->fresh()->status)->toBe(BallotStatus::Failed);
});

/*
 * ---------------------------------------------------------------------------
 * Idempotence (section 23)
 * ---------------------------------------------------------------------------
 */

it('ne compte qu\'une fois un callback relivre', function () {
    // Les operateurs relivrent en rafale apres une coupure. Le second message
    // porte la meme cle d'evenement, et la contrainte d'unicite l'arrete.
    ($this->pvitRepond)('SUCCESS');

    $this->postJson('/api/webhooks/payments/pvit', ($this->notification)())->assertOk();
    $this->postJson('/api/webhooks/payments/pvit', ($this->notification)())->assertOk();

    expect(PaymentWebhook::query()->where('provider', 'pvit')->count())->toBe(1)
        ->and($this->ballot->fresh()->status)->toBe(BallotStatus::Confirmed);
});

/*
 * ---------------------------------------------------------------------------
 * Ce que le driver echoue seul
 * ---------------------------------------------------------------------------
 */

it('construit l\'accuse depuis la requete, et non depuis nos donnees', function () {
    /*
     * Un accuse de reception atteste de CE QU'ON A RECU. Le construire depuis
     * nos propres valeurs le rendrait faux le jour ou notre lecture differerait
     * de leur envoi — exactement le cas que cet echo sert a detecter.
     */
    $requete = Request::create('/', 'POST', [
        'transactionId' => 'PAY999', 'code' => 418,
    ]);

    expect(app(PvitProvider::class)->callbackAcknowledgement($requete))
        ->toBe(['transactionId' => 'PAY999', 'responseCode' => 418]);
});

/*
 * ---------------------------------------------------------------------------
 * La verification de montant
 * ---------------------------------------------------------------------------
 */

it('refuse de confirmer quand PVit a enregistre un autre sous-total', function () {
    /*
     * PVit ne publie pas le total debite sur son API de statut — il facture en
     * deux composantes et n'en montre qu'une. La verification de montant
     * sautait donc entierement sur ce chemin, EN SILENCE : un paiement etait
     * confirme sans que rien ne soit compare.
     *
     * Le repli compare ce qui est comparable : le sous-total enregistre chez le
     * prestataire doit etre celui qu'on lui a envoye.
     */
    Http::fake([
        '*renew-secret*' => Http::response(['secret' => 'sk_test_x', 'expires_in' => 3600]),
        '*status*' => Http::response([
            'status' => 'SUCCESS',
            // 200 la ou le bulletin en vaut 1 000.
            'amount' => 200,
            'fees' => 5,
            'merchant_reference_id' => 'VTTEST001',
        ]),
    ]);

    $this->postJson('/api/webhooks/payments/pvit', ($this->notification)())->assertOk();

    expect($this->ballot->fresh()->status)->toBe(BallotStatus::Pending);

    expect(PaymentWebhook::query()->where('provider', 'pvit')->latest('id')->first()->outcome)
        ->toContain('Sous-total incoherent');
});

it('confirme quand le sous-total enregistre correspond au notre', function () {
    ($this->pvitRepond)('SUCCESS');

    $this->postJson('/api/webhooks/payments/pvit', ($this->notification)())->assertOk();

    expect($this->ballot->fresh()->status)->toBe(BallotStatus::Confirmed)
        ->and(PaymentWebhook::query()->where('provider', 'pvit')->latest('id')->first()->outcome)
        ->toBe('Paiement confirme.');
});

it('signale au journal quand aucun montant n\'etait verifiable', function () {
    /*
     * Un prestataire qui ne rend ni total ni sous-total nous fait confirmer
     * sans rien pouvoir comparer. Ce n'est pas une raison de refuser — l'argent
     * est parti — mais cela doit se VOIR : c'est le genre de trou qui ne se
     * remarque pas avant qu'il ne coute.
     */
    Http::fake([
        '*renew-secret*' => Http::response(['secret' => 'sk_test_x', 'expires_in' => 3600]),
        '*status*' => Http::response(['status' => 'SUCCESS', 'merchant_reference_id' => 'VTTEST001']),
    ]);

    $this->postJson('/api/webhooks/payments/pvit', ($this->notification)())->assertOk();

    expect($this->ballot->fresh()->status)->toBe(BallotStatus::Confirmed)
        ->and(PaymentWebhook::query()->where('provider', 'pvit')->latest('id')->first()->outcome)
        ->toContain('non verifiable');
});
