<?php

namespace App\Models;

use App\Enums\TicketStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\Ticketing\TicketTokenService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;
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

    /**
     * Ligne de commande d'origine.
     *
     * C'est elle qui porte le prix reellement paye et le nom du type au moment
     * de l'achat — pas le type de billet, qui a pu changer depuis.
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * Recherche par reference pour l'affichage public.
     *
     * Sort de la portee par organisation : le porteur du billet n'appartient a
     * aucune organisation. L'acces reste protege par la signature de l'URL.
     */
    public static function publicByReference(string $reference): ?self
    {
        return static::query()
            ->acrossOrganizations()
            // La levee de portee doit etre repetee sur CHAQUE relation chargee.
            // Un `with('event.location')` simple appliquerait la portee globale
            // a l'evenement, qui reviendrait null — et la page planterait sur
            // une propriete manquante plutot que d'afficher le billet.
            ->with([
                'event' => fn ($query) => $query->acrossOrganizations()->with(['location', 'organization']),
                'orderItem',
                'order' => fn ($query) => $query->acrossOrganizations(),
            ])
            ->where('reference', $reference)
            ->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TicketStatus::Active->value);
    }

    public function allowsEntry(): bool
    {
        return $this->status->allowsEntry();
    }

    /**
     * Contenu du QR Code : une charge utile signee (section 18).
     *
     * Calcule a la demande plutot que stocke. Le jeton est une fonction pure
     * des identifiants du billet : le conserver en base ajouterait une colonne
     * a maintenir, et un risque de divergence, sans rien apporter.
     */
    public function qrToken(): string
    {
        return app(TicketTokenService::class)->issue($this);
    }

    /**
     * Empreinte du jeton, pour la validation hors connexion (section 27.7).
     *
     * L'application agent telecharge cette empreinte, JAMAIS le jeton. La
     * difference est tout sauf theorique : un telephone d'agent se perd et se
     * vole. Avec les jetons, le voleur repart avec de quoi fabriquer tous les
     * QR de l'evenement ; avec les empreintes, il ne peut rien en faire —
     * elles servent a reconnaitre un billet, pas a en creer un.
     *
     * L'agent scanne, l'application calcule l'empreinte de ce qu'elle a lu et
     * la cherche dans sa base locale. Aucun secret ne quitte le serveur.
     */
    public function tokenHash(): string
    {
        return hash('sha256', $this->qrToken());
    }

    /**
     * Adresse publique du billet, signee et sans expiration.
     *
     * L'acheteur n'a pas de compte : ce lien est ce qu'il partage et ce qu'il
     * retrouve (decision D-004). La signature empeche de parcourir les billets
     * en devinant des references.
     */
    public function publicUrl(): string
    {
        return URL::signedRoute('tickets.show', ['reference' => $this->reference]);
    }
}
