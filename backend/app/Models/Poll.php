<?php

namespace App\Models;

use App\Enums\PollStatus;
use App\Enums\ResultsVisibility;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PollFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Scrutin (sections 86.4, 86.5).
 *
 * `Poll` et non `Vote` : en francais, « vote » designe a la fois le scrutin et
 * le bulletin. En interface, tout reste « Vote » — le vocabulaire de
 * l'organisateur ne change pas.
 *
 * Porte la portee multi-tenant : la liste des votes d'une organisation ne doit
 * jamais laisser passer ceux d'une autre (section 9). La page publique, elle,
 * sort explicitement de cette portee — voir `publicBySlug()`.
 *
 * AUCUNE PROPRIETE DE RESULTAT N'EST STOCKEE ICI. Totaux, classements et revenus
 * sont agreges en SQL par `VotingStatistics`, jamais lus sur une colonne
 * (section 86.5). C'est ce qui rend la triche impossible plutot que simplement
 * interdite.
 */
class Poll extends Model
{
    /** @use HasFactory<PollFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'name',
        'description',
        'starts_at',
        'ends_at',
        'results_visibility',
    ];

    /**
     * `slug`, `status`, `published_at`, `closed_at`, `cancelled_at` et les
     * chemins d'images sont absents de $fillable : ils changent par des methodes
     * dediees et auditees, jamais par affectation en masse depuis un formulaire
     * (section 67).
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'status' => PollStatus::class,
            'results_visibility' => ResultsVisibility::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $poll) {
            /*
             * Le slug reste stable des la publication, comme celui d'un
             * evenement (section 13) ou d'une collecte. Une fois le lien parti
             * sur une affiche ou dans un message, le changer casse tout ce qui a
             * deja circule — et un concours vit precisement de ce partage.
             */
            if ($poll->isDirty('slug') && $poll->getOriginal('published_at') !== null) {
                throw new RuntimeException(
                    'Le lien public d\'un vote publie ne peut plus etre modifie : '
                    .'il a deja pu etre partage.'
                );
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /*
     * ---------------------------------------------------------------------
     * Relations
     * ---------------------------------------------------------------------
     */

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(PollCategory::class)->orderBy('position')->orderBy('id');
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
     * L'organisation se charge sans precaution : elle ne porte pas elle-meme de
     * portee. Les categories et les candidats non plus — ils descendent du
     * scrutin. Les BULLETINS, eux, en portent une : toute requete qui les
     * compterait depuis un chemin public devra lever la portee explicitement,
     * faute de quoi elle reviendra vide. Ce piege s'est deja referme plusieurs
     * fois.
     */
    public static function publicBySlug(string $slug): ?self
    {
        $poll = static::query()
            ->acrossOrganizations()
            ->with('organization')
            ->where('slug', $slug)
            ->first();

        return $poll?->status->hasPublicPage() ? $poll : null;
    }

    public function scopeStatus(Builder $query, PollStatus $status): Builder
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
     * Independant du statut, qu'une tache planifiee met a jour. Un scrutin ne
     * doit pas accepter un bulletin entre l'echeance et le passage de la tache :
     * la verification porte donc sur la DATE, pas sur l'etiquette (section 86.5,
     * point 8 de la strategie anti-fraude).
     */
    public function hasLapsed(): bool
    {
        return $this->ends_at->isPast();
    }

    public function hasStarted(): bool
    {
        return $this->starts_at->isPast();
    }

    /**
     * Le scrutin accepte-t-il un bulletin a cet instant precis ?
     *
     * Trois conditions, et les dates en font partie. Une categorie peut ensuite
     * refuser pour ses raisons propres — sa fenetre a elle, ou sa desactivation
     * — mais aucune categorie ne peut ouvrir ce que le scrutin a ferme.
     */
    public function acceptsBallots(): bool
    {
        return $this->status->acceptsBallots()
            && $this->hasStarted()
            && ! $this->hasLapsed();
    }

