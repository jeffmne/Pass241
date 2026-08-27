<?php

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fait echouer un don reste impaye (sections 21.1, 39).
 *
 * Les webhooks mobile money arrivent en retard, ou pas du tout, selon
 * l'operateur. Ce job marque alors le don en echec, pour que le donateur comme
 * l'organisateur lisent un etat clair, et pour laisser une trace au journal.
 *
 * IL NE CHANGE RIEN AU MONTANT COLLECTE : seuls les dons confirmes y entrent
 * (section 39), et un don en attente n'y a jamais figure. Un worker arrete ne
 * peut donc pas fausser le total affiche — au pire, un don restera « en
 * attente » plus longtemps que prevu sur la page de suivi de son auteur.
 *
 * Idempotent : rejoue plusieurs fois, il ne fait rien de plus.
 */
class ExpireDonationIfUnpaid implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $donationId) {}

    public function handle(AuditLogger $audit): void
    {
        // Un worker de file d'attente n'a aucun contexte d'organisation : le don
        // est donc retrouve hors portee.
        $donation = Donation::query()->acrossOrganizations()->find($this->donationId);

        if (! $donation || ! $donation->isPending()) {
            return;
        }

        // Le job peut s'executer un peu tot si la file est en avance sur
        // l'horloge : on ne fait echouer que ce qui est reellement echu.
        if (! $donation->hasExpired()) {
            return;
        }

        $donation->forceFill([
            'status' => DonationStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => 'Paiement non confirme dans le delai imparti.',
        ])->save();

        $audit->log(
            AuditAction::DonationFailed,
            $donation,
            ['reference' => $donation->reference, 'reason' => 'timeout'],
            actor: null,
            organizationId: $donation->organization_id,
        );
    }
}
