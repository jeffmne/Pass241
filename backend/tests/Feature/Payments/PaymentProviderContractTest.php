<?php

use App\Enums\PaymentProvider;
use App\Enums\PayoutState;
use App\Models\Order;
use App\Services\Payments\CollectionProvider;
use App\Services\Payments\FakeProvider;
use App\Services\Payments\Payable;
use App\Services\Payments\PaymentCapabilities;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\PaymentResult;
use App\Services\Payments\PayoutRequest;
use App\Services\Payments\SettlementProvider;
use Illuminate\Http\Request;

/*
 * Abstraction du prestataire de paiement (decision D-024, section 21).
 *
 * Deux faces distinctes : encaisser et reverser. Un agregateur peut savoir
 * prendre un paiement mobile money sans exposer la moindre API de virement
 * sortant — le code doit pouvoir le constater plutot que le supposer.
 */

beforeEach(function () {
    $this->payments = app(PaymentManager::class);
});

it('resout un prestataire d encaissement', function () {
    expect($this->payments->driver())->toBeInstanceOf(CollectionProvider::class)
        ->and($this->payments->driver()->name())->toBe('fake');
});

it('refuse un prestataire inconnu', function () {
    expect(fn () => $this->payments->driver('inexistant'))
        ->toThrow(InvalidArgumentException::class, 'Prestataire de paiement inconnu');
});

it('expose les capacites declarees par le driver', function () {
    $capabilities = $this->payments->capabilities();

    // Declarees par le driver et non lues en configuration : un prestataire ne
    // sait pas eclater un encaissement parce qu'un fichier l'affirme.
    expect($capabilities->payout)->toBeTrue()
        ->and($capabilities->scheduledPayout)->toBeTrue()
        ->and($capabilities->nativeSplit)->toBeFalse();
});

it('dit si la plateforme detiendra les fonds', function () {
    $capabilities = $this->payments->capabilities();

    // Sans eclatement natif mais avec virement programme, les fonds restent
    // chez le prestataire : Pass241 n'en est jamais depositaire (section 21.2).
    expect($capabilities->holdsFunds())->toBeFalse()
        ->and($capabilities->canSettle())->toBeTrue();
});

it('signale qu un prestataire sans virement programme fait detenir les fonds', function () {
    $capabilities = new PaymentCapabilities(
        nativeSplit: false,
        payout: true,
        scheduledPayout: false,
    );

    // Cas le plus courant, et le plus problematique : l'argent atterrit sur le
    // compte marchand Pass241 et y reste jusqu'a l'evenement. Cette methode
    // existe pour que ce soit visible plutot que subi.
    expect($capabilities->holdsFunds())->toBeTrue()
        ->and($capabilities->canSettle())->toBeTrue();
});

it('resout un prestataire de reversement quand il en existe un', function () {
    expect($this->payments->settlement())->toBeInstanceOf(SettlementProvider::class)
        ->and($this->payments->supportsSettlement())->toBeTrue();
});

it('echoue bruyamment quand le prestataire ne sait pas reverser', function () {
    // Un appelant qui demande a reverser doit s'arreter net. Rendre null
    // laisserait passer un appel silencieux, et un reversement qui n'a jamais
    // eu lieu ne se remarque que le jour ou l'organisateur telephone.
    $manager = new class extends PaymentManager
    {
        public function driver(?string $name = null): CollectionProvider
        {
            return new class implements CollectionProvider
            {
                public function name(): string
                {
                    return 'encaissement-seul';
                }

                public function capabilities(): PaymentCapabilities
                {
                    return new PaymentCapabilities;
                }

                public function initializePayment(Payable $payable): PaymentResult
                {
                    throw new RuntimeException('non utilise');
                }

                public function checkPaymentStatus(Payable $payable): PaymentResult
                {
                    throw new RuntimeException('non utilise');
                }

                public function processCallback(Request $request): ?PaymentResult
                {
                    return null;
                }

                public function refund(Payable $payable, int $amount, string $reason): PaymentResult
                {
                    throw new RuntimeException('non utilise');
                }
            };
        }
    };

    expect($manager->supportsSettlement())->toBeFalse()
        ->and(fn () => $manager->settlement())
        ->toThrow(RuntimeException::class, 'ne sait pas reverser');
});

/*
 * ---------------------------------------------------------------------------
 * Reversement
 * ---------------------------------------------------------------------------
 */

function payoutRequest(array $overrides = []): PayoutRequest
{
    return new PayoutRequest(
        recipientPhone: $overrides['phone'] ?? '074000000',
        recipientOperator: PaymentProvider::Airtel,
        amount: $overrides['amount'] ?? 47_500,
        reference: $overrides['reference'] ?? 'RV-0001',
        idempotencyKey: $overrides['key'] ?? 'cle-unique-1',
        releaseAt: $overrides['releaseAt'] ?? null,
    );
}

it('transmet un ordre de reversement immediat', function () {
    $result = app(FakeProvider::class)->settle(payoutRequest());

    expect($result->state)->toBe(PayoutState::Succeeded)
        ->and($result->providerReference)->toStartWith('FAKEPAY-')
        ->and($result->accepted())->toBeTrue();
});

it('programme un reversement date dans le futur', function () {
    $result = app(FakeProvider::class)->settle(payoutRequest([
        'releaseAt' => now()->addDays(3),
    ]));

    // Les fonds restent dans les livres du prestataire jusqu'a l'echeance :
    // c'est ce qui evite a Pass241 d'en etre depositaire (section 21.2).
    expect($result->state)->toBe(PayoutState::Scheduled)
        ->and($result->accepted())->toBeTrue()
        // Accepte n'est pas arrive : les confondre ferait annoncer a
        // l'organisateur un argent qu'il n'a pas encore.
        ->and($result->succeeded())->toBeFalse();
});

it('ne verse jamais deux fois pour la meme cle d idempotence', function () {
    $provider = app(FakeProvider::class);

    $first = $provider->settle(payoutRequest(['key' => 'cle-rejouee']));
    $second = $provider->settle(payoutRequest(['key' => 'cle-rejouee']));

    // Plus critique qu'a l'encaissement : un webhook rejoue cree un doublon
    // rattrapable, un virement rejoue envoie l'argent deux fois et ne se
    // recupere pas.
    expect($second->providerReference)->toBe($first->providerReference)
        ->and($second->raw['replayed'] ?? false)->toBeTrue();
});

it('rapporte un etat inconnu et non un echec sur une reference ignoree', function () {
    $result = app(FakeProvider::class)->checkPayout('FAKEPAY-INEXISTANT');

    // Conclure a l'echec conduirait a rejouer l'ordre, donc a payer deux fois.
    expect($result->state)->toBe(PayoutState::Unknown)
        ->and($result->failed())->toBeFalse();
});

it('retrouve le titulaire d un numero', function () {
    $name = app(FakeProvider::class)->lookupRecipientName('074123456', PaymentProvider::Airtel);

    // Sert a afficher « vous allez recevoir sur le 074XXXXXX au nom de … » :
    // un virement mobile money ne se rappelle pas, une faute de frappe est
    // definitive.
    expect($name)->toContain('3456');
});
