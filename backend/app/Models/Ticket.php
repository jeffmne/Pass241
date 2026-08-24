<?php

namespace App\Models;

use App\Enums\TicketStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Billet (sections 18, 19).
 *
 * Emis uniquement apres confirmation du paiement (section 15.1) : la creation
 * est branchee au module de paiement.
 *
 * Le QR Code n'encode jamais l'identifiant present ici mais un jeton signe
 * (section 18) ; `reference` est l'identifiant lisible imprime sur le billet,
 * destine au support et a la recherche manuelle, pas un secret.
 */
class Ticket extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'event_id',
        'order_id',
        'order_item_id',
        'ticket_type_id',
        'reference',
        'holder_name',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'used_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $ticket) {
            /*
             * Section 19 : un billet utilise ne peut jamais l'etre une seconde
             * fois. Le garde-fou vit ici, au plus pres de la donnee, et non
             * seulement dans le service de scan : un futur import, une commande
             * artisan ou une correction manuelle ne passeront pas par ce
             * service, mais passeront par ce modele.
             */
            if ($ticket->getOriginal('status') === TicketStatus::Used->value
                && $ticket->isDirty('status')
                && $ticket->status !== TicketStatus::Used) {

                // Le remboursement reste possible : il n'annule pas l'entree
                // deja effectuee, il regle la question de l'argent.
                if ($ticket->status !== TicketStatus::Refunded) {
                    throw new RuntimeException(
                        'Un billet deja utilise ne peut pas redevenir valide.'
                    );
                }
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TicketStatus::Active->value);
    }

    public function allowsEntry(): bool
    {
        return $this->status->allowsEntry();
    }
}
