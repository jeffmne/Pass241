<?php

namespace App\Models;

use App\Enums\CommissionType;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Commission prelevee par la plateforme (section 21.2).
 *
 * En ajout seul. Des declencheurs PostgreSQL le font respecter (voir la
 * migration) ; les garde-fous ci-dessous produisent une erreur plus lisible
 * cote applicatif, avant d'atteindre la base.
 *
 * La plateforme n'est jamais depositaire des fonds (section 21.2) : cette
 * ligne dit ce qui a ete retenu et ce qui revient a l'organisateur, elle ne
 * represente pas un solde detenu.
 */
class Commission extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'commissionable_type',
        'commissionable_id',
        'parent_commission_id',
        'type',
        'gross_amount',
        'rate_basis_points',
        'commission_amount',
        'net_amount',
    ];

    protected function casts(): array
    {
        return [
            'type' => CommissionType::class,
            'gross_amount' => 'integer',
            'rate_basis_points' => 'integer',
            'commission_amount' => 'integer',
            'net_amount' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Une commission ne peut pas etre modifiee. Une correction passe par '
                .'une ligne liee au remboursement (section 46).'
            );
        });

        static::deleting(function () {
            throw new RuntimeException('Une commission ne peut pas etre supprimee.');
        });
    }

    public function commissionable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Commission d'origine, quand cette ligne est un ajustement. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_commission_id');
    }

    /** Ajustements lies a cette commission (remboursements, section 24.1). */
    public function adjustments(): HasMany
    {
        return $this->hasMany(self::class, 'parent_commission_id');
    }

    /**
     * Cette ligne corrige-t-elle une commission anterieure ?
     *
     * Une ligne d'origine represente ce qui a ete preleve ; un ajustement
     * represente ce qui a ete rendu. Les additionner sans distinguer les deux
     * doublerait le chiffre d'affaires de la plateforme.
     */
    public function isAdjustment(): bool
    {
        return $this->parent_commission_id !== null;
    }

    /** Ne garde que les lignes d'origine. */
    public function scopeOriginals(Builder $query): Builder
    {
        return $query->whereNull('parent_commission_id');
    }

    /** Taux applique a cette transaction, tel qu'il etait ce jour-la. */
    public function formattedRate(): string
    {
        $percent = $this->rate_basis_points / 100;

        return rtrim(rtrim(number_format($percent, 2, ',', ' '), '0'), ',').' %';
    }

    public function formattedCommission(): string
    {
        return Money::format($this->commission_amount);
    }

    public function formattedNet(): string
    {
        return Money::format($this->net_amount);
    }
}
