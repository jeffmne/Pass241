<?php

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fait expirer une commande restee impayee (section 21.1).
 *
 * Les webhooks mobile money arrivent en retard, ou pas du tout, selon
 * l'operateur. Ce job marque alors la commande en echec, pour que l'acheteur
 * comme l'organisateur lisent un etat clair, et pour laisser une trace au
 * journal.
 *
 * Il ne libere aucun stock, et c'est volontaire : depuis la decision D-017, la
 * reservation expire d'elle-meme avec la commande. Le stock redevient
 * disponible a l'echeance, que ce job s'execute ou non — un worker arrete ne
 * peut plus fausser un decompte.
 *
 * Idempotent : rejoue plusieurs fois, il ne fait rien de plus. Indispensable,
 * une file d'attente pouvant relivrer un message.
 */
class ExpireOrderIfUnpaid implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $orderId) {}

    public function handle(AuditLogger $audit, OrganizationContext $context): void
    {
        // Un worker de file d'attente n'a aucun contexte d'organisation : la
        // commande est donc retrouvee hors portee.
        $order = Order::query()->acrossOrganizations()->find($this->orderId);

        if (! $order) {
            return;
        }

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

        $order->forceFill([
            'status' => OrderStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => 'Paiement non confirme dans le delai imparti.',
        ])->save();

        $audit->log(
            AuditAction::PaymentFailed,
            $order,
            ['reference' => $order->reference, 'reason' => 'timeout'],
            actor: null,
            organizationId: $order->organization_id,
        );
    }
}
