<?php

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Audit\AuditLogger;
use App\Services\Ticketing\StockHoldService;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Fait expirer une commande restee impayee (section 21.1).
 *
 * Les webhooks mobile money arrivent en retard, ou pas du tout, selon
 * l'operateur. Ce job est le filet : passe le delai, la commande bascule en
 * FAILED et le stock est libere, sans dependre d'un evenement exterieur.
 *
 * Idempotent : rejoue plusieurs fois, il ne fait rien de plus. C'est
 * indispensable, une file d'attente pouvant relivrer un message.
 */
class ExpireOrderIfUnpaid implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $orderId) {}

    public function handle(StockHoldService $holds, AuditLogger $audit, OrganizationContext $context): void
    {
        // La commande est retrouvee hors portee : un worker de file d'attente
        // n'a aucun contexte d'organisation.
        $order = Order::query()->acrossOrganizations()->find($this->orderId);

        if (! $order) {
            return;
        }

        /*
         * Le reste s'execute DANS le contexte de l'organisation de la commande.
         *
         * Sans cela, le chargement des types de billets passerait par la portee
         * globale et ne renverrait rien : aucun hold ne serait jamais libere,
         * et le dernier billet resterait bloque jusqu'a l'expiration du TTL
         * Redis — silencieusement.
         */
        $context->runFor($order->organization, function () use ($order, $holds, $audit) {
            $this->expire($order->load('items.ticketType'), $holds, $audit);
        });
    }

    private function expire(Order $order, StockHoldService $holds, AuditLogger $audit): void
    {
        // Le paiement a pu etre confirme entre-temps : dans ce cas il n'y a
        // rien a faire, et surtout rien a annuler.
        if (! $order->isPending()) {
            return;
        }

        // Le job peut s'executer un peu tot si la file d'attente est en avance
        // sur l'horloge : on ne fait expirer que ce qui est reellement echu.
        if (! $order->hasExpired()) {
            return;
        }

        DB::transaction(function () use ($order) {
            $order->forceFill([
                'status' => OrderStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => 'Paiement non confirme dans le delai imparti.',
            ])->save();
        });

        foreach ($order->items as $item) {
            if ($item->ticketType) {
                $holds->release($item->ticketType, $order->reference);
            }
        }

        $audit->log(
            AuditAction::PaymentFailed,
            $order,
            ['reference' => $order->reference, 'reason' => 'timeout'],
            actor: null,
            organizationId: $order->organization_id,
        );
    }
}
