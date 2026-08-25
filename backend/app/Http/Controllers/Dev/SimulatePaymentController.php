<?php

namespace App\Http\Controllers\Dev;

use App\Enums\PaymentState;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\FakeProvider;
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
    public function __invoke(Request $request, string $reference, PaymentManager $payments): RedirectResponse
    {
        abort_unless($payments->isFake() && app()->environment('local'), 404);

        $order = Order::query()->acrossOrganizations()->where('reference', $reference)->firstOrFail();

        $outcome = $request->string('outcome')->toString();

        // « Ne rien renvoyer » : c'est le cas le plus interessant, celui ou
        // l'operateur reste muet. La commande doit alors expirer d'elle-meme
        // (section 21.1).
        if ($outcome === 'TIMEOUT') {
            return back();
        }

        $payload = [
            'event_id' => 'fake-'.Str::uuid()->toString(),
            'order_reference' => $order->reference,
            'status' => $outcome === 'SUCCEEDED' ? 'SUCCEEDED' : 'FAILED',
            'amount' => $order->amount,
            'provider_reference' => $order->provider_reference ?? 'FAKE-'.Str::upper(Str::random(12)),
            'failure_reason' => $outcome === 'SUCCEEDED' ? null : 'Solde insuffisant.',
        ];

        app(FakeProvider::class)->remember(
            $order,
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
