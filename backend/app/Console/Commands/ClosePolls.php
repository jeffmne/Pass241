<?php

namespace App\Console\Commands;

use App\Actions\Polls\PublishPoll;
use App\Enums\PollStatus;
use App\Models\Poll;
use App\Services\Voting\PollResultsFreezer;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Clot les scrutins arrives a leur date de fin, et fige leurs resultats
 * (sections 86.4, 86.5).
 *
 * LE SCRUTIN EST DEJA FERME quand cette commande s'execute.
 * `PollCategory::acceptsBallots()` verifie les DATES, pas le statut : aucun
 * vote ne peut passer entre l'echeance et le balayage. Si la commande ne tourne
 * pas pendant trois jours, aucun bulletin hors delai n'est accepte — seules les
 * etiquettes affichees restent en retard.
 *
 * C'est la propriete qu'on attend d'une tache planifiee qui touche a un
 * resultat, et c'est la meme que pour les collectes et les invitations.
 *
 * ELLE FAIT DEUX CHOSES, ET L'ORDRE COMPTE : clore d'abord, figer ensuite. Un
 * classement fige sur un scrutin encore ouvert pourrait etre depasse par un
 * bulletin arrive dans l'intervalle. La cloture etant portee par les dates, ce
 * risque est deja nul en pratique ; l'ordre le rend nul par construction.
 *
 * LES DEUX ETAPES SONT SEPAREES PAR SCRUTIN. Un scrutin dont le figeage echoue
 * n'empeche pas les suivants d'etre traites, et sa cloture reste acquise : la
 * prochaine execution reprendra le figeage, l'operation etant rejouable.
 */
class ClosePolls extends Command
{
    protected $signature = 'pass241:close-polls';

    protected $description = 'Clot les votes dont la date de fin est depassee et fige leurs resultats';

    public function handle(
        PublishPoll $polls,
        PollResultsFreezer $freezer,
        OrganizationContext $context,
    ): int {
        $closed = $this->closeLapsed($polls, $context);
        $frozen = $this->freezePending($freezer, $context);

        $this->info(sprintf(
            '%d vote(s) clos, %d ligne(s) de resultat figee(s).',
            $closed,
            $frozen,
        ));

        return self::SUCCESS;
    }

    /** Passe en clos les scrutins dont l'echeance est depassee. */
    private function closeLapsed(PublishPoll $polls, OrganizationContext $context): int
    {
        $lapsed = Poll::query()
            ->acrossOrganizations()
            ->whereIn('status', [PollStatus::Active->value, PollStatus::Scheduled->value])
            ->where('ends_at', '<=', now())
            ->get();

        $closed = 0;

        foreach ($lapsed as $poll) {
            try {
                $context->runFor($poll->organization, fn () => $polls->close($poll));
                $closed++;
            } catch (Throwable $e) {
                /*
                 * Un scrutin qui refuse la cloture — transition invalide,
                 * organisation supprimee — ne doit pas arreter les autres. Le
                 * signaler et continuer : la prochaine execution repassera.
                 */
                report($e);
                $this->warn(sprintf('Vote %s non clos : %s', $poll->slug, $e->getMessage()));
            }
        }

        return $closed;
    }

    /**
     * Fige les classements des scrutins clos qui ne le sont pas encore.
     *
     * Balaye TOUS les scrutins clos, et pas seulement ceux que cette execution
     * vient de fermer : un scrutin clos a la main par l'organisateur doit voir
     * son classement fige lui aussi, et un figeage qui aurait echoue la veille
     * doit pouvoir etre repris.
     */
    private function freezePending(PollResultsFreezer $freezer, OrganizationContext $context): int
    {
        $closedPolls = Poll::query()
            ->acrossOrganizations()
            ->where('status', PollStatus::Closed->value)
            ->get();

        $frozen = 0;

        foreach ($closedPolls as $poll) {
            try {
                $frozen += $context->runFor($poll->organization, fn () => $freezer->freeze($poll));
            } catch (Throwable $e) {
                report($e);
                $this->warn(sprintf('Resultats de %s non figes : %s', $poll->slug, $e->getMessage()));
            }
        }

        return $frozen;
    }
}
