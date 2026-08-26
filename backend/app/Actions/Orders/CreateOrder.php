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
use App\Services\Payments\PaymentManager;
use App\Services\Payments\PspFeeCalculator;
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
 * cette commande EST la reservation : ses billets sortent aussitot du stock
 * disponible, et y reviennent seuls a l'expiration (decision D-017). Il n'y a
 * aucun verrou a poser ni a relacher.
 */
class CreateOrder
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
        private readonly PspFeeCalculator $fees,
        private readonly PaymentManager $payments,
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
            $order = DB::transaction(function () use (
                $event, $quantities, $firstName, $lastName, $email,
                $provider, $payerPhone, $ipAddress
            ) {
                $lines = [];
                $ticketsTotal = 0;

                foreach ($quantities as $ticketTypeId => $quantity) {
                    $ticketType = $this->resolveTicketType($event, (int) $ticketTypeId);

                    $this->assertPurchasable($ticketType, $quantity);

                    // Instantane du prix : la commande doit continuer a dire ce
                    // qui a ete paye, meme si le tarif change ensuite.
                    $lineTotal = $ticketType->price * $quantity;
                    $ticketsTotal += $lineTotal;

                    $lines[] = [
                        'ticket_type_id' => $ticketType->getKey(),
                        'ticket_type_name' => $ticketType->name,
                        'quantity' => $quantity,
                        'unit_price' => $ticketType->price,
                        'subtotal' => $lineTotal,
                    ];
                }

                /*
                 * Frais du prestataire, a la charge de l'acheteur
                 * (decision D-023). Calcules ici et nulle part ailleurs : le
                 * montant debite est celui que le serveur produit, jamais
                 * celui que le navigateur a affiche (regle 80.5).
                 *
                 * Le bareme est fige sur la commande. Sans cet instantane, une
                 * transaction deviendrait inexplicable apres un changement de
                 * prestataire.
                 */
                $psp = $this->payments->driver()->name();
                $fee = $this->fees->feeFor($ticketsTotal, $psp);

                $order = new Order([
                    'event_id' => $event->getKey(),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'payment_provider' => $provider,
                    'payer_phone' => $payerPhone,
                    'psp' => $psp,
                    // `subtotal` est l'assiette de la commission Pass241 ;
                    // `amount` est ce qui part du telephone de l'acheteur.
                    'subtotal' => $ticketsTotal,
                    'psp_fee_amount' => $fee->amount,
                    'psp_fee_basis_points' => $fee->basisPoints,
                    'psp_fee_fixed' => $fee->fixed,
                    'amount' => $ticketsTotal + $fee->amount,
                    // Section 17.1 : la duree de reservation est celle du delai
                    // de validation du paiement. Les deux ne doivent jamais
                    // diverger, d'ou une source unique.
                    'expires_at' => now()->addSeconds((int) config('payments.timeout_seconds')),
                    'ip_address' => $ipAddress,
                ]);

                $order->reference = $this->uniqueReference();
                $order->status = OrderStatus::Pending;
                $order->save();

                $order->items()->createMany($lines);

                return $order;
            });

            // Filet de securite : la commande doit basculer en echec meme si
            // aucun callback n'arrive (section 21.1). Le stock, lui, n'en
            // depend pas — il redevient disponible a l'echeance, que ce job
            // s'execute ou non (decision D-017).
            ExpireOrderIfUnpaid::dispatch($order->getKey())
                ->delay($order->expires_at->addSeconds(30));

            $this->audit->log(
                AuditAction::PaymentCreated,
                $order,
                [
                    'reference' => $order->reference,
                    // Les trois montants, et non le seul total : un audit doit
                    // pouvoir dire ce qui revenait a qui sans recalculer avec
                    // un bareme qui aura peut-etre change.
                    'amount' => $order->amount,
                    'subtotal' => $order->subtotal,
                    'psp_fee' => $order->psp_fee_amount,
                    'psp' => $order->psp,
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

        $available = $ticketType->availableStock();

        if ($quantity > $available) {
            throw new RuntimeException(sprintf(
                '« %s » : il ne reste que %d billet%s.',
                $ticketType->name,
                $available,
                $available > 1 ? 's' : ''
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
