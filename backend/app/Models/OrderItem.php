<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de commande (section 20).
 *
 * Ne porte pas le trait BelongsToOrganization : son isolation vient de la
 * commande a laquelle elle appartient, et toute lecture passe par elle.
 *
 * `ticket_type_name` et `unit_price` sont des instantanes pris a l'achat. Si
 * l'organisateur renomme son billet ou change son tarif, cette ligne doit
 * continuer a dire ce qui a ete achete et paye ce jour-la.
 */
class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'ticket_type_id',
        'ticket_type_name',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'subtotal' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function formattedUnitPrice(): string
    {
        return $this->unit_price === 0 ? 'Gratuit' : Money::format($this->unit_price);
    }

    public function formattedSubtotal(): string
    {
        return Money::format($this->subtotal);
    }
}
