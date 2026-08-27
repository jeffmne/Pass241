<?php

namespace App\Console\Commands;

use App\Actions\Campaigns\PublishCampaign;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Passe en echues les collectes dont la date de fin est arrivee (section 35).
 *
 * LA COLLECTE EST DEJA FERMEE quand cette commande s'execute :
 * `Campaign::acceptsDonations()` verifie la date de fin, pas seulement le
 * statut. Un don ne peut donc pas passer entre l'echeance et le balayage.
 *
 * Ce n'est pas une nuance de confort. Si la commande ne tourne pas pendant trois
 * jours, aucun don hors delai n'est accepte : seuls les statuts affiches restent
 * en retard. C'est la propriete qu'on attend d'une tache planifiee qui touche a
 * de l'argent — la meme que pour les invitations.
 */
class ExpireCampaigns extends Command
{
    protected $signature = 'pass241:expire-campaigns';

    protected $description = 'Passe en echues les collectes dont la date de fin est depassee';

    public function handle(PublishCampaign $campaigns, OrganizationContext $context): int
    {
        $lapsed = Campaign::query()
            ->acrossOrganizations()
            ->whereIn('status', [CampaignStatus::Published->value, CampaignStatus::Paused->value])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->get();

        if ($lapsed->isEmpty()) {
            $this->info('Aucune collecte a fermer.');

            return self::SUCCESS;
        }

        $closed = 0;

        foreach ($lapsed as $campaign) {
            try {
                $context->runFor(
                    $campaign->organization,
                    fn () => $campaigns->expire($campaign)
                );

                $closed++;
            } catch (Throwable $e) {
                // Une collecte en echec n'arrete pas les autres : elles
                // appartiennent a des organisateurs differents, et rien ne
                // justifie qu'un incident chez l'un gele l'affichage chez
                // l'autre.
                report($e);

                $this->error(sprintf('Collecte %d : %s', $campaign->getKey(), $e->getMessage()));
            }
        }

        $this->info(sprintf('%d collecte%s fermee%s.', $closed, $closed > 1 ? 's' : '', $closed > 1 ? 's' : ''));

        return self::SUCCESS;
    }
}
