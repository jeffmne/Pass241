<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Affectation d'un agent a un evenement (sections 27.2, 30).
 *
 * « Un agent ne voit que les evenements auxquels il est affecte. » Cette ligne
 * EST cette regle : sans elle, l'application ne telecharge rien et le scan est
 * refuse.
 *
 * La revocation se fait par desactivation, jamais par suppression : cette ligne
 * est ce qui explique pourquoi tel agent a scanne tel billet il y a six mois
 * (section 67).
 */
class EventAgent extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'event_id',
        'user_id',
        'entry_point_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function entryPoint(): BelongsTo
    {
        return $this->belongsTo(EntryPoint::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function revoke(): void
    {
        $this->forceFill(['is_active' => false, 'revoked_at' => now()])->save();
    }
}
