<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\TicketStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Facades\URL;

/**
 * Invitation a un evenement (section 25).
 *
 * Une place mise de cote pour quelqu'un, qui devient un billet le jour ou il
 * l'accepte. Le stock est retenu par la commande a zero franc qui
 * l'accompagne (decision D-039) : l'invitation elle-meme ne compte rien.
 */
class Invitation extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'event_id',
        'order_id',
        'token',
        'guest_name',
        'guest_email',
        'expires_at',
        'created_by',
    ];

    /** Le jeton ne sort jamais en JSON : c'est lui qui ouvre l'invitation. */
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Le billet issu de l'invitation, quand elle a ete acceptee.
     *
     * Passe par la commande : c'est elle qui porte le billet, exactement comme
     * pour un achat. Une invitation ne produit qu'un billet — sa commande n'a
     * qu'une ligne, de quantite un.
     */
    public function ticket(): HasOneThrough
    {
        return $this->hasOneThrough(Ticket::class, Order::class, 'id', 'order_id', 'order_id', 'id');
    }

    /**
     * Recherche par jeton, pour la page publique.
     *
     * Sort de la portee par organisation : l'invite n'appartient a aucune
     * organisation. L'acces reste protege par le jeton lui-meme, imprevisible.
     *
     * La levee de portee doit etre REPETEE SUR CHAQUE RELATION chargee : un
     * `with('event')` simple appliquerait la portee globale, l'evenement
     * reviendrait nul, et la page planterait sur une propriete manquante. Ce
     * piege s'est deja referme cinq fois sur des chemins publics.
     */
    public static function publicByToken(string $token): ?self
    {
        return static::query()
            ->acrossOrganizations()
            ->with([
                'event' => fn ($query) => $query->acrossOrganizations()->with(['location', 'organization']),
                'order' => fn ($query) => $query->acrossOrganizations()->with('items'),
            ])
            ->where('token', $token)
            ->first();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InvitationStatus::Created->value,
            InvitationStatus::Sent->value,
        ]);
    }

    /** Le delai est-il passe ? Independant du statut, qu'une tache met a jour. */
    public function hasLapsed(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen() && ! $this->hasLapsed();
    }

    /**
     * Etat affiche, `USED` compris (section 25).
     *
     * `USED` ne vit pas en base : il se lit sur le billet. Le calculer ici
     * evite d'avoir deux verites sur un meme fait — celle de l'invitation
     * serait fausse des qu'un scan hors connexion remonterait apres coup.
     */
    public function displayStatus(): string
    {
        if ($this->status === InvitationStatus::Accepted
            && $this->ticket?->status === TicketStatus::Used) {
            return 'USED';
        }

        if ($this->status->isOpen() && $this->hasLapsed()) {
            return InvitationStatus::Expired->value;
        }

        return $this->status->value;
    }

    public function displayLabel(): string
    {
        return match ($this->displayStatus()) {
            'USED' => 'Entree effectuee',
            default => InvitationStatus::from($this->displayStatus())->label(),
        };
    }

    /**
     * Le lien a transmettre a l'invite.
     *
     * Non signe, contrairement au billet : c'est le JETON qui fait l'acces, et
     * il est deja imprevisible. Une signature en plus n'ajouterait rien, mais
     * rendrait le lien intransmissible par copier-coller — or c'est ainsi
     * qu'une invitation circule ici, dans un message.
     */
    public function publicUrl(): string
    {
        return URL::route('invitations.show', ['token' => $this->token]);
    }
}
