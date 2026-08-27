<?php

namespace App\Actions\Campaigns;

use App\Data\CampaignDraft;
use App\Enums\AuditAction;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\SlugGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Creation et modification d'une campagne de collecte (section 34).
 *
 * Un seul point d'entree pour les deux, comme `SaveEvent` : creer et modifier
 * partagent la meme validation metier et la meme gestion d'images. Deux actions
 * separees auraient diverge des la premiere evolution.
 *
 * L'organisation n'est jamais passee en parametre : elle vient du contexte
 * etabli par EnsureOrganizationContext, et le trait BelongsToOrganization
 * refuse toute ecriture visant une autre organisation (regle 80.4).
 *
 * CE QUI NE PASSE JAMAIS PAR ICI : le montant collecte, le pourcentage et le
 * nombre de dons. Ils n'existent pas en colonne (regle 80.1), il n'y a donc
 * rien a ecrire, meme par erreur.
 */
class SaveCampaign
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SlugGenerator $slugs,
    ) {}

    public function create(CampaignDraft $draft, User $actor): Campaign
    {
        return DB::transaction(function () use ($draft, $actor) {
            $campaign = new Campaign($draft->campaignAttributes());

            $campaign->slug = $this->slugs->unique(Campaign::class, $draft->name, fallback: 'collecte');
            $campaign->status = CampaignStatus::Draft;
            $campaign->created_by = $actor->getKey();

            $this->attachImages($campaign, $draft->poster, $draft->cover);
            $campaign->save();

            $this->audit->log(
                AuditAction::CampaignCreated,
                $campaign,
                ['name' => $campaign->name, 'goal' => $campaign->goal_amount],
                actor: $actor,
            );

            return $campaign->refresh();
        });
    }

    /**
     * Modifie la campagne.
     *
     * Deux regimes, selon qu'elle a ete publiee ou non :
     *
     * - un brouillon se modifie entierement ;
     * - une campagne publiee ne laisse changer que sa description et ses
     *   images. L'objectif, le beneficiaire et les dates engagent ceux qui ont
     *   deja donne — les changer discretement reviendrait a deplacer la cible
     *   apres coup, ce que la transparence de la section 37 exclut.
     *
     * Une campagne close ne se modifie plus du tout : son resultat est acquis.
     */
    public function update(Campaign $campaign, CampaignDraft $draft, User $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $draft, $actor) {
            $this->assertEditable($campaign);

            if ($campaign->status->isEditable()) {
                $campaign->fill($draft->campaignAttributes());

                // Le slug suit le nom tant que la campagne n'est pas publiee.
                // Ensuite il est fige : le lien a pu partir dans un message.
                if ($campaign->isDirty('name')) {
                    $campaign->slug = $this->slugs->unique(
                        Campaign::class,
                        $draft->name,
                        ignoreId: $campaign->getKey(),
                        fallback: 'collecte',
                    );
                }
            } else {
                $campaign->fill($draft->publishedAttributes());
            }

            $this->attachImages($campaign, $draft->poster, $draft->cover);

            $changed = array_keys($campaign->getDirty());
            $campaign->save();

            $this->audit->log(
                AuditAction::CampaignUpdated,
                $campaign,
                ['changed' => $changed],
                actor: $actor,
            );

            return $campaign->refresh();
        });
    }

    /**
     * Supprime une campagne.
     *
     * Uniquement un brouillon jamais publie. Des qu'un lien a pu circuler, la
     * suppression effacerait avec la campagne les dons qui lui sont rattaches —
     * ce que les sections 44 a 46 interdisent formellement. L'annulation existe
     * pour cela : elle arrete la collecte sans rien detruire.
     */
    public function delete(Campaign $campaign, User $actor): void
    {
        if (! $campaign->isDeletable()) {
            throw new RuntimeException(
                'Une collecte deja publiee ne se supprime pas : annulez-la, '
                .'son historique doit rester consultable.'
            );
        }

        $name = $campaign->name;

        DB::transaction(function () use ($campaign, $actor, $name) {
            $this->audit->log(
                AuditAction::CampaignUpdated,
                $campaign,
                ['deleted' => true, 'name' => $name],
                actor: $actor,
            );

            foreach ([$campaign->poster_path, $campaign->cover_path] as $path) {
                if ($path) {
                    Storage::disk('public')->delete($path);
                }
            }

            $campaign->delete();
        });
    }

    private function assertEditable(Campaign $campaign): void
    {
        if (! $campaign->status->isClosed()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Une collecte au statut « %s » ne se modifie plus.',
            $campaign->status->label()
        ));
    }

    private function attachImages(Campaign $campaign, ?UploadedFile $poster, ?UploadedFile $cover): void
    {
        if ($poster) {
            $this->replace($campaign, 'poster_path', $poster);
        }

        if ($cover) {
            $this->replace($campaign, 'cover_path', $cover);
        }
    }

    /**
     * Les images vivent sur le disque public : elles doivent etre lisibles par
     * n'importe qui ouvrant le lien de la collecte, et servent d'apercu de
     * partage. Rien de sensible ne s'y trouve.
     */
    private function replace(Campaign $campaign, string $column, UploadedFile $file): void
    {
        $previous = $campaign->getAttribute($column);

        $campaign->setAttribute(
            $column,
            $file->store('campaigns/'.($campaign->getKey() ?? 'nouvelles'), 'public'),
        );

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }
    }
}
