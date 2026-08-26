<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Point d'entree d'un evenement (section 29).
 *
 * Ne filtre RIEN. Il ne decide pas qu'un billet VIP doit passer par l'entree
 * VIP : la section 29 ne le prevoit pas, et l'inventer ferait refuser a
 * l'entree des porteurs parfaitement valides — le pire defaut possible pour un
 * controle d'acces. Il sert a savoir par ou quelqu'un est entre, et a repartir
 * les agents.
 */
class EntryPoint extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'event_id',
        'name',
        'description',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(EventAgent::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(AccessScan::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position')->orderBy('id');
    }
}
