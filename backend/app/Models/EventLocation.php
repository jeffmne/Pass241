<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lieu d'un evenement (sections 7, 11).
 *
 * Ne porte pas le trait BelongsToOrganization : il n'a pas de colonne
 * `organization_id`, son isolation vient de l'evenement auquel il appartient.
 * Toute lecture passe par la relation, donc par la portee de `Event`.
 */
class EventLocation extends Model
{
    protected $fillable = [
        'event_id',
        'name',
        'address',
        'city',
        'country',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Adresse sur une ligne, telle qu'on la lit sur un billet ou qu'on la
     * dicte a un taximan.
     */
    public function fullAddress(): string
    {
        return implode(', ', array_filter([
            $this->name,
            $this->address,
            $this->city,
        ]));
    }

    public function shortAddress(): string
    {
        return implode(', ', array_filter([$this->name, $this->city]));
    }
}
