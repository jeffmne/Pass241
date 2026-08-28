<?php

namespace App\Actions\Polls;

use App\Data\PollDraft;
use App\Enums\AuditAction;
use App\Enums\PollStatus;
use App\Models\Poll;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\SlugGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Creation et modification d'un scrutin (section 86.4).
 *
 * Un seul point d'entree pour les deux, comme `SaveCampaign` : creer et modifier
 * partagent la meme validation metier et la meme gestion d'images. Deux actions
 * separees auraient diverge des la premiere evolution.
 *
 * L'organisation n'est jamais passee en parametre : elle vient du contexte
 * etabli par `EnsureOrganizationContext`, et `BelongsToOrganization` refuse
 * toute ecriture visant une autre organisation (regle 80.4).
 *
 * CE QUI NE PASSE JAMAIS PAR ICI : le nombre de votes et le classement. Ils
 * n'existent pas en colonne (section 86.5), il n'y a donc rien a ecrire, meme
 * par erreur.
 */
class SavePoll
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SlugGenerator $slugs,
    ) {}

    public function create(PollDraft $draft, User $actor): Poll
    {
        return DB::transaction(function () use ($draft, $actor) {
            $poll = new Poll($draft->pollAttributes());

            $poll->slug = $this->slugs->unique(Poll::class, $draft->name, fallback: 'vote');
            $poll->status = PollStatus::Draft;
            $poll->created_by = $actor->getKey();

            $this->attachImages($poll, $draft->poster, $draft->banner);
            $poll->save();

            $this->audit->log(
                AuditAction::PollCreated,
                $poll,
                ['name' => $poll->name],
                actor: $actor,
            );

            return $poll->refresh();
        });
    }

    /**
     * Modifie le scrutin.
     *
     * Deux regimes, selon qu'il a ete publie ou non :
     *
     * - un brouillon ou un scrutin programme se modifie entierement, tant que
     *   personne n'a encore pu voter ;
     * - un scrutin ouvert ne laisse changer que sa description et ses images. La
     *   fenetre de vote et la visibilite des resultats sont les REGLES DU JEU :
     *   les changer pendant la partie reviendrait a rallonger un scrutin qui se
     *   joue mal, ou a devoiler un classement promis scelle.
     *
     * Un scrutin clos ou annule ne se modifie plus du tout : son resultat est
     * acquis.
     */
    public function update(Poll $poll, PollDraft $draft, User $actor): Poll
    {
        return DB::transaction(function () use ($poll, $draft, $actor) {
            $this->assertEditable($poll);

            if ($poll->status->isEditable()) {
                $poll->fill($draft->pollAttributes());

                /*
                 * Le slug suit le nom tant que le scrutin n'est PAS PUBLIE.
                 *
                 * `isEditable()` couvre aussi l'etat programme, qui est deja
                 * publie : sa page circule. La condition porte donc sur la
                 * publication, pas sur la modifiabilite — deux notions qui se
                 * recouvrent partout ailleurs, et se separent ici.
                 */
                if ($poll->isDirty('name') && ! $poll->isPublished()) {
                    $poll->slug = $this->slugs->unique(
                        Poll::class,
                        $draft->name,
                        ignoreId: $poll->getKey(),
                        fallback: 'vote',
                    );
                }
            } else {
                $poll->fill($draft->openedAttributes());
            }

            $this->attachImages($poll, $draft->poster, $draft->banner);

            $changed = array_keys($poll->getDirty());
            $poll->save();

            $this->audit->log(
                AuditAction::PollUpdated,
                $poll,
                ['changed' => $changed],
                actor: $actor,
            );

            return $poll->refresh();
        });
    }

    /**
     * Supprime un scrutin.
     *
     * Uniquement un brouillon jamais publie. Des qu'un lien a pu circuler, la
     * suppression effacerait avec le scrutin les bulletins qui lui sont
     * rattaches — dont des bulletins PAYES, ce que la section 86.4 interdit
     * formellement. L'annulation existe pour cela : elle arrete le vote sans
     * rien detruire.
     */
    public function delete(Poll $poll, User $actor): void
    {
        if (! $poll->isDeletable()) {
            throw new RuntimeException(
                'Un vote deja publie ne se supprime pas : annulez-le, '
                .'ses resultats doivent rester consultables.'
            );
        }

        $name = $poll->name;

        DB::transaction(function () use ($poll, $actor, $name) {
            $this->audit->log(
                AuditAction::PollUpdated,
                $poll,
                ['deleted' => true, 'name' => $name],
                actor: $actor,
            );

            foreach ([$poll->poster_path, $poll->banner_path] as $path) {
                if ($path) {
                    Storage::disk('public')->delete($path);
                }
            }

            $poll->delete();
        });
    }

    private function assertEditable(Poll $poll): void
    {
        if (! $poll->status->isClosed()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Un vote au statut « %s » ne se modifie plus.',
            $poll->status->label()
        ));
    }

    private function attachImages(Poll $poll, ?UploadedFile $poster, ?UploadedFile $banner): void
    {
        if ($poster) {
            $this->replace($poll, 'poster_path', $poster);
        }

        if ($banner) {
            $this->replace($poll, 'banner_path', $banner);
        }
    }

    /**
     * Les images vivent sur le disque public : elles doivent etre lisibles par
     * n'importe qui ouvrant le lien du vote, et servent d'apercu de partage.
     * Rien de sensible ne s'y trouve.
     */
    private function replace(Poll $poll, string $column, UploadedFile $file): void
    {
        $previous = $poll->getAttribute($column);

        $poll->setAttribute(
            $column,
            $file->store('polls/'.($poll->getKey() ?? 'nouveaux'), 'public'),
        );

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }
    }
}
