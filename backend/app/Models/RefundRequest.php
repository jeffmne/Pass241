<?php

namespace App\Models;

use App\Enums\RefundOrigin;
use App\Enums\RefundStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Demande de remboursement (section 24.1, decision D-027).
 *
 * Le client demande, l'organisateur decide. Cette ligne porte la decision et
 * sa trace, pas l'operation financiere : le remboursement lui-meme est une
 * transaction distincte, liee a la commande d'origine, jamais une modification
 * du paiement initial (section 46).
 */
class RefundRequest extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'order_id',
        'ticket_id',
        'reference',
        'status',
        'origin',
        'amount',
        'psp_fee_amount',
        'includes_psp_fee',
        'reason',
        'ip_address',
    ];

    protected $hidden = [
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'origin' => RefundOrigin::class,
            'amount' => 'integer',
            'includes_psp_fee' => 'boolean',
            'psp_fee_amount' => 'integer',
            'decided_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $request) {
            /*
             * Une decision ne se reprend pas.
             *
             * Sans ce garde-fou, un organisateur pourrait refuser, puis
             * accepter, puis refuser a nouveau, et le client n'aurait aucun
             * moyen de savoir ce qui s'est reellement passe. Une decision
             * changee d'avis passe par une NOUVELLE demande, qui laisse sa
             * propre trace.
             */
            // `getRawOriginal` et non `getOriginal` : avec un cast d'enum, ce
            // dernier rend l'instance, jamais la chaine — la comparaison
            // echouait donc toujours, et le garde-fou bloquait la premiere
            // decision au lieu de la seconde.
            if ($request->getRawOriginal('status') !== RefundStatus::Requested->value
                && $request->isDirty('status')) {
                throw new RuntimeException(
                    'Une demande de remboursement deja tranchee ne peut pas etre rouverte. '
                    .'Le client doit soumettre une nouvelle demande.'
                );
            }

            if ($request->isDirty(['amount', 'order_id', 'ticket_id'])) {
                throw new RuntimeException(
                    'Le montant et l\'objet d\'une demande de remboursement sont figes.'
                );
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', RefundStatus::Requested->value);
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isApproved(): bool
    {
        return $this->status === RefundStatus::Approved;
    }

    /** Porte sur un billet precis, et non sur toute la commande. */
    public function isPartial(): bool
    {
        return $this->ticket_id !== null;
    }

    public function formattedAmount(): string
    {
        return Money::format($this->amount);
    }

    public function formattedPspFee(): string
    {
        return Money::format($this->psp_fee_amount);
    }

    /**
     * Part correspondant au prix des billets, hors frais.
     *
     * C'est elle, et jamais le montant rendu, qui sert d'assiette a
     * l'ajustement de commission : les frais du prestataire n'ont jamais
     * transite par l'organisateur (decision D-023).
     */
    public function ticketPortion(): int
    {
        return $this->includes_psp_fee
            ? $this->amount - $this->psp_fee_amount
            : $this->amount;
    }
}
