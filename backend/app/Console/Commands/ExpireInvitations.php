<?php

namespace App\Console\Commands;

use App\Actions\Invitations\CancelInvitation;
use App\Models\Invitation;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ferme les invitations restees sans reponse (section 25).
 *
 * LA PLACE EST DEJA RENDUE quand cette commande s'execute : le stock cesse de
 * compter une reservation a la seconde ou son echeance passe (decision D-017),
 * sans qu'aucun traitement n'ait a s'executer. Cette commande ne libere donc
 * rien — elle met les mots justes sur ce qui s'est deja produit.
 *
 * Ce n'est pas une nuance de confort. Si elle ne tourne pas pendant trois
 * jours, aucune place n'est perdue et aucune n'est vendue deux fois : seuls les
 * statuts affiches restent en retard. C'est exactement la propriete qu'on veut
 * d'une tache planifiee qui touche au stock.
 */
class ExpireInvitations extends Command
{
    protected $signature = 'pass241:expire-invitations';

    protected $description = 'Passe en expirees les invitations non acceptees dont le delai est ecoule';

    public function handle(CancelInvitation $invitations, OrganizationContext $context): int
    {
        $lapsed = Invitation::query()
            ->acrossOrganizations()
            ->open()
            ->where('expires_at', '<=', now())
            ->get();

        if ($lapsed->isEmpty()) {
            $this->info('Aucune invitation a fermer.');

            return self::SUCCESS;
        }

        $closed = 0;

        foreach ($lapsed as $invitation) {
            try {
                $context->runFor(
                    $invitation->organization,
                    fn () => $invitations->expire($invitation)
                );

                $closed++;
            } catch (Throwable $e) {
                // Une invitation en echec n'arrete pas les autres : elles
                // appartiennent a des organisateurs differents, et rien ne
                // justifie qu'un incident chez l'un gele l'affichage chez
                // l'autre.
                report($e);

                $this->error(sprintf('Invitation %d : %s', $invitation->getKey(), $e->getMessage()));
            }
        }

        $this->info(sprintf('%d invitation%s fermee%s.', $closed, $closed > 1 ? 's' : '', $closed > 1 ? 's' : ''));

        return self::SUCCESS;
    }
}
