<?php

namespace App\Models;

use App\Enums\BallotStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\BallotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bulletin (sections 86.4, 86.5).
 *
 * `Ballot` et non `Vote` : en francais, « vote » designe a la fois le scrutin et
 * le bulletin.
 *
 * AUCUN ATTRIBUT N'EST MODIFIABLE EN MASSE. `$fillable` est vide, et c'est
 * volontaire : un bulletin se cree par `CastFreeBallot` ou `CreatePaidBallot`,
 * jamais par affectation depuis une requete. La base tient la meme regle par un
 * declencheur — candidat, quantite et montant sont definitifs apres creation.
 *
 * Le contrat `Payable` est implemente au jalon 9.4, quand les votes payants
 * arrivent.
 */
class Ballot extends Model
{
    /** @use HasFactory<BallotFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * VIDE, et c'est le point.
     *
     * Les valeurs qui decident d'une voix et d'un montant ne viennent jamais
     * d'un formulaire : elles sont derivees au serveur depuis la categorie
     * (section 86.5, point 4). Un `$fillable` complaisant laisserait un jour
     * passer un `amount` envoye par le navigateur.
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'amount' => 'integer',
            'status' => BallotStatus::class,
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(PollCategory::class, 'poll_category_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /*
     * ---------------------------------------------------------------------
     * Requetes
     * ---------------------------------------------------------------------
     */

    /**
     * Les bulletins qui COMPTENT dans les voix.
     *
     * Les rembourses en font partie : le remboursement rend l'argent, il ne
     * retire pas la voix. Voir `BallotStatus::countsAsVote()` — c'est la que le
     * raisonnement est ecrit, et il n'a pas a etre redit a chaque requete.
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BallotStatus::Confirmed->value,
            BallotStatus::Refunded->value,
        ]);
    }

    /** Les bulletins qui ont rapporte de l'argent non rendu. */
    public function scopeEarning(Builder $query): Builder
    {
        return $query->where('status', BallotStatus::Confirmed->value);
    }

    /*
     * ---------------------------------------------------------------------
     * Etat
     * ---------------------------------------------------------------------
     */

    public function isConfirmed(): bool
    {
        return $this->status === BallotStatus::Confirmed;
    }

    public function isPending(): bool
    {
        return $this->status === BallotStatus::Pending;
    }

    /** Un vote gratuit n'a rien coute : il ne passe par aucun paiement. */
    public function isFree(): bool
    {
        return $this->amount === 0;
    }
}
