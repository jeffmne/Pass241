<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\PayoutState;
use App\Models\Settlement;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentManager;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Confronte le journal des reversements a ce que dit le prestataire
 * (section 21.2, decision D-026).
 *
 * C'est la commande qui donne sa valeur au reste. Sans elle, un virement
 * programme qui n'aboutit jamais, ou un ordre parti dont la reponse s'est
 * perdue, reste indefiniment dans un etat intermediaire — et l'ecart ne se
 * decouvre que le jour ou l'organisateur telephone.
 *
 * Elle est aussi le SEUL endroit ou un etat UNKNOWN peut devenir un echec.
 * Nulle part ailleurs on ne conclut a l'echec d'un virement : ce serait
 * autoriser une nouvelle tentative sur un ordre peut-etre deja parti.
 */
class ReconcileSettlements extends Command
{
    protected $signature = 'pass241:reconcile-settlements
                            {--limit=500 : Nombre maximal de reversements verifies}';

    protected $description = 'Verifie aupres du prestataire l\'etat des reversements non aboutis';

    public function handle(
        PaymentManager $payments,
        AuditLogger $audit,
        OrganizationContext $context,
    ): int {
        if (! $payments->supportsSettlement()) {
            $this->warn('Le prestataire configure ne sait pas reverser : rien a reconcilier.');

            return self::SUCCESS;
        }

        $pending = Settlement::query()
            ->acrossOrganizations()
            ->with('organization')
            ->whereIn('status', [
                PayoutState::Pending->value,
                PayoutState::Scheduled->value,
                PayoutState::Unknown->value,
            ])
            ->whereNotNull('provider_reference')
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Aucun reversement en attente de confirmation.');

            return self::SUCCESS;
        }

        $changed = 0;

        foreach ($pending as $settlement) {
            try {
                $result = $payments->settlement()->checkPayout($settlement->provider_reference);
            } catch (Throwable $e) {
                report($e);
                $this->error(sprintf('  %s : %s', $settlement->reference, $e->getMessage()));

                continue;
            }

            // Le prestataire ne sait toujours pas : on ne touche a rien. Un
            // etat inconnu qui persiste finit par etre traite a la main, ce
            // qui vaut mieux qu'un second virement.
            if ($result->state === PayoutState::Unknown) {
                $this->warn(sprintf('  %s : etat toujours inconnu.', $settlement->reference));

                continue;
            }

            if ($result->state === $settlement->status) {
                continue;
            }

            $context->runFor($settlement->organization, function () use ($settlement, $result, $audit) {
                $settlement->forceFill([
                    'status' => $result->state->value,
                    'settled_at' => $result->succeeded() ? now() : $settlement->settled_at,
                    'failed_at' => $result->failed() ? now() : null,
                    'failure_reason' => $result->failureReason,
                ])->save();

                $audit->log(
                    $result->succeeded() ? AuditAction::SettlementSucceeded : AuditAction::SettlementFailed,
                    $settlement,
                    [
                        'reference' => $settlement->reference,
                        'net' => $settlement->net_amount,
                        'state' => $result->state->value,
                        'source' => 'reconciliation',
                    ],
                    actor: null,
                    organizationId: $settlement->organization_id,
                );
            });

            $changed++;
            $this->line(sprintf('  %s → %s', $settlement->reference, $result->state->label()));
        }

        $this->info(sprintf('%d reversement(s) mis a jour sur %d verifie(s).', $changed, $pending->count()));

        return self::SUCCESS;
    }
}
