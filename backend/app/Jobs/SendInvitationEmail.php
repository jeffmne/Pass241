<?php

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\InvitationStatus;
use App\Mail\GuestInvited;
use App\Models\Invitation;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Envoie le lien d'invitation a l'invite (sections 25, 69).
 *
 * En file d'attente : un organisateur qui colle cinquante noms attendrait
 * cinquante remises SMTP avant de revoir son ecran.
 *
 * LE STATUT EST POSE ICI, APRES L'ENVOI, jamais a la mise en file. « Envoyee »
 * doit vouloir dire que le message est parti : si le serveur de messagerie
 * refuse, l'invitation reste « creee » et l'organisateur voit qu'il lui reste
 * quelque chose a faire — transmettre le lien lui-meme, ce qui est de toute
 * facon le canal courant ici. Un statut pose a l'avance lui aurait cache le
 * probleme.
 *
 * L'echec releve donc du mecanisme de reprise de la file : le job retente, et
 * le statut suit ce qui s'est reellement produit.
 */
class SendInvitationEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $invitationId) {}

    public function handle(OrganizationContext $context, AuditLogger $audit): void
    {
        // Un worker n'a aucun contexte d'organisation : l'invitation est donc
        // retrouvee hors portee, puis tout le reste se fait dans le sien.
        $invitation = Invitation::query()
            ->acrossOrganizations()
            ->find($this->invitationId);

        if (! $invitation || ! $invitation->guest_email) {
            return;
        }

        /*
         * Annulee, deja acceptee ou echue entre la mise en file et l'envoi :
         * le message n'a plus lieu d'etre. Envoyer un lien vers une place qui
         * n'existe plus se retourne contre l'organisateur — l'invite se
         * presenterait a une porte qui le refuserait.
         */
        if (! $invitation->status->isOpen() || $invitation->hasLapsed()) {
            return;
        }

        $organization = $invitation->organization;

        if (! $organization) {
            return;
        }

        $context->runFor($organization, function () use ($invitation, $audit) {
            /*
             * Chargement DANS le contexte : hors contexte, la portee globale
             * masquerait l'evenement et le message partirait sans son nom ni sa
             * date. Ce piege s'est deja referme plusieurs fois sur des chemins
             * sans organisation.
             */
            $invitation->load(['event.location', 'event.organization', 'order.items']);

            Mail::to($invitation->guest_email)->send(new GuestInvited($invitation));

            $invitation->forceFill([
                // Un renvoi ne change pas le statut, seulement la date : c'est
                // la derniere tentative qui renseigne l'organisateur.
                'status' => InvitationStatus::Sent->value,
                'sent_at' => now(),
            ])->save();

            $audit->log(
                AuditAction::InvitationSent,
                $invitation,
                [
                    'guest' => $invitation->guest_name,
                    'channel' => 'email',
                    'to' => $invitation->guest_email,
                ],
                actor: null,
                organizationId: $invitation->organization_id,
            );
        });
    }
}
