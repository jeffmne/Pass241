<?php

namespace App\Models;

use App\Enums\CommissionType;
use App\Enums\EventCategory;
use App\Enums\EventStatus;
use App\Enums\EventVisibility;
use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\Settlement\Settlable;
use Carbon\CarbonInterface;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Evenement (sections 11 a 14).
 *
 * Porte la portee multi-tenant : la liste des evenements d'une organisation ne
 * doit jamais laisser passer ceux d'une autre (section 9). La page publique,
 * elle, sort explicitement de cette portee — voir `publicBySlug()`.
 */
class Event extends Model implements Settlable
{
    /** @use HasFactory<EventFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'name',
        'description',
        'programme',
        'practical_info',
        'categories',
        'starts_at',
        'ends_at',
        'max_capacity',
        'visibility',
    ];

    /**
     * `slug`, `status`, `published_at`, `cancelled_at` et les chemins d'images
     * sont absents de $fillable : ils changent par des methodes dediees et
     * auditees, jamais par affectation en masse depuis un formulaire.
     */
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'max_capacity' => 'integer',
            'status' => EventStatus::class,
            'visibility' => EventVisibility::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $event) {
            /*
             * Section 13 : le slug doit rester stable apres publication.
             *
             * Une fois le lien parti sur WhatsApp, sur une affiche ou un flyer,
             * le changer casse tout ce qui a deja circule — y compris des
             * supports imprimes qu'on ne peut pas corriger.
             */
            if ($event->isDirty('slug') && $event->getOriginal('published_at') !== null) {
                throw new RuntimeException(
                    'Le lien public d\'un evenement publie ne peut plus etre modifie : '
                    .'il a deja pu etre partage.'
                );
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function location(): HasOne
    {
        return $this->hasOne(EventLocation::class);
    }

    /** Billets emis pour cet evenement (section 18). */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** Points d'entree de l'evenement (section 29). */
    public function entryPoints(): HasMany
    {
        return $this->hasMany(EntryPoint::class);
    }

    /** Agents affectes au controle de cet evenement (section 30). */
    public function agents(): HasMany
    {
        return $this->hasMany(EventAgent::class);
    }

    /** Places offertes (section 25). Chacune retient un siege des sa creation. */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * Reversement du net a l'organisateur (section 21.2).
     *
     * Un seul par evenement, garanti par une contrainte d'unicite : c'est ce
     * qui empeche de payer deux fois apres un echec transitoire.
     *
     * Polymorphe depuis la decision D-041 : la meme table accueille les
     * reversements des collectes, qui n'ont pas d'evenement.
     */
    public function settlement(): MorphOne
    {
        return $this->morphOne(Settlement::class, 'settlable');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class)->orderBy('position')->orderBy('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Types de billets proposes au public.
     *
     * Les types masques restent vendables par un autre canal (invitation, lien
     * direct) mais n'apparaissent pas sur la page de l'evenement (section 17).
     *
     * La portee par organisation est levee : la page publique est lue par des
     * visiteurs qui n'appartiennent a aucune organisation. Il n'y a aucune
     * fuite possible, la requete etant deja bornee aux billets de CET
     * evenement.
     *
     * @return Collection<int, TicketType>
     */
    public function publicTicketTypes()
    {
        $types = $this->ticketTypes()
            ->acrossOrganizations()
            ->publiclyListed()
            ->get()
            // L'evenement est deja charge ici : le rattacher evite que chaque
            // type de billet le recharge par une requete scopee, qui ne
            // renverrait rien hors contexte — et le rendrait invendable.
            ->each(fn (TicketType $ticketType) => $ticketType->setRelation('event', $this));

        app(\App\Services\Ticketing\StockLedger::class)->loadDisplayCounts($types);

        return $types;
    }

    /*
     * ---------------------------------------------------------------------
     * Requetes
     * ---------------------------------------------------------------------
     */

    /**
     * Recherche par slug pour la page publique.
     *
     * Sort volontairement de la portee par organisation : un visiteur n'est
     * membre d'aucune organisation, et c'est precisement le cas d'usage prevu
     * par la levee explicite (section 9). Les evenements sans page publique
     * restent exclus ici, pas dans la vue appelante.
     */
    public static function publicBySlug(string $slug): ?self
    {
        $event = static::query()
            ->acrossOrganizations()
            ->with(['location', 'organization'])
            ->where('slug', $slug)
            ->first();

        return $event?->status->hasPublicPage() ? $event : null;
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now());
    }

    public function scopeStatus(Builder $query, EventStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /*
     * ---------------------------------------------------------------------
     * Etat
     * ---------------------------------------------------------------------
     */

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function allowsPurchase(): bool
    {
        return $this->status->allowsPurchase();
    }

    /**
     * Conditions a remplir avant publication.
     *
     * Verifie ici plutot que dans le formulaire : la publication est aussi
     * declenchable par une action, une commande ou l'API, et une page publique
     * sans date ni lieu ne rendrait service a personne.
     *
     * @return list<string> les manques, vide si l'evenement est publiable
     */
    public function publicationBlockers(): array
    {
        $blockers = [];

        if (trim((string) $this->description) === '') {
            $blockers[] = 'Ajoutez une description : c\'est ce que verront vos participants.';
        }

        if ($this->poster_path === null) {
            $blockers[] = 'Ajoutez une affiche : elle sert d\'apercu quand le lien est partage.';
        }

        if ($this->location === null || trim((string) $this->location->name) === '') {
            $blockers[] = 'Indiquez le lieu de l\'evenement.';
        }

        if ($this->starts_at === null) {
            $blockers[] = 'Indiquez la date et l\'heure de debut.';
        } elseif ($this->starts_at->isPast()) {
            $blockers[] = 'La date de debut est deja passee.';
        }

        return $blockers;
    }

    public function canBePublished(): bool
    {
        return $this->status->canTransitionTo(EventStatus::Published)
            && $this->publicationBlockers() === [];
    }

    /*
     * ---------------------------------------------------------------------
     * Presentation
     * ---------------------------------------------------------------------
     */

    public function publicUrl(): string
    {
        return route('events.public', $this->slug);
    }

    public function posterUrl(): ?string
    {
        return $this->poster_path ? Storage::disk('public')->url($this->poster_path) : null;
    }

    /**
     * Image d'en-tete. La banniere paysage est facultative : sans elle,
     * l'affiche fait l'affaire plutot que de laisser un vide.
     */
    public function coverUrl(): ?string
    {
        return $this->cover_path
            ? Storage::disk('public')->url($this->cover_path)
            : $this->posterUrl();
    }

    /** @return list<EventCategory> */
    public function categoryCases(): array
    {
        return array_values(array_filter(
            array_map(
                fn (string $value) => EventCategory::tryFrom($value),
                $this->categories ?? []
            )
        ));
    }

    public function categoryLine(): string
    {
        return implode(' • ', array_map(fn (EventCategory $c) => $c->label(), $this->categoryCases()));
    }

    /*
     * ---------------------------------------------------------------------
     * Reversement (contrat Settlable, decision D-041)
     * ---------------------------------------------------------------------
     */

    public function settlementOrganization(): ?Organization
    {
        return $this->organization;
    }

    /**
     * Les commissions de billetterie de CET evenement.
     *
     * Une commande en echec ou annulee n'a rien encaisse : la reverser
     * creerait de l'argent qui n'existe pas (regle 80.1). Les commandes
     * partiellement remboursees restent dans le calcul, amputees de la part
     * rendue par leur ligne d'ajustement.
     */
    public function constrainCommissions(QueryBuilder $query): void
    {
        $query->join('orders', function ($join) {
            $join->on('commissions.commissionable_id', '=', 'orders.id')
                ->where('commissions.commissionable_type', '=', (new Order)->getMorphClass());
        })
            ->where('orders.event_id', $this->getKey())
            ->whereIn('orders.status', [
                OrderStatus::Paid->value,
                OrderStatus::PartiallyRefunded->value,
                OrderStatus::Refunded->value,
            ]);
    }

    public function settlementCommissionType(): CommissionType
    {
        return CommissionType::Ticketing;
    }

    /**
     * La fin de l'evenement.
     *
     * `starts_at` en secours : la date de fin est facultative en base, et un
     * evenement sans elle ne doit pas rester eternellement non reverse.
     */
    public function settlementEndsAt(): ?CarbonInterface
    {
        return $this->ends_at ?? $this->starts_at;
    }

    public function settlementLabel(): string
    {
        return Str::limit($this->name, 20, '');
    }
}
