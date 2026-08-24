<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\Ticketing\StockHoldService;
use App\Support\Money;
use Database\Factories\TicketTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Type de billet (section 17) : Standard, VIP, Premium, Early Bird, ou libre.
 */
class TicketType extends Model
{
    /** @use HasFactory<TicketTypeFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'event_id',
        'name',
        'description',
        'price',
        'quantity',
        'sales_start_at',
        'sales_end_at',
        'max_per_order',
        'is_visible',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            // Entier de francs CFA, jamais un flottant (decision D-003).
            'price' => 'integer',
            'quantity' => 'integer',
            'max_per_order' => 'integer',
            'position' => 'integer',
            'sales_start_at' => 'datetime',
            'sales_end_at' => 'datetime',
            'is_visible' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /*
     * ---------------------------------------------------------------------
     * Stock
     * ---------------------------------------------------------------------
     */

    /**
     * Nombre de billets deja vendus.
     *
     * Seules les commandes effectivement payees comptent. Une commande en
     * attente n'a rien consomme : elle peut encore echouer ou expirer, et
     * decompter son contenu ferait afficher « complet » pour des billets qui
     * redeviendront disponibles cinq minutes plus tard.
     */
    public function soldCount(): int
    {
        /*
         * La portee par organisation est levee sur la sous-requete.
         *
         * Ce compte est lu par la page publique, ou le visiteur n'appartient a
         * aucune organisation. Sans cette levee, la sous-requete ne renverrait
         * aucune commande, le nombre de ventes serait de zero, et un evenement
         * complet afficherait des places disponibles.
         *
         * Aucune fuite possible : le decompte est deja borne aux lignes de CE
         * type de billet.
         */
        return (int) $this->orderItems()
            ->whereHas('order', fn (Builder $query) => $query->acrossOrganizations()->paid())
            ->sum('quantity');
    }

    /** Billets encore disponibles a la vente. */
    public function remainingStock(): int
    {
        return max(0, $this->quantity - $this->soldCount());
    }

    /**
     * Disponibilite reelle vue par un acheteur.
     *
     * Retire le dernier billet quand quelqu'un d'autre est en train de le
     * payer (section 17.1) : afficher « 1 restant » a un second acheteur qui
     * ne pourra pas aller au bout serait pire que d'afficher « complet ».
     */
    public function availableStock(): int
    {
        $remaining = $this->remainingStock();

        if ($remaining === 1 && app(StockHoldService::class)->isHeld($this)) {
            return 0;
        }

        return $remaining;
    }

    public function isSoldOut(): bool
    {
        return $this->availableStock() === 0;
    }

    /*
     * ---------------------------------------------------------------------
     * Disponibilite a la vente
     * ---------------------------------------------------------------------
     */

    public function salesHaveStarted(): bool
    {
        return $this->sales_start_at === null || $this->sales_start_at->isPast();
    }

    public function salesHaveEnded(): bool
    {
        return $this->sales_end_at !== null && $this->sales_end_at->isPast();
    }

    /**
     * Ce type de billet peut-il etre achete maintenant ?
     *
     * Quatre conditions, toutes necessaires : l'evenement est en vente, le type
     * est actif, la periode de vente court, et il reste du stock.
     */
    public function isPurchasable(): bool
    {
        return $this->is_active
            && $this->salesHaveStarted()
            && ! $this->salesHaveEnded()
            && ! $this->isSoldOut()
            && $this->event->allowsPurchase();
    }

    /** Raison pour laquelle l'achat est ferme, a afficher a l'acheteur. */
    public function unavailabilityReason(): ?string
    {
        if (! $this->event->allowsPurchase()) {
            return 'La vente n\'est pas ouverte.';
        }

        if (! $this->is_active) {
            return 'Ce billet n\'est pas en vente.';
        }

        if (! $this->salesHaveStarted()) {
            return 'Vente a partir du '
                .$this->sales_start_at->timezone(config('app.display_timezone'))->translatedFormat('j F \a\ H\hi');
        }

        if ($this->salesHaveEnded()) {
            return 'La vente est terminee.';
        }

        if ($this->isSoldOut()) {
            return 'Complet';
        }

        return null;
    }

    /**
     * Quantite maximale commandable en une fois : la limite fixee par
     * l'organisateur, bornee par ce qui reste reellement.
     */
    public function maxPurchasableQuantity(): int
    {
        return min($this->max_per_order, $this->availableStock());
    }

    /*
     * ---------------------------------------------------------------------
     * Presentation
     * ---------------------------------------------------------------------
     */

    public function isFree(): bool
    {
        return $this->price === 0;
    }

    public function formattedPrice(): string
    {
        return $this->isFree() ? 'Gratuit' : Money::format($this->price);
    }

    /** Types visibles sur la page publique de l'evenement. */
    public function scopePubliclyListed(Builder $query): Builder
    {
        return $query->where('is_visible', true)->orderBy('position')->orderBy('id');
    }
}
