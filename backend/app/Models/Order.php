<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Commande (section 20).
 *
 * Le montant, une fois la commande payee, est une donnee financiere figee :
 * il ne se corrige pas par une mise a jour mais par un remboursement, seul
 * mecanisme tracable (sections 46, 67).
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'event_id',
        'first_name',
        'last_name',
        'email',
        'payment_provider',
        'payer_phone',
        'amount',
        'expires_at',
        'ip_address',
    ];

    protected $hidden = [
        // Donnees de paiement, privees (section 52). Les exclure par defaut
        // evite qu'elles partent dans une reponse JSON par simple oubli.
        'payer_phone',
        'email',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => OrderStatus::class,
            'payment_provider' => PaymentProvider::class,
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $order) {
            /*
             * Section 67 : le montant d'une commande payee n'est pas
             * modifiable. Une correction financiere passe par un
             * remboursement, qui laisse une trace, jamais par un UPDATE qui
             * n'en laisse aucune.
             */
            if ($order->isDirty('amount') && $order->getOriginal('paid_at') !== null) {
                throw new RuntimeException(
                    'Le montant d\'une commande payee ne peut pas etre modifie. '
                    .'Utiliser un remboursement.'
                );
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /*
     * ---------------------------------------------------------------------
     * Requetes
     * ---------------------------------------------------------------------
     */

    /** Commandes dont l'argent est encaisse — celles qui consomment du stock. */
    public function scopePaid(Builder $query): Builder
    {
        return $query->whereIn('status', [
            OrderStatus::Paid->value,
            OrderStatus::PartiallyRefunded->value,
        ]);
    }

    /** Commandes en attente arrivees a echeance, a faire expirer. */
    public function scopeExpirable(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /*
     * ---------------------------------------------------------------------
     * Etat
     * ---------------------------------------------------------------------
     */

    public function isPending(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    public function hasExpired(): bool
    {
        return $this->isPending()
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /** Secondes restantes pour valider le paiement, jamais negatives. */
    public function secondsBeforeExpiry(): int
    {
        if (! $this->isPending() || $this->expires_at === null) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }

    public function totalTickets(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function buyerName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function formattedAmount(): string
    {
        return Money::format($this->amount);
    }
}
