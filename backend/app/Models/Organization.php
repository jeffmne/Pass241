<?php

namespace App\Models;

use App\Enums\KycStatus;
use App\Enums\OrganizationType;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unite d'isolation du SaaS (section 9) et titulaire des reversements
 * (section 21.2).
 *
 * Ce modele ne porte pas le trait BelongsToOrganization : il est
 * l'organisation. Son acces est controle par l'appartenance verifiee
 * (OrganizationMember), pas par une portee globale.
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'contact_email',
        'contact_phone',
    ];

    /**
     * `kyc_status`, `suspended_at` et `owner_id` sont volontairement absents de
     * $fillable : ces valeurs ne changent que par des methodes dediees et
     * auditees, jamais par affectation en masse depuis un formulaire
     * (sections 10.1, 67).
     */
    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'kyc_status' => KycStatus::class,
            'kyc_submitted_at' => 'datetime',
            'kyc_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->withPivot(['joined_at', 'revoked_at'])
            ->wherePivotNull('revoked_at');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Section 10.1 : le reversement est suspendu tant que la verification
     * d'identite n'est pas aboutie. Les ventes et les dons, eux, ne sont
     * jamais bloques — les fonds continuent d'etre encaisses et comptabilises.
     */
    public function canReceivePayouts(): bool
    {
        return $this->kyc_status->allowsPayout() && ! $this->isSuspended();
    }

    public function needsKycAttention(): bool
    {
        return in_array($this->kyc_status, [KycStatus::Pending, KycStatus::Rejected], true);
    }
}
