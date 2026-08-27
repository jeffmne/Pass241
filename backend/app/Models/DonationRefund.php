<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remboursement, total ou partiel, d'un don (sections 46, 47).
 *
 * Une operation A PART, liee au don d'origine. Le don, lui, ne bouge pas : il
 * reste confirme, parce qu'il a bien eu lieu. C'est ce que la section 46
 * exige — une correction s'ajoute au passe, elle ne le reecrit pas.
 */
class DonationRefund extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'donation_id',
        'amount',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'refunded_at' => 'datetime',
        ];
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Le prestataire a-t-il execute le virement ? */
    public function isExecuted(): bool
    {
        return $this->refunded_at !== null;
    }

    public function formattedAmount(): string
    {
        return Money::format($this->amount);
    }
}
