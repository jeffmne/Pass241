<?php

namespace App\Models;

use Database\Factories\CandidateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Candidat d'une categorie (section 86.4).
 *
 * AUCUN COMPTEUR DE VOTES, ni en colonne, ni en attribut cache.
 *
 * L'organisateur peut ajouter, modifier ou desactiver un candidat — jamais
 * modifier son nombre de votes. Le total se lit par agregation SQL sur les
 * bulletins confirmes (`VotingStatistics`), comme le montant collecte d'une
 * campagne. On ne peut pas falsifier ce qui n'est stocke nulle part : la regle
 * n'est pas seulement interdite, elle est impossible.
 *
 * PAS DE PORTEE MULTI-TENANT ICI, pour la meme raison que `PollCategory` : le
 * candidat descend de la categorie, qui descend du scrutin, qui porte
 * l'organisation.
 */
class Candidate extends Model
{
    /** @use HasFactory<CandidateFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'biography',
        'number',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PollCategory::class, 'poll_category_id');
    }

    /**
     * Categorie porteuse, SANS DEPENDRE DU CHARGEMENT DIFFERE.
     *
     * Le produit interdit le chargement differe hors production, precisement
     * pour faire apparaitre les N+1 pendant le developpement. Un candidat
     * charge par `with('candidates')` ignore d'ou il vient : toute methode qui
     * remontait a `$this->category` levait donc une violation en developpement,
     * et faisait une requete par candidat en production.
     *
     * Le repli lit la categorie une fois et la retient sur l'instance. Les
     * appelants qui posent deja la relation — la page publique le fait — n'en
     * paient rien ; ceux qui ne le font pas obtiennent quand meme une reponse
     * juste. C'est le pendant de `PollCategory::resolvedPoll()`, pour l'etage
     * du dessous.
     */
    public function resolvedCategory(): ?PollCategory
    {
        if ($this->relationLoaded('category') && $this->category) {
            return $this->category;
        }

        $category = PollCategory::query()->find($this->poll_category_id);

        if ($category) {
            $this->setRelation('category', $category);
        }

        return $category;
    }

    /**
     * Le candidat peut-il encore recevoir un vote ?
     *
     * Un candidat desactive reste VISIBLE sur la page publique et dans le
     * classement : ses votes deja exprimes comptent toujours, et les faire
     * disparaitre fausserait le total de la categorie. Il ne peut simplement
     * plus en recevoir de nouveaux.
     */
    public function acceptsBallots(): bool
    {
        // Echec en ferme : sans categorie lisible, le vote est ferme.
        return $this->is_active && (bool) $this->resolvedCategory()?->acceptsBallots();
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    /** « 7 — Awa Ndong », ou le nom seul si le concours ne numerote pas. */
    public function displayName(): string
    {
        return $this->number !== null
            ? $this->number.' — '.$this->name
            : $this->name;
    }
}
