<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un reglage de la plateforme (section 58).
 *
 * Ne porte PAS le trait multi-tenant, et c'est volontaire : ces valeurs
 * appartiennent a la plateforme, pas a une organisation. C'est d'ailleurs le
 * seul modele metier dans ce cas avec `Organization` elle-meme.
 *
 * Rien ne se lit directement sur ce modele dans le reste du produit : tout
 * passe par `PlatformSettings`, qui applique la retombee sur la configuration
 * et la mise en cache.
 */
class PlatformSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
