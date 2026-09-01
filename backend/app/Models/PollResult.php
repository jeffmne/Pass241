<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resultat fige d'un candidat, a la cloture (sections 86.4, 86.5).
 *
 * SEULE TABLE DU MODULE QUI STOCKE UN NOMBRE DE VOIX. Ailleurs, tout est
 * agrege en SQL sur les bulletins pour qu'il n'y ait rien a falsifier ; ici, on
 * CONSERVE ce calcul a un instant precis, parce qu'un classement proclame doit
 * cesser de bouger.
 *
 * Aucun attribut n'est modifiable en masse, et la base refuse toute
 * modification comme toute suppression. Ce modele ne sert donc qu'a lire et a
 * inserer : il n'expose deliberement aucune methode d'ecriture.
 */
class PollResult extends Model
{
    use BelongsToOrganization;

    /**
     * VIDE, et c'est le point.
     *
     * Un resultat se fige par `PollResultsFreezer`, qui le derive des bulletins
     * — jamais par affectation depuis une requete. Un `$fillable` complaisant
     * laisserait un jour passer un nombre de voix venu d'ailleurs.
     */
    protected $fillable = [];

    /**
     * AUCUN HORODATAGE AUTOMATIQUE.
     *
     * `frozen_at` est le seul, et il porte un sens que `created_at` n'aurait
     * pas : l'instant ou le classement a ete PROCLAME. Les deux cote a cote
     * seraient la meme date sous deux noms, et le second laisserait croire
     * qu'une modification est possible — la table est en ajout seul.
     */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'votes' => 'integer',
            'ballots' => 'integer',
            'revenue' => 'integer',
            'share_basis_points' => 'integer',
            'frozen_at' => 'datetime',
        ];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PollCategory::class, 'poll_category_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** Le gagnant de sa categorie. */
    public function isWinner(): bool
    {
        return $this->rank === 1;
    }

    /**
     * Part des voix, en pourcentage.
     *
     * Stockee en centiemes de point pour rester entiere en base
     * (decision D-003) : 4235 se lit 42,35 %.
     */
    public function sharePercentage(): float
    {
        return $this->share_basis_points / 100;
    }
}
