<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\Ticketing\StockLedger;
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
    private ?array $displayStock = null;

    public function setDisplayStock(int $sold, int $reserved): void
    {
        $this->displayStock = compact('sold', 'reserved');
    }

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
     * Billets deja vendus.
     *
     * Le calcul vit dans StockLedger, pas ici : il est lu par la page publique
     * — ou le visiteur n'appartient a aucune organisation — comme par les
     * traitements differes, et doit donner le meme resultat partout,
     * independamment de toute portee (section 17.1, decision D-017).
     */
    public function soldCount(): int
    {
        return $this->displayStock['sold'] ?? app(StockLedger::class)->sold($this);
    }

    /**
     * Billets immobilises par des commandes en cours de paiement.
     *
     * Section 17.1 : toute commande reserve ses billets, et la reservation
     * expire d'elle-meme avec la commande.
     */
    public function reservedCount(): int
    {
        return $this->displayStock['reserved'] ?? app(StockLedger::class)->reserved($this);
    }

    /** Ce qu'un acheteur peut encore prendre, maintenant. */
    public function availableStock(): int
    {
        return $this->displayStock === null
            ? app(StockLedger::class)->available($this)
            : max(0, $this->quantity - $this->displayStock['sold'] - $this->displayStock['reserved']);
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
    public function isPurchasable(?int $availableStock = null): bool
    {
        return $this->is_active
            && $this->salesHaveStarted()
            && ! $this->salesHaveEnded()
            && ($availableStock ?? $this->availableStock()) > 0
            && (bool) $this->resolvedEvent()?->allowsPurchase();
    }

    /**
     * Evenement porteur, lisible hors portee d'organisation.
     *
     * L'acheteur n'appartient a aucune organisation : une simple lecture de la
     * relation reviendrait null sur la page publique, et ces deux methodes
     * planteraient sur un evenement inexistant. `Event::publicTicketTypes()`
     * rattache deja l'evenement pour cette raison — ce repli fait que
     * l'appelant n'a plus a y penser, et transforme une erreur fatale en
     * refus propre.
     */
    private function resolvedEvent(): ?Event
    {
        if ($this->relationLoaded('event') && $this->event) {
            return $this->event;
        }

        $event = Event::query()->acrossOrganizations()->find($this->event_id);

        if ($event) {
            $this->setRelation('event', $event);
        }

        return $event;
    }

    /** Raison pour laquelle l'achat est ferme, a afficher a l'acheteur. */
    public function unavailabilityReason(): ?string
    {
        // Echec en ferme : sans evenement lisible, la vente est fermee. Une
        // anomalie ne doit pas ouvrir un droit d'achat.
        if (! $this->resolvedEvent()?->allowsPurchase()) {
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
