<?php

namespace App\Actions\Invitations;

use App\Enums\AuditAction;
use App\Enums\InvitationStatus;
use App\Enums\OrderStatus;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Annulation et expiration d'une invitation (section 25).
 *
 * Les deux font le meme geste — rendre la place — mais ne disent pas la meme
 * chose : l'annulation est une decision de l'organisateur, l'expiration est le
 * temps qui passe. L'historique doit pouvoir les distinguer, d'ou deux entrees
 * d'audit et deux statuts.
 *
 * UNE INVITATION ACCEPTEE NE S'ANNULE PAS. Le billet existe, il a pu etre
 * transmis, imprime, presente a une porte. Le retirer serait annuler une place
 * que quelqu'un croit avoir : cela passe par le remboursement (section 24), qui
 * laisse une trace, jamais par une annulation silencieuse.
 */
class CancelInvitation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function cancel(Invitation $invitation, ?User $actor = null): Invitation
    {
        if ($invitation->status === InvitationStatus::Accepted) {
            throw new RuntimeException(
                'Cette invitation a deja ete acceptee : le billet existe et ne peut pas '
                .'etre retire ici.'
            );
        }

        if ($invitation->status === InvitationStatus::Cancelled) {
            return $invitation;
        }

        return $this->close(
            $invitation,
            InvitationStatus::Cancelled,
            AuditAction::InvitationCancelled,
            $actor,
        );
    }

    /** Appelee par la tache planifiee, jamais par un ecran. */
    public function expire(Invitation $invitation): Invitation
    {
        if (! $invitation->status->isOpen()) {
            return $invitation;
        }

        return $this->close(
            $invitation,
            InvitationStatus::Expired,
            AuditAction::InvitationExpired,
            null,
        );
    }

    /**
     * Ferme l'invitation et libere la place.
     *
     * La commande passe en annulee : le stock cesse de la compter (decision
     * D-017). C'est le seul effet reel de cette action — le reste est de la
     * tracabilite.
     */
    private function close(
        Invitation $invitation,
        InvitationStatus $status,
        AuditAction $action,
        ?User $actor,
    ): Invitation {
        DB::transaction(function () use ($invitation, $status) {
            $attributes = ['status' => $status->value];

            if ($status === InvitationStatus::Cancelled) {
                $attributes['cancelled_at'] = now();
            }

            $invitation->forceFill($attributes)->save();

            Order::query()
                ->acrossOrganizations()
                ->whereKey($invitation->order_id)
                ->where('status', OrderStatus::Pending->value)
                ->update(['status' => OrderStatus::Cancelled->value, 'expires_at' => null]);
        });

        $this->audit->log(
            $action,
            $invitation,
            ['guest' => $invitation->guest_name],
            actor: $actor,
            organizationId: $invitation->organization_id,
        );

        return $invitation;
    }
}
