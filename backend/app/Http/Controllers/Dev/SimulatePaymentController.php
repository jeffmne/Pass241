<?php

namespace App\Http\Controllers\Dev;

use App\Enums\PaymentState;
use App\Http\Controllers\Controller;
use App\Services\Payments\FakeProvider;
use App\Services\Payments\PayableResolver;
use App\Services\Payments\PaymentManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Simulation d'une reponse d'operateur (decision D-002).
 *
 * Developpement uniquement. Aucun acces Airtel Money ni Moov Money n'etant
 * disponible, cet ecran reproduit les trois issues de la section 21.1 : le
 * client valide, le client refuse, ou rien ne revient jamais.
 *
 * Point important : la simulation passe par le VRAI webhook, signe comme le
 * sera celui d'un operateur. Le chemin exerce est donc exactement celui de la
 * production — signature, idempotence, verification de montant, confirmation.
 * Un raccourci qui appellerait ConfirmPayment directement ne testerait rien de
 * ce qui compte.
 */
class SimulatePaymentController extends Controller
{
    public function __invoke(
        Request $request,
        string $reference,
        PaymentManager $payments,
        PayableResolver $payables,
    ): RedirectResponse {
        abort_unless($payments->isFake() && app()->environment('local'), 404);

        // Une commande de billets ou un don, indifferemment : depuis la
        // decision D-041, la simulation ne sait plus ce qu'elle paie — comme le
        // vrai webhook.
        $payable = $payables->byReference($reference) ?? abort(404);

        $outcome = $request->string('outcome')->toString();

        // « Ne rien renvoyer » : c'est le cas le plus interessant, celui ou
        // l'operateur reste muet. La transaction doit alors expirer d'elle-meme
        // (section 21.1).
        if ($outcome === 'TIMEOUT') {
            return back();
        }

        $payload = [
            'event_id' => 'fake-'.Str::uuid()->toString(),
            'order_reference' => $payable->paymentReference(),
            'status' => $outcome === 'SUCCEEDED' ? 'SUCCEEDED' : 'FAILED',
            'amount' => $payable->paymentAmount(),
            'provider_reference' => $payable->providerReference() ?? 'FAKE-'.Str::upper(Str::random(12)),
            'failure_reason' => $outcome === 'SUCCEEDED' ? null : 'Solde insuffisant.',
        ];

        app(FakeProvider::class)->remember(
            $payable,
            $outcome === 'SUCCEEDED' ? PaymentState::Succeeded : PaymentState::Failed,
        );

        // Adresse interne : depuis le conteneur applicatif, APP_URL ne mene
        // nulle part, le serveur web etant un conteneur voisin.
        $endpoint = rtrim((string) config('payments.internal_base_url'), '/')
            .'/api/webhooks/payments/fake';

        Http::withHeaders(['X-Pass241-Signature' => FakeProvider::sign($payload)])
            ->post($endpoint, $payload);

        return back();
    }
}
