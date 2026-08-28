<?php

namespace App\Models;

use App\Enums\ResultsVisibility;
use Carbon\CarbonInterface;
use Database\Factories\PollCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Categorie d'un scrutin (section 86.4).
 *
 * LES CATEGORIES SONT INDEPENDANTES ENTRE ELLES. Fenetre de vote, gratuite,
 * prix, plafond par votant et visibilite des resultats se reglent categorie par
 * categorie — un meme evenement peut porter un prix du public gratuit en direct
 * et une election principale payante scellee jusqu'a la fin.
 *
 * PAS DE PORTEE MULTI-TENANT ICI. Une categorie n'a pas de `organization_id` :
 * elle descend du scrutin, qui en porte un. Ajouter la colonne creerait une
 * seconde verite sur l'appartenance, qu'il faudrait ensuite tenir synchronisee.
 * Les requetes passent donc par `Poll`, deja filtre — sauf sur les chemins
 * publics, ou la portee est levee volontairement.
 *
 * L'HERITAGE DU SCRUTIN se lit ici, jamais recopie en base : `starts_at`,
 * `ends_at` et `results_visibility` restent nuls tant que la categorie n'a pas
 * de reglage propre. Une copie se desynchroniserait le jour ou l'organisateur
 * decale la fenetre du scrutin.
 */
class PollCategory extends Model
{
    /** @use HasFactory<PollCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'starts_at',
        'ends_at',
        'is_paid',
        'unit_price',
        'max_votes_per_voter',
        'results_visibility',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_paid' => 'boolean',
            'unit_price' => 'integer',
            'max_votes_per_voter' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
            'results_visibility' => ResultsVisibility::class,
        ];
    }

    /*
     * ---------------------------------------------------------------------
     * Relations
     * ---------------------------------------------------------------------
     */

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class)->orderBy('position')->orderBy('id');
    }

    public function activeCandidates(): HasMany
    {
        return $this->candidates()->where('is_active', true);
    }

    /*
     * ---------------------------------------------------------------------
     * Fenetre de vote — heritee ou propre
     * ---------------------------------------------------------------------
     */

    public function effectiveStartsAt(): CarbonInterface
    {
        return $this->starts_at ?? $this->poll->starts_at;
    }

    public function effectiveEndsAt(): CarbonInterface
    {
        return $this->ends_at ?? $this->poll->ends_at;
    }

    /** La categorie regle-t-elle sa propre fenetre ? */
    public function hasOwnWindow(): bool
    {
        return $this->starts_at !== null;
    }

    /**
     * La categorie accepte-t-elle un bulletin a cet instant precis ?
     *
     * LE SCRUTIN DECIDE EN PREMIER. Une categorie ne peut jamais ouvrir ce que
     * le scrutin a ferme : sa fenetre propre ne fait que RESTREINDRE. Sans cette
     * subordination, une categorie mal reglee laisserait voter apres la cloture
     * du concours, et le classement fige n'aurait plus de sens.
     */
    public function acceptsBallots(): bool
    {
        if (! $this->is_active || ! $this->poll->acceptsBallots()) {
            return false;
        }

        $now = now();

        return $this->effectiveStartsAt()->lessThanOrEqualTo($now)
            && $this->effectiveEndsAt()->greaterThan($now);
    }

    /**
     * Pourquoi le vote est-il ferme, ou null s'il est ouvert.
     *
     * Rendre une phrase et non un booleen : elle remonte telle quelle a l'ecran.
     * « Les votes de cette categorie ouvrent le 3 mars » renseigne la ou un
     * `false` laisse le visiteur chercher ce qu'il a mal fait.
     */
    public function closureReason(): ?string
    {
        if ($this->acceptsBallots()) {
            return null;
        }

        if (! $this->is_active) {
            return 'Cette categorie n\'est pas ouverte au vote.';
        }

        if (! $this->poll->acceptsBallots()) {
            return $this->poll->hasLapsed()
                ? 'Les votes sont termines.'
                : 'Les votes ne sont pas encore ouverts.';
        }

        if ($this->effectiveStartsAt()->isFuture()) {
            return sprintf(
                'Les votes de cette categorie ouvrent le %s.',
                $this->effectiveStartsAt()->timezone(config('app.display_timezone'))->format('d/m/Y a H\hi')
            );
        }

        return 'Les votes de cette categorie sont termines.';
    }

    /*
     * ---------------------------------------------------------------------
     * Resultats
     * ---------------------------------------------------------------------
     */

    public function effectiveResultsVisibility(): ResultsVisibility
    {
        return $this->results_visibility ?? $this->poll->results_visibility;
    }

    /** Le public peut-il voir le classement de CETTE categorie, maintenant ? */
    public function showsPublicResults(): bool
    {
        return $this->effectiveResultsVisibility()->isPublicFor($this->poll->displayStatus());
    }

    /*
     * ---------------------------------------------------------------------
     * Modele economique
     * ---------------------------------------------------------------------
     */

    /**
     * Prix a payer pour `n` votes.
     *
     * CALCULE ICI, JAMAIS RECU DU NAVIGATEUR (section 86.5, point 4). Une
     * quantite venue du client est une intention ; le montant, lui, se derive du
     * prix unitaire en base. Confondre les deux laisserait un bulletin fixer
     * lui-meme ce qu'il coute.
     */
    public function priceFor(int $quantity): int
    {
        return $this->is_paid ? $this->unit_price * max(0, $quantity) : 0;
    }

    /** Le plafond par votant, ou null si les votes sont illimites (section 86.3). */
    public function voteLimit(): ?int
    {
        return $this->max_votes_per_voter;
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
