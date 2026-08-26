<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\RefundStatus;
use App\Mail\RefundDecided;
use App\Models\RefundRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Refunds\RefundPolicy;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Ferme les demandes de remboursement restees sans reponse (section 24.1).
 *
 * Une demande soumise dans les delais mais jamais tranchee ne peut pas rester
 * ouverte indefiniment : le reversement intervient apres l'evenement
 * (decision D-026), et une demande en suspens bloquerait l'argent de
 * l'organisateur sans que personne ait decide quoi que ce soit.
 *
 * Passe l'echeance, la demande devient EXPIRED. Ce n'est pas un refus de
 * l'organisateur — la distinction compte pour arbitrer un litige : le client
 * a demande dans les temps, personne n'a repondu.
 */
class ExpireRefundRequests extends Command
{
    protected $signature = 'pass241:expire-refund-requests';

    protected $description = 'Ferme les demandes de remboursement restees sans reponse passe le delai';

    public function handle(RefundPolicy $policy, AuditLogger $audit, OrganizationContext $context): int
    {
        $pending = RefundRequest::query()
            ->acrossOrganizations()
            ->pending()
            ->with(['order' => fn ($query) => $query->acrossOrganizations()->with([
                'event' => fn ($q) => $q->acrossOrganizations(),
            ])])
            ->get()
            ->filter(fn (RefundRequest $request) => $request->order
                && ! $policy->isWithinWindow($request->order));

        if ($pending->isEmpty()) {
            $this->info('Aucune demande a fermer.');

            return self::SUCCESS;
        }

        foreach ($pending as $request) {
            $context->runFor($request->organization, function () use ($request, $audit) {
                $request->forceFill([
                    'status' => RefundStatus::Expired->value,
                    'decided_at' => now(),
                ])->save();

                $audit->log(
                    AuditAction::RefundExpired,
                    $request,
                    [
                        'reference' => $request->reference,
                        'order' => $request->order->reference,
                        'amount' => $request->amount,
                    ],
                    // Aucun acteur : personne n'a decide, et c'est justement
                    // ce que cette ligne consigne.
                    actor: null,
                    organizationId: $request->organization_id,
                );
            });

            // Le client a demande dans les temps : lui laisser croire que sa
            // demande est toujours a l'etude serait pire que de lui dire
            // qu'elle est close.
            if ($request->order?->email) {
                try {
                    Mail::to($request->order->email)->send(new RefundDecided($request->fresh()));
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $this->line(sprintf('  %s fermee sans reponse.', $request->reference));
        }

        $this->info(sprintf('%d demande(s) fermee(s).', $pending->count()));

        return self::SUCCESS;
    }
}
