<?php

namespace App\Actions\Organizations;

use App\Enums\AuditAction;
use App\Enums\KycStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use RuntimeException;

/**
 * Decision de verification d'identite (section 10.1).
 *
 * Reservee au Super Admin : c'est cette decision qui debloque les reversements,
 * et un organisateur ne peut evidemment pas se verifier lui-meme. Le controle
 * est fait ici plutot que dans l'interface appelante, pour qu'aucun autre point
 * d'entree (commande, API, tache planifiee) ne puisse le contourner.
 */
class ReviewKyc
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function verify(Organization $organization, User $reviewer): Organization
    {
        $this->assertReviewer($reviewer);

        if ($organization->kyc_status !== KycStatus::Submitted) {
            throw new RuntimeException(
                'Seule une organisation ayant soumis ses pieces peut etre verifiee.'
            );
        }

        $organization->forceFill([
            'kyc_status' => KycStatus::Verified,
            'kyc_verified_at' => now(),
            'kyc_rejection_reason' => null,
        ])->save();

        // Section 10.1 : les reversements sont debloques retroactivement sur le
        // solde en attente. Rien a recalculer ici — les fonds ont ete
        // comptabilises normalement pendant toute la periode d'attente.
        $this->audit->log(
            AuditAction::KycVerified,
            $organization,
            actor: $reviewer,
            organizationId: $organization->getKey(),
        );

        return $organization;
    }

    public function reject(Organization $organization, User $reviewer, string $reason): Organization
    {
        $this->assertReviewer($reviewer);

        if (trim($reason) === '') {
            throw new RuntimeException('Un refus doit toujours etre motive.');
        }

        $organization->forceFill([
            'kyc_status' => KycStatus::Rejected,
            'kyc_verified_at' => null,
            'kyc_rejection_reason' => $reason,
        ])->save();

        $this->audit->log(
            AuditAction::KycRejected,
            $organization,
            ['reason' => $reason],
            actor: $reviewer,
            organizationId: $organization->getKey(),
        );

        return $organization;
    }

    private function assertReviewer(User $reviewer): void
    {
        if (! $reviewer->isSuperAdmin()) {
            throw new RuntimeException(
                'Seul un administrateur de la plateforme peut statuer sur une verification d\'identite.'
            );
        }
    }
}
