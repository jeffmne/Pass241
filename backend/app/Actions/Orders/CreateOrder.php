<?php

namespace App\Actions\Orders;

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Jobs\ExpireOrderIfUnpaid;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Audit\AuditLogger;
use App\Services\Ticketing\StockHoldService;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creation d'une commande (sections 15, 16, 17.1, 20).
 *
 * L'acheteur n'a pas de compte : cette action s'execute donc hors de tout
 * contexte d'organisation, dans une requete publique. L'organisation est
 * deduite de l'evenement — jamais d'un parametre envoye par le client
 * (section 80.4).
 *
 * Rien n'est encaisse ici. La commande nait en PENDING avec une echeance, et
 * le module de paiement prend le relais (jalon suivant).
 */
class CreateOrder
{
    public function __construct(
        private readonly StockHoldService $holds,
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * @param  array<int, int>  $quantities  identifiant de type de billet => quantite
     */
    public function handle(
        Event $event,
        array $quantities,
        string $firstName,
        string $lastName,
        ?string $email,
        PaymentProvider $provider,
        string $payerPhone,
        ?string $ipAddress = null,
    ): Order {
        if (! $event->allowsPurchase()) {
            throw new RuntimeException('La vente n\'est pas ouverte pour cet evenement.');
        }

        $quantities = array_filter($quantities, fn (int $quantity) => $quantity > 0);

        if ($quantities === []) {
            throw new RuntimeException('Choisissez au moins un billet.');
        }

        /*
         * Le contexte est pose sur l'organisation de l'evenement, le temps de
         * la transaction. Sans lui, le trait BelongsToOrganization refuserait
         * de creer la commande — ce qui est exactement le comportement voulu
         * partout ailleurs, et qu'il faut ici lever explicitement plutot que
         * contourner en forcant `organization_id`.
         */
        return $this->context->runFor($event->organization, function () use (
            $event, $quantities, $firstName, $lastName, $email, $provider, $payerPhone, $ipAddress
        ) {
            $reference = $this->uniqueReference();
            $held = [];

            try {
                $order = DB::transaction(function () use (
                    $event, $quantities, $firstName, $lastName, $email,
                    $provider, $payerPhone, $ipAddress, $reference, &$held
                ) {
                    $lines = [];
                    $total = 0;

                    foreach ($quantities as $ticketTypeId => $quantity) {
                        $ticketType = $this->resolveTicketType($event, (int) $ticketTypeId);

                        $this->assertPurchasable($ticketType, $quantity);

                        // Section 17.1 : le hold ne concerne que le dernier
                        // billet. Au-dela, il n'y a rien a proteger.
                        if (! $this->holds->tryHold($ticketType, $reference, $quantity)) {
                            throw new RuntimeException(sprintf(
                                'Le dernier billet « %s » vient d\'etre reserve par quelqu\'un d\'autre.',
                                $ticketType->name
                            ));
                        }

                        $held[] = $ticketType;

                        // Instantane du prix : la commande doit continuer a
                        // dire ce qui a ete paye, meme si le tarif change.
                        $subtotal = $ticketType->price * $quantity;
                        $total += $subtotal;

                        $lines[] = [
                            'ticket_type_id' => $ticketType->getKey(),
                            'ticket_type_name' => $ticketType->name,
                            'quantity' => $quantity,
                            'unit_price' => $ticketType->price,
                            'subtotal' => $subtotal,
                        ];
                    }

                    $order = new Order([
                        'event_id' => $event->getKey(),
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                        'payment_provider' => $provider,
                        'payer_phone' => $payerPhone,
                        'amount' => $total,
                        'expires_at' => now()->addSeconds($this->holds->ttlSeconds()),
                        'ip_address' => $ipAddress,
                    ]);

                    $order->reference = $reference;
                    $order->status = OrderStatus::Pending;
                    $order->save();

                    $order->items()->createMany($lines);

                    return $order;
                });
            } catch (RuntimeException $e) {
                // La transaction est annulee, mais les holds vivent dans Redis
                // et lui survivraient : sans ce nettoyage, un echec en cours de
                // panier bloquerait le dernier billet pendant cinq minutes.
                foreach ($held as $ticketType) {
                    $this->holds->release($ticketType, $reference);
                }

                throw $e;
            }

            // Filet de securite : le webhook du provider reste la voie normale,
            // mais une commande ne doit jamais rester en attente parce qu'un
            // callback n'est pas arrive (section 21.1).
            ExpireOrderIfUnpaid::dispatch($order->getKey())
                ->delay(now()->addSeconds($this->holds->ttlSeconds() + 30));

            $this->audit->log(
                AuditAction::PaymentCreated,
                $order,
                [
                    'reference' => $order->reference,
                    'amount' => $order->amount,
                    'provider' => $provider->value,
                    'tickets' => $order->items->sum('quantity'),
                ],
                actor: null,
                organizationId: $event->organization_id,
            );

            return $order->load('items');
        });
    }

    private function resolveTicketType(Event $event, int $ticketTypeId): TicketType
    {
        $ticketType = TicketType::query()
            ->acrossOrganizations()
            ->where('event_id', $event->getKey())
            ->find($ticketTypeId);

        // Le type doit appartenir a CET evenement. Sans cette verification, un
        // identifiant d'un autre evenement — voire d'une autre organisation —
        // pourrait etre glisse dans la requete et vendu au mauvais tarif.
        return $ticketType ?? throw new RuntimeException('Ce billet n\'existe pas pour cet evenement.');
    }

    private function assertPurchasable(TicketType $ticketType, int $quantity): void
    {
        if (! $ticketType->isPurchasable()) {
            throw new RuntimeException(sprintf(
                '« %s » : %s',
                $ticketType->name,
                $ticketType->unavailabilityReason() ?? 'ce billet n\'est pas disponible.'
            ));
        }

        if ($quantity > $ticketType->max_per_order) {
            throw new RuntimeException(sprintf(
                '« %s » : %d billets au maximum par commande.',
                $ticketType->name,
                $ticketType->max_per_order
            ));
        }

        if ($quantity > $ticketType->availableStock()) {
            throw new RuntimeException(sprintf(
                '« %s » : il ne reste que %d billet%s.',
                $ticketType->name,
                $ticketType->availableStock(),
                $ticketType->availableStock() > 1 ? 's' : ''
            ));
        }
    }

    /**
     * Reference lisible, du type PS241-4KX9-27B.
     *
     * Elle est dictee au telephone au support : l'alphabet exclut les
     * caracteres qu'on confond a l'oral et a l'ecrit (0/O, 1/I/L).
     */
    private function uniqueReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $block = fn (int $length) => collect(range(1, $length))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');

            $reference = 'PS241-'.$block(4).'-'.$block(3);
        } while (Order::query()->acrossOrganizations()->where('reference', $reference)->exists());

        return $reference;
    }
}