    /**
     * Etat affiche, echeance comprise.
     *
     * Calcule plutot que lu : entre le moment ou la date de fin passe et celui
     * ou la tache planifiee s'execute, la base dit encore « en cours ». Le
     * visiteur, lui, doit lire « termine » immediatement.
     */
    public function displayStatus(): PollStatus
    {
        if ($this->status === PollStatus::Active && $this->hasLapsed()) {
            return PollStatus::Closed;
        }

        // Un scrutin programme dont l'heure d'ouverture est passee se lit
        // « en cours », meme si la tache n'a pas encore tourne.
        if ($this->status === PollStatus::Scheduled && $this->hasStarted() && ! $this->hasLapsed()) {
            return PollStatus::Active;
        }

        return $this->status;
    }

    /**
     * Le public peut-il voir le classement a cet instant ?
     *
     * Repond pour le SCRUTIN. Une categorie qui regle sa propre visibilite
     * repond pour elle-meme — voir `PollCategory::showsPublicResults()`.
     */
    public function showsPublicResults(): bool
    {
        return $this->results_visibility->isPublicFor($this->displayStatus());
    }

    /**
     * Conditions a remplir avant publication.
     *
     * Verifiees ici plutot que dans le formulaire : la publication est aussi
     * declenchable par une action ou une commande, et une page de vote sans
     * candidat ne rendrait service a personne.
     *
     * @return list<string> les manques, vide si le scrutin est publiable
     */
    public function publicationBlockers(): array
    {
        $blockers = [];

        if (trim((string) $this->description) === '') {
            $blockers[] = 'Ajoutez une description : elle explique ce qui se joue.';
        }

        if ($this->banner_path === null && $this->poster_path === null) {
            $blockers[] = 'Ajoutez une image : elle sert d\'apercu quand le lien est partage.';
        }

        $categories = $this->relationLoaded('categories')
            ? $this->categories
            : $this->categories()->with('candidates')->get();

        $active = $categories->where('is_active', true);

        if ($active->isEmpty()) {
            $blockers[] = 'Creez au moins une categorie active.';
        }

        /*
         * Une categorie a au moins deux candidats.
         *
         * Un candidat unique n'est pas un vote, c'est une formalite : le public
         * cliquerait sur la seule option possible. Le seuil vit ici parce qu'il
         * est verifiable au moment de publier, pas au moment de saisir — une
         * categorie passe forcement par « un seul candidat » pendant qu'on la
         * remplit.
         */
        foreach ($active as $category) {
            if ($category->candidates->where('is_active', true)->count() < 2) {
                $blockers[] = sprintf(
                    'La categorie « %s » a besoin d\'au moins deux candidats actifs.',
                    $category->name
                );
            }
        }

        if ($this->hasLapsed()) {
            $blockers[] = 'La date de fin est deja passee.';
        }

        return $blockers;
    }

    public function canBePublished(): bool
    {
        return ($this->status->canTransitionTo(PollStatus::Active)
                || $this->status->canTransitionTo(PollStatus::Scheduled))
            && $this->publicationBlockers() === [];
    }

    /**
     * Un scrutin supprimable.
     *
     * Uniquement un brouillon jamais publie. Des qu'un lien a pu circuler, la
     * suppression pourrait effacer des bulletins avec le scrutin — dont des
     * bulletins payes, ce que la section 86.4 interdit. L'annulation existe pour
     * cela : elle arrete le vote sans rien effacer.
     */
    public function isDeletable(): bool
    {
        return $this->status === PollStatus::Draft && ! $this->isPublished();
    }

    /*
     * ---------------------------------------------------------------------
     * Presentation
     * ---------------------------------------------------------------------
     */

    public function publicUrl(): string
    {
        return route('polls.public', $this->slug);
    }

    public function posterUrl(): ?string
    {
        return $this->poster_path ? Storage::disk('public')->url($this->poster_path) : null;
    }

    /**
     * Image d'en-tete. La banniere paysage est facultative : sans elle,
     * l'affiche fait l'affaire plutot que de laisser un vide.
     */
    public function bannerUrl(): ?string
    {
        return $this->banner_path
            ? Storage::disk('public')->url($this->banner_path)
            : $this->posterUrl();
    }
}
