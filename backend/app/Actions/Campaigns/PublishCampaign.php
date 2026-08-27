<?php

namespace App\Actions\Campaigns;

use App\Enums\AuditAction;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Changements d'etat d'une campagne (section 35).
 *
 * Regroupes ici, hors de `SaveCampaign` : ce ne sont pas des modifications de
 * contenu mais des decisions, avec chacune leur consequence sur la collecte et
 * leur trace au journal (section 51).
 *
 * AUCUN DE CES GESTES NE TOUCHE A UN DON. Suspendre, cloturer ou annuler ferme
 * la collecte pour l'avenir ; les dons deja confirmes restent confirmes, restent
 * comptes et restent publics (sections 44 a 46). C'est la difference entre
 * arreter de recevoir et effacer ce qui a ete recu.
 */
class PublishCampaign
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Met la collecte en ligne et lui donne sa page publique. */
    public function publish(Campaign $campaign, User $actor): Campaign
    {
        $this->assertTransition($campaign, CampaignStatus::Published);

        $blockers = $campaign->publicationBlockers();

        if ($blockers !== []) {
            // Message unique reprenant tous les manques : corriger un point
            // pour en decouvrir un autre au clic suivant est penible.
            throw new RuntimeException(implode(' ', $blockers));
        }

        return DB::transaction(function () use ($campaign, $actor) {
            $firstPublication = ! $campaign->isPublished();

            $campaign->forceFill([
                'status' => CampaignStatus::Published,
                // La date de premiere publication ne bouge plus : c'est elle
                // qui fige le lien public.
                'published_at' => $campaign->published_at ?? now(),
            ])->save();

            $this->audit->log(
                AuditAction::CampaignPublished,
                $campaign,
                ['first_publication' => $firstPublication, 'slug' => $campaign->slug],
                actor: $actor,
            );

            return $campaign->refresh();
        });
    }

    /**
     * Suspend la collecte.
     *
     * La page reste en ligne avec son historique : c'est tout l'interet de la
     * suspension par rapport a un retour au brouillon, qui masquerait des dons
     * confirmes. Les visiteurs lisent que la collecte est momentanement fermee,
     * et ceux qui ont donne retrouvent leur contribution.
     */
    public function pause(Campaign $campaign, User $actor): Campaign
    {
        $this->assertTransition($campaign, CampaignStatus::Paused);

        $campaign->forceFill(['status' => CampaignStatus::Paused])->save();

        $this->audit->log(AuditAction::CampaignPaused, $campaign, [], actor: $actor);

        return $campaign->refresh();
    }

    /** Rouvre une collecte suspendue. */
    public function resume(Campaign $campaign, User $actor): Campaign
    {
        $this->assertTransition($campaign, CampaignStatus::Published);

        if ($campaign->hasLapsed()) {
            throw new RuntimeException(
                'La date de fin de cette collecte est passee : elle ne peut plus etre rouverte.'
            );
        }

        $campaign->forceFill(['status' => CampaignStatus::Published])->save();

        $this->audit->log(
            AuditAction::CampaignPublished,
            $campaign,
            ['resumed' => true],
            actor: $actor,
        );

        return $campaign->refresh();
    }

    /**
     * Cloture la collecte.
     *
     * Geste volontaire de l'organisateur — objectif atteint, ou campagne menee
     * a son terme. Distinct de l'echeance, qui est le temps qui passe : les
     * deux ferment la collecte, mais l'historique doit pouvoir les distinguer.
     */
    public function complete(Campaign $campaign, User $actor): Campaign
    {
        $this->assertTransition($campaign, CampaignStatus::Completed);

        $campaign->forceFill([
            'status' => CampaignStatus::Completed,
            'completed_at' => now(),
        ])->save();

        $this->audit->log(AuditAction::CampaignCompleted, $campaign, [], actor: $actor);

        return $campaign->refresh();
    }

    /**
     * Marque la collecte echue.
     *
     * Appelee par la tache planifiee, jamais par un ecran. Elle ne ferme rien
     * qui ne le soit deja : `Campaign::acceptsDonations()` verifie la date, de
     * sorte qu'un retard de la tache ne laisse jamais passer un don hors delai.
     * Cette methode met les mots justes sur un fait acquis.
     */
    public function expire(Campaign $campaign): Campaign
    {
        $this->assertTransition($campaign, CampaignStatus::Expired);

        $campaign->forceFill(['status' => CampaignStatus::Expired])->save();

        $this->audit->log(
            AuditAction::CampaignCompleted,
            $campaign,
            ['expired' => true],
            actor: null,
        );

        return $campaign->refresh();
    }

    /**
     * Annule la collecte.
     *
     * La page publique reste en ligne et affiche l'annulation : quelqu'un qui
     * ouvre un lien recu doit lire que la collecte est annulee, pas tomber sur
     * une page introuvable — et surtout, ceux qui ont donne doivent retrouver
     * leur contribution.
     *
     * Le remboursement des dons deja recus n'est PAS automatique, contrairement
     * a l'annulation d'un evenement (decision D-028) : un billet non honore
     * n'a plus d'objet, alors qu'un don remis a une cause peut deja avoir ete
     * employe. La decision revient a l'organisateur, don par don, par le module
     * de remboursement (section 47).
     */
    public function cancel(Campaign $campaign, User $actor, string $reason): Campaign
    {
        $this->assertTransition($campaign, CampaignStatus::Cancelled);

        if (trim($reason) === '') {
            throw new RuntimeException('Une annulation doit toujours etre motivee.');
        }

        return DB::transaction(function () use ($campaign, $actor, $reason) {
            $campaign->forceFill([
                'status' => CampaignStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($reason),
            ])->save();

            $this->audit->log(
                AuditAction::CampaignCancelled,
                $campaign,
                ['reason' => trim($reason)],
                actor: $actor,
            );

            return $campaign->refresh();
        });
    }

    private function assertTransition(Campaign $campaign, CampaignStatus $target): void
    {
        if ($campaign->status->canTransitionTo($target)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Passage impossible de « %s » a « %s ».',
            $campaign->status->label(),
            $target->label()
        ));
    }
}
