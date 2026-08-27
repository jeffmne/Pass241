<?php

namespace App\Actions\Organizations;

use App\Enums\AuditAction;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Suspend ou reactive une organisation (sections 10, 58).
 *
 * LE SEUL CHEMIN D'ECRITURE DE LA CONSOLE D'ADMINISTRATION, et il est etroit
 * par construction. Suspendre coupe l'acces de tous les membres, ferme les
 * ventes en cours et bloque les reversements : ce n'est pas un reglage, c'est
 * une decision. Elle exige donc un motif, et laisse une trace nominative.
 *
 * CE QU'ELLE NE FAIT PAS, et ne fera jamais : toucher a une donnee financiere.
 * Les billets vendus, les commandes payees, les dons confirmes et les
 * commissions restent exactement ce qu'ils etaient. Une organisation suspendue
 * est une organisation dont on ferme la porte, pas une organisation dont on
 * reecrit l'histoire (section 19 de la demande, regle 80.1).
 *
 * Les reversements deja programmes ne sont pas annules non plus : l'argent des
 * acheteurs appartient a l'organisateur, meme suspendu. Ce que la suspension
 * empeche, c'est d'en encaisser de nouveau.
 */
class SuspendOrganization
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function suspend(Organization $organization, User $actor, string $reason): Organization
    {
        if (trim($reason) === '') {
            // Une suspension sans motif ne s'explique ni a l'organisateur qui
            // telephone, ni a celui qui relira le journal dans six mois.
            throw new RuntimeException('Une suspension doit toujours etre motivee.');
        }

        if ($organization->isSuspended()) {
            return $organization;
        }

        return $this->apply($organization, $actor, [
            'suspended_at' => now(),
            'suspension_reason' => trim($reason),
        ], trim($reason), suspended: true);
    }

    public function reactivate(Organization $organization, User $actor, string $reason): Organization
    {
        if (! $organization->isSuspended()) {
            return $organization;
        }

        return $this->apply($organization, $actor, [
            'suspended_at' => null,
            // Le motif de suspension est conserve : l'historique d'un compte
            // reactive doit rester lisible, sans quoi une suspension repetee
            // ressemblerait a un incident isole.
        ], trim($reason), suspended: false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function apply(
        Organization $organization,
        User $actor,
        array $attributes,
        string $reason,
        bool $suspended,
    ): Organization {
        return DB::transaction(function () use ($organization, $actor, $attributes, $reason, $suspended) {
            /*
             * `forceFill` : `suspended_at` est volontairement hors de
             * `$fillable` (section 67). Aucun formulaire ne doit pouvoir
             * l'atteindre par affectation en masse — seul ce chemin l'ecrit.
             */
            $organization->forceFill($attributes)->save();

            $this->audit->log(
                AuditAction::OrganizationSuspended,
                $organization,
                [
                    'organization' => $organization->name,
                    'suspended' => $suspended,
                    'reason' => $reason !== '' ? $reason : null,
                ],
                actor: $actor,
                organizationId: $organization->getKey(),
            );

            return $organization->refresh();
        });
    }
}
