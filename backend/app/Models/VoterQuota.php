<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compteur de votes d'un votant sur une categorie (sections 86.3, 86.5).
 *
 * CE MODELE NE SERT QU'A LIRE. L'increment passe par `VoteQuotaLedger`, qui
 * ecrit en SQL direct : un `$quota->used++` suivi d'un `save()` relirait la
 * valeur en memoire, et deux soumissions simultanees ecriraient la meme.
 * L'interet de cette table est precisement d'eviter cela.
 */
class VoterQuota extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'used' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PollCategory::class, 'poll_category_id');
    }
}
