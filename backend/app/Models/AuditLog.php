<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Entree du journal d'audit (section 68).
 *
 * En ajout seul. La base de donnees le fait respecter par des declencheurs
 * (voir la migration) ; les garde-fous ci-dessous produisent simplement une
 * erreur plus lisible cote applicatif, avant meme d'atteindre PostgreSQL.
 *
 * Volontairement sans portee globale par organisation : le journal doit rester
 * consultable par le Super Admin (section 58) et contient des lignes anterieures
 * a tout contexte d'organisation (connexion, inscription). Le filtrage se fait
 * explicitement par `forOrganization()`.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Une entree du journal d\'audit ne peut pas etre modifiee (section 68).'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Une entree du journal d\'audit ne peut pas etre supprimee (section 68).'
            );
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForOrganization($query, Organization|int $organization)
    {
        return $query->where(
            'organization_id',
            $organization instanceof Organization ? $organization->getKey() : $organization
        );
    }

    public function scopeAction($query, AuditAction $action)
    {
        return $query->where('action', $action->value);
    }
}
