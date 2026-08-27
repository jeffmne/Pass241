<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Campagne de collecte (sections 34 a 38).
 *
 * Porte la portee multi-tenant : la liste des collectes d'une organisation ne
 * doit jamais laisser passer celles d'une autre (section 9). La page publique,
 * elle, sort explicitement de cette portee — voir `publicBySlug()`.
 *
 * AUCUNE PROPRIETE FINANCIERE N'EST STOCKEE ICI. Montant collecte, pourcentage
 * et nombre de dons sont calcules par `CampaignProgress`, jamais lus sur une
 * colonne (regle 80.1). C'est ce qui rend la triche impossible plutot que
 * simplement interdite.
 */
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'name',
        'description',
        'goal_amount',
        'beneficiary_label',
        'starts_at',
        'ends_at',
    ];

    /**
     * `slug`, `status`, `published_at`, `completed_at`, `cancelled_at`,
     * `beneficiary_id` et les chemins d'images sont absents de $fillable : ils
     * changent par des methodes dediees et auditees, jamais par affectation en
     * masse depuis un formulaire (section 67).
     */
    protected function casts(): array
    {
        return [
            'goal_amount' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'status' => CampaignStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $campaign) {
            /*
             * Le slug reste stable apres publication, comme celui d'un
             * evenement (section 13). Une fois le lien parti dans un message ou
             * sur une affiche, le changer casse tout ce qui a deja circule — et
             * une collecte vit precisement de ce partage.
             */
            if ($campaign->isDirty('slug') && $campaign->getOriginal('published_at') !== null) {
                throw new RuntimeException(
                    'Le lien public d\'une collecte publiee ne peut plus etre modifie : '
                    .'il a deja pu etre partage.'
                );
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
     * membre d'aucune, et c'est le cas d'usage prevu par la levee explicite
     * (section 9).
     *
     * L'organisation se charge sans precaution particuliere : elle ne porte pas
     * elle-meme de portee par organisation. Toute relation qui en porterait une
     * — un don, demain — devra en revanche lever la portee explicitement, faute
     * de quoi elle reviendrait vide sur un chemin public. Ce piege s'est deja
     * referme plusieurs fois.
     */
    public static function publicBySlug(string $slug): ?self
    {
        $campaign = static::query()
            ->acrossOrganizations()
            ->with('organization')
            ->where('slug', $slug)
            ->first();

        return $campaign?->status->hasPublicPage() ? $campaign : null;
    }

    public function scopeStatus(Builder $query, CampaignStatus $status): Builder
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

    /**
     * La date de fin est-elle passee ?
     *
     * Independant du statut, qu'une tache planifiee met a jour toutes les
     * heures. Une collecte ne doit pas accepter un don entre l'echeance et le
     * passage de la tache : la verification se fait donc sur la date, pas sur
     * l'etiquette.
     */
    public function hasLapsed(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    /** La collecte a-t-elle commence ? Une campagne peut etre programmee. */
    public function hasStarted(): bool
    {
        return $this->starts_at === null || $this->starts_at->isPast();
    }

    /**
     * Section 39 : le don est-il possible a cet instant precis ?
     *
     * Trois conditions, et la date en fait partie — un statut seul mentirait le
     * temps qu'une tache planifiee prenne du retard.
     */
    public function acceptsDonations(): bool
    {
        return $this->status->acceptsDonations()
            && $this->hasStarted()
            && ! $this->hasLapsed();
    }

    /**
     * Etat affiche, echeance comprise.
     *
     * Calcule plutot que lu : entre le moment ou la date de fin passe et celui
     * ou la tache planifiee s'execute, la base dit encore « en cours ». Le
     * visiteur, lui, doit lire « echue » immediatement.
     */
    public function displayStatus(): CampaignStatus
    {
        if ($this->status === CampaignStatus::Published && $this->hasLapsed()) {
            return CampaignStatus::Expired;
        }

        return $this->status;
    }

    /**
     * Conditions a remplir avant publication.
     *
     * Verifiees ici plutot que dans le formulaire : la publication est aussi
     * declenchable par une action ou une commande, et une page de collecte sans
     * objectif ni beneficiaire ne rendrait service a personne.
     *
     * @return list<string> les manques, vide si la campagne est publiable
     */
    public function publicationBlockers(): array
    {
        $blockers = [];

        if (trim((string) $this->description) === '') {
            $blockers[] = 'Ajoutez une description : c\'est elle qui donne envie de contribuer.';
        }

        if ($this->cover_path === null && $this->poster_path === null) {
            $blockers[] = 'Ajoutez une image : elle sert d\'apercu quand le lien est partage.';
        }

        if ((int) $this->goal_amount <= 0) {
            $blockers[] = 'Indiquez un objectif superieur a zero.';
        }

        if (trim((string) $this->beneficiary_label) === '') {
            $blockers[] = 'Indiquez qui recevra les fonds.';
        }

        if ($this->hasLapsed()) {
            $blockers[] = 'La date de fin est deja passee.';
        }

        return $blockers;
    }

    public function canBePublished(): bool
    {
        return $this->status->canTransitionTo(CampaignStatus::Published)
            && $this->publicationBlockers() === [];
    }

    /**
     * Une campagne supprimable.
     *
     * Uniquement un brouillon jamais publie. Des qu'un lien a pu circuler, la
     * suppression pourrait effacer des dons avec la campagne — ce que les
     * sections 44 a 46 interdisent. L'annulation existe pour cela : elle
     * arrete la collecte sans rien effacer.
     */
    public function isDeletable(): bool
    {
        return $this->status === CampaignStatus::Draft && ! $this->isPublished();
    }

    /*
     * ---------------------------------------------------------------------
     * Presentation
     * ---------------------------------------------------------------------
     */

    public function publicUrl(): string
    {
        return route('campaigns.public', $this->slug);
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
}
