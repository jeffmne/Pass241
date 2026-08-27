<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\CommissionType;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Jobs\SendTicketsEmail;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\Payments\Payable;
use App\Services\Ticketing\StockLedger;
use App\Services\Ticketing\TicketIssuer;
use App\Support\Money;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Commande (section 20).
 *
 * Le montant, une fois la commande payee, est une donnee financiere figee :
 * il ne se corrige pas par une mise a jour mais par un remboursement, seul
 * mecanisme tracable (sections 46, 67).
 *
 * `Payable` (decision D-041) : la commande est encaissee par le meme chemin
 * qu'un don ou, demain, qu'un vote payant. Tout ce qui suit la section
 * « Encaissement » est ce que ce chemin partage ne peut pas savoir — le stock
 * et les billets n'existent que dans la billetterie.
 */
class Order extends Model implements Payable
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
        'subtotal',
        'psp_fee_amount',
        'psp_fee_basis_points',
        'psp_fee_fixed',
        'psp',
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
            'subtotal' => 'integer',
            'psp_fee_amount' => 'integer',
            'psp_fee_basis_points' => 'integer',
            'psp_fee_fixed' => 'integer',
            'origin' => OrderOrigin::class,
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
            $amounts = ['amount', 'subtotal', 'psp_fee_amount'];

            if ($order->isDirty($amounts) && $order->getOriginal('paid_at') !== null) {
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

    /**
     * Commission retenue par la plateforme (section 21.2).
     *
     * La ligne D'ORIGINE seulement : depuis la decision D-027, un
     * remboursement ajoute un ajustement partageant la meme transaction, et
     * les additionner doublerait le chiffre d'affaires.
     */
    public function commission(): MorphOne
    {
        return $this->morphOne(Commission::class, 'commissionable')
            ->whereNull('parent_commission_id');
    }

    /** Demandes de remboursement portant sur cette commande (section 24.1). */
    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    /**
     * Adresse publique de suivi de la commande.
     *
     * Signee et sans expiration (decision D-004) : l'acheteur n'a pas de
     * compte, il garde ce lien. La signature evite qu'on parcoure les
     * commandes en devinant des references.
     */
    /**
     * Adresse du recu de paiement, signee et sans expiration.
     *
     * Meme raison que pour le suivi de commande : l'acheteur n'a pas de
     * compte, et la signature empeche de parcourir les recus en devinant des
     * references (decision D-004).
     */
    public function receiptUrl(): string
    {
        return URL::signedRoute('orders.receipt', ['reference' => $this->reference]);
    }

    public function statusUrl(): string
    {
        return URL::signedRoute('orders.show', ['reference' => $this->reference]);
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

    /**
     * Recherche par reference pour les pages publiques.
     *
     * La levee de portee est repetee sur chaque relation chargee : un
     * `with('event.location')` simple appliquerait la portee globale a
     * l'evenement, qui reviendrait null hors contexte d'organisation — et la
     * page planterait sur une propriete manquante.
     */
    public static function publicByReference(string $reference): ?self
    {
        return static::query()
            ->acrossOrganizations()
            ->with(self::publicRelations())
            ->where('reference', $reference)
            ->first();
    }

    /**
     * Relations necessaires a l'affichage public d'une commande, toutes hors
     * portee d'organisation.
     *
     * @return array<string, callable|string>
     */
    public static function publicRelations(): array
    {
        return [
            'items',
            'event' => fn ($query) => $query->acrossOrganizations()->with(['location', 'organization']),
            'tickets' => fn ($query) => $query->acrossOrganizations()->with('orderItem'),
        ];
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

    /**
     * L'argent a-t-il ete encaisse un jour ?
     *
     * A distinguer de `isPaid()` : une commande remboursee n'est plus payee,
     * mais elle l'a ete. C'est cette question-la que pose un recu — attester
     * un paiement qui a eu lieu, meme s'il a ete rendu depuis
     * (decision D-029).
     */
    public function hasBeenPaid(): bool
    {
        return $this->paid_at !== null;
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

    /** Montant reellement debite : billets et frais du prestataire. */
    public function formattedAmount(): string
    {
        return Money::format($this->amount);
    }

    /** Prix des billets seuls, assiette de la commission (decision D-023). */
    public function formattedSubtotal(): string
    {
        return Money::format($this->subtotal);
    }

    public function formattedPspFee(): string
    {
        return Money::format($this->psp_fee_amount);
    }

    public function hasPspFee(): bool
    {
        return $this->psp_fee_amount > 0;
    }

    /*
     * ---------------------------------------------------------------------
     * Encaissement (contrat Payable, decision D-041)
     *
     * Ce que `ConfirmPayment` ne peut pas savoir : le stock a reverifier, les
     * billets a emettre, l'email a envoyer. Le reste — idempotence, verrou,
     * commission, journal — vit une seule fois, dans l'action partagee.
     * ---------------------------------------------------------------------
     */

    public function paymentReference(): string
    {
        return $this->reference;
    }

    public function paymentAmount(): int
    {
        return (int) $this->amount;
    }

    public function providerReference(): ?string
    {
        return $this->provider_reference;
    }

    public function payerPhone(): ?string
    {
        return $this->payer_phone;
    }

    public function paymentOperator(): ?PaymentProvider
    {
        return $this->payment_provider;
    }

    /**
     * Assiette de la commission : le prix des billets seuls.
     *
     * JAMAIS `amount`, qui contient en plus les frais du prestataire avances
     * par l'acheteur (decision D-023). Les confondre ferait prelever a Pass241
     * une commission sur l'argent d'un tiers, et gonflerait mecaniquement le
     * net reverse a l'organisateur.
     */
    public function commissionBase(): int
    {
        return (int) $this->subtotal;
    }

    public function commissionType(): CommissionType
    {
        return CommissionType::Ticketing;
    }

    public function paymentOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function isPaymentConfirmed(): bool
    {
        return $this->isPaid();
    }

    public function isPaymentPending(): bool
    {
        return $this->isPending();
    }

    public function paymentRefusalReason(): ?string
    {
        if ($this->status === OrderStatus::Cancelled || $this->status === OrderStatus::Refunded) {
            return sprintf('Une commande %s ne peut plus etre payee.', $this->status->label());
        }

        return null;
    }

    public function lockedForPayment(): ?static
    {
        $fresh = static::query()
            ->acrossOrganizations()
            ->lockForUpdate()
            ->find($this->getKey());

        // Les lignes sont rechargees avec leur type de billet : la verification
        // de stock et l'emission en ont besoin, et les relire une par une
        // multiplierait les requetes sous verrou.
        $fresh?->setRelation('items', $this->items()->with('ticketType')->get());

        return $fresh;
    }

    /**
     * Section 17.1, second niveau de protection.
     *
     * La reservation de cette commande a pu expirer entre le moment ou
     * l'acheteur a valide sur son telephone et celui ou le callback est arrive.
     * Le stock est donc reverifie, sous verrou, avant toute emission.
     */
    public function guardPaymentConfirmation(): void
    {
        $stock = app(StockLedger::class);

        foreach ($this->items as $item) {
            $ticketType = $item->ticketType
                ?? TicketType::query()->acrossOrganizations()->find($item->ticket_type_id);

            if (! $ticketType) {
                throw new RuntimeException('Un des billets de cette commande n\'existe plus.');
            }

            $stock->commit($ticketType, $item->quantity, $this->getKey());
        }
    }

    public function applyPaymentConfirmed(?string $providerReference): void
    {
        $this->forceFill([
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
            'provider_reference' => $providerReference ?? $this->provider_reference,
            'expires_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
        ])->save();

        // Emission par le service partage : une invitation acceptee produit
        // exactement les memes billets (decision D-039).
        $this->issuedTicketCount = app(TicketIssuer::class)->issue($this);
    }

    /**
     * Envoie la copie du billet, si une adresse a ete renseignee (section 69).
     *
     * Delegue a un job : l'email porte le billet en PDF, dont le rendu coute
     * trop cher pour le webhook de l'operateur, qui abandonne s'il attend.
     * L'email reste une COMMODITE — le billet est deja emis et consultable.
     */
    public function afterPaymentConfirmed(): void
    {
        if (! $this->email) {
            return;
        }

        SendTicketsEmail::dispatch($this->getKey());
    }

    public function applyPaymentFailed(string $reason): void
    {
        $this->forceFill([
            'status' => OrderStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ])->save();
    }

    /** Billets emis lors de la confirmation, pour le journal. */
    private int $issuedTicketCount = 0;

    public function paymentAuditContext(): array
    {
        return [
            'reference' => $this->reference,
            'amount' => $this->amount,
            'tickets' => $this->issuedTicketCount,
            'provider_reference' => $this->provider_reference,
        ];
    }

    public function confirmedAuditAction(): AuditAction
    {
        return AuditAction::PaymentConfirmed;
    }

    public function failedAuditAction(): AuditAction
    {
        return AuditAction::PaymentFailed;
    }
}
