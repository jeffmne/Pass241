<?php

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Models\Ballot;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fait echouer un bulletin paye reste impaye, ET REND SA VOIX (sections 21.1,
 * 86.5).
 *
 * C'est la difference avec son equivalent pour les dons, et elle est
 * essentielle. Un don en attente n'immobilise rien ; UN BULLETIN EN ATTENTE
 * IMMOBILISE UNE VOIX sur le quota du votant, reservee des la creation pour que
 * deux personnes ne puissent pas payer la meme derniere voix d'une categorie
 * plafonnee.
 *
 * Sans ce job, quelqu'un qui lance un paiement puis abandonne resterait bloque
 * pour toujours : son plafond serait consomme par un vote qu'il n'a jamais paye,
 * et il ne pourrait plus voter du tout. La liberation est donc le vrai travail
 * de ce job — l'echec du bulletin n'en est que la trace.
 *
 * IL NE CHANGE RIEN AU CLASSEMENT : seuls les bulletins confirmes et rembourses
 * y entrent, et un bulletin en attente n'y a jamais figure. Un worker arrete ne
 * peut donc pas fausser un resultat affiche — au pire, un votant restera bloque
 * sur son plafond plus longtemps que prevu.
 *
 * Idempotent : rejoue plusieurs fois, il ne fait rien de plus. La liberation
 * elle-meme est bornee a zero pour la meme raison.
 */
class ExpireBallotIfUnpaid implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $ballotId) {}

    public function handle(AuditLogger $audit): void
    {
        // Un worker de file d'attente n'a aucun contexte d'organisation : le
        // bulletin est donc retrouve hors portee.
        $ballot = Ballot::query()->acrossOrganizations()->find($this->ballotId);

        if (! $ballot || ! $ballot->isPending()) {
            return;
        }

        // Le job peut s'executer un peu tot si la file est en avance sur
        // l'horloge : on ne fait echouer que ce qui est reellement echu.
        if (! $ballot->hasExpired()) {
            return;
        }

        /*
         * `applyPaymentFailed` plutot qu'une ecriture directe : c'est lui qui
         * rend la voix au quota, et cette regle ne doit exister qu'a un seul
         * endroit. Le refus de l'operateur passe par le meme chemin, via
         * `ConfirmPayment::markFailed`.
         */
        $ballot->applyPaymentFailed('Paiement non confirme dans le delai imparti.');

        $audit->log(
            AuditAction::BallotFailed,
            $ballot,
            [
                'reference' => $ballot->reference,
                'candidate_id' => $ballot->candidate_id,
                'quantity' => $ballot->quantity,
                'reason' => 'timeout',
            ],
            actor: null,
            organizationId: $ballot->organization_id,
        );
    }
}
