<?php

namespace App\Actions\Polls;

use App\Enums\AuditAction;
use App\Enums\ResultsVisibility;
use App\Models\Poll;
use App\Models\PollCategory;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Categories d'un scrutin (section 86.4).
 *
 * LES TERMES ECONOMIQUES SE FIGENT A L'OUVERTURE DU VOTE. Gratuite, prix
 * unitaire et plafond par votant ne bougent plus des que le scrutin est actif :
 * ce sont les regles du jeu, et quelqu'un qui a paye 500 francs son vote l'a
 * fait sous ces regles. Les changer en cours de partie creerait deux categories
 * de votants dans un meme scrutin.
 *
 * Le nom, la description et l'ordre restent modifiables : corriger une faute de
 * frappe ou reordonner l'affichage ne change ce que personne a paye.
 */
class SavePollCategory
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Poll $poll, array $attributes, User $actor): PollCategory
    {
        /*
         * Une categorie ne s'ajoute pas a un scrutin clos.
         *
         * Elle n'aurait aucun votant possible, et apparaitrait dans le rapport
         * final comme une categorie a zero vote — un resultat qui ne veut rien
         * dire, et qu'il faudrait ensuite expliquer.
         */
        if ($poll->status->isClosed()) {
            throw new RuntimeException(
                'Un vote termine ou annule n\'accepte plus de nouvelle categorie.'
            );
        }

        return DB::transaction(function () use ($poll, $attributes, $actor) {
            $category = new PollCategory($this->sanitize($attributes));

            $category->poll_id = $poll->getKey();
            $category->position = $attributes['position']
                ?? ((int) $poll->categories()->max('position') + 1);

            $category->save();

            $this->audit->log(
                AuditAction::PollCategorySaved,
                $poll,
                [
                    'category_id' => $category->getKey(),
                    'name' => $category->name,
                    'created' => true,
                    'is_paid' => $category->is_paid,
                    'unit_price' => $category->unit_price,
                ],
                actor: $actor,
            );

            return $category->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(PollCategory $category, array $attributes, User $actor): PollCategory
    {
        $poll = $category->poll;

        if ($poll->status->isClosed()) {
            throw new RuntimeException('Un vote termine ou annule ne se modifie plus.');
        }

        return DB::transaction(function () use ($category, $poll, $attributes, $actor) {
            $clean = $this->sanitize($attributes);

            if ($this->termsAreFrozen($poll)) {
                /*
                 * Retire des attributs plutot que lever une exception.
                 *
                 * L'ecran n'affiche deja plus ces champs une fois le vote
                 * ouvert : une requete qui les porterait encore vient d'un
                 * onglet reste ouvert, pas d'une intention. Refuser tout
                 * l'enregistrement ferait perdre la correction de description
                 * que l'organisateur voulait vraiment.
                 */
                unset($clean['is_paid'], $clean['unit_price'], $clean['max_votes_per_voter'],
                    $clean['starts_at'], $clean['ends_at'], $clean['results_visibility']);
            }

            $category->fill($clean);

            $changed = array_keys($category->getDirty());
            $category->save();

            $this->audit->log(
                AuditAction::PollCategorySaved,
                $poll,
                [
                    'category_id' => $category->getKey(),
                    'name' => $category->name,
                    'changed' => $changed,
                ],
                actor: $actor,
            );

            return $category->refresh();
        });
    }

    /**
     * Supprime une categorie.
     *
     * Reservee au scrutin non publie. Des qu'un lien a circule, la suppression
     * emporterait les bulletins de la categorie — la desactivation existe pour
     * cela : elle ferme le vote de la categorie en laissant ses resultats
     * consultables.
     */
    public function delete(PollCategory $category, User $actor): void
    {
        $poll = $category->poll;

        if ($poll->isPublished()) {
            throw new RuntimeException(
                'Une categorie d\'un vote publie ne se supprime pas : desactivez-la, '
                .'ses resultats doivent rester consultables.'
            );
        }

        DB::transaction(function () use ($category, $poll, $actor) {
            $this->audit->log(
                AuditAction::PollCategorySaved,
                $poll,
                ['category_id' => $category->getKey(), 'name' => $category->name, 'deleted' => true],
                actor: $actor,
            );

            $category->delete();
        });
    }

    /**
     * Les termes economiques sont-ils figes ?
     *
     * Des que le vote est ouvert. Le jalon 9.3 pourra affiner en regardant s'il
     * existe reellement un bulletin — mais l'ouverture est le bon seuil par
     * defaut : c'est le moment ou les regles ont ete annoncees publiquement,
     * meme si personne n'a encore vote.
     */
    private function termsAreFrozen(Poll $poll): bool
    {
        return $poll->displayStatus()->acceptsBallots() || $poll->status->isClosed();
    }

    /**
     * Rend coherents gratuite et prix AVANT que la base ne s'en charge.
     *
     * La contrainte `CHECK` refuserait une categorie gratuite portant un prix,
     * mais elle le ferait par une exception SQL brute, illisible a l'ecran.
     * Cette normalisation traite le cas courant — l'organisateur bascule une
     * categorie payante en gratuite sans vider le champ prix — sans rien
     * relacher : la base reste le dernier mot.
     */
    private function sanitize(array $attributes): array
    {
        $clean = array_intersect_key($attributes, array_flip([
            'name', 'description', 'starts_at', 'ends_at', 'is_paid', 'unit_price',
            'max_votes_per_voter', 'results_visibility', 'position', 'is_active',
        ]));

        if (array_key_exists('is_paid', $clean) && ! $clean['is_paid']) {
            $clean['unit_price'] = 0;
        }

        // Zero ne veut pas dire « illimite » mais « aucun vote autorise », ce que
        // la base refuse. Un champ vide, lui, dit bien « pas de plafond ».
        if (array_key_exists('max_votes_per_voter', $clean) && ! $clean['max_votes_per_voter']) {
            $clean['max_votes_per_voter'] = null;
        }

        if (array_key_exists('results_visibility', $clean) && is_string($clean['results_visibility'])) {
            $clean['results_visibility'] = $clean['results_visibility'] === ''
                ? null
                : ResultsVisibility::from($clean['results_visibility']);
        }

        return $clean;
    }
}
