<?php

namespace App\Models;

use App\Enums\ResultsVisibility;
use Carbon\CarbonInterface;
use Database\Factories\PollCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Categorie d'un scrutin (section 86.4).
 *
 * LES CATEGORIES SONT INDEPENDANTES ENTRE ELLES. Fenetre de vote, gratuite,
 * prix, plafond par votant et visibilite des resultats se reglent categorie par
 * categorie — un meme evenement peut porter un prix du public gratuit en direct
 * et une election principale payante scellee jusqu'a la fin.
 *
 * PAS DE PORTEE MULTI-TENANT ICI. Une categorie n'a pas de `organization_id` :
 * elle descend du scrutin, qui en porte un. Ajouter la colonne creerait une
 * seconde verite sur l'appartenance, qu'il faudrait ensuite tenir synchronisee.
 * Les requetes passent donc par `Poll`, deja filtre — sauf sur les chemins
 * publics, ou la portee est levee volontairement.
 *
 * L'HERITAGE DU SCRUTIN se lit ici, jamais recopie en base : `starts_at`,
 * `ends_at` et `results_visibility` restent nuls tant que la categorie n'a pas
 * de reglage propre. Une copie se desynchroniserait le jour ou l'organisateur
 * decale la fenetre du scrutin.
 */
class PollCategory extends Model
{
    /** @use HasFactory<PollCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'starts_at',
        'ends_at',
        'is_paid',
        'unit_price',
        'max_votes_per_voter',
        'results_visibility',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_paid' => 'boolean',
            'unit_price' => 'integer',
            'max_votes_per_voter' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
            'results_visibility' => ResultsVisibility::class,
        ];
    }

    /*
     * ---------------------------------------------------------------------
     * Relations
     * ---------------------------------------------------------------------
     */

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    /**
     * Scrutin porteur, LISIBLE HORS CONTEXTE D'ORGANISATION.
     *
     * Sans ce repli, toute cette classe s'effondrait sur la page publique.
     *
     * Une categorie ne porte pas de portee par organisation — elle descend du
     * scrutin. Le SCRUTIN, lui, en porte une. Un visiteur n'etant membre
     * d'aucune organisation, `$this->poll` charge en differe revenait `null`, et
     * les sept methodes qui s'en servent levaient « Attempt to read property on
     * null ». La page publique repondait 500, et `acceptsBallots()` — donc le
     * vote lui-meme — echouait pour tout le monde sauf en test, ou le contexte
     * restait pose par la creation des donnees.
     *
     * C'est exactement le piege annonce dans `Poll::publicBySlug`, et c'est
     * exactement le remede employe par `TicketType::resolvedEvent()` pour la
     * meme raison sur la page d'un evenement. Le faire porter par le modele
     * plutot que par chaque appelant est ce qui evite que le piege se referme
     * une fois de plus : personne n'a plus a y penser.
     *
     * Publique, contrairement a son equivalent sur `TicketType` : l'action qui
     * enregistre un bulletin en a besoin elle aussi, pour retrouver
     * l'organisation a crediter depuis un chemin sans contexte.
     */
    public function resolvedPoll(): ?Poll
    {
        if ($this->relationLoaded('poll') && $this->poll) {
            return $this->poll;
        }

        $poll = Poll::query()->acrossOrganizations()->find($this->poll_id);

        if ($poll) {
            $this->setRelation('poll', $poll);
        }

        return $poll;
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class)->orderBy('position')->orderBy('id');
    }

    public function activeCandidates(): HasMany
    {
        return $this->candidates()->where('is_active', true);
    }

    /*
     * ---------------------------------------------------------------------
     * Fenetre de vote — heritee ou propre
     * ---------------------------------------------------------------------
     */

    public function effectiveStartsAt(): CarbonInterface
    {
        return $this->starts_at ?? $this->resolvedPoll()->starts_at;
    }

    public function effectiveEndsAt(): CarbonInterface
    {
        return $this->ends_at ?? $this->resolvedPoll()->ends_at;
    }

    /** La categorie regle-t-elle sa propre fenetre ? */
    public function hasOwnWindow(): bool
    {
        return $this->starts_at !== null;
    }

    /**
     * La categorie accepte-t-elle un bulletin a cet instant precis ?
     *
     * LE SCRUTIN DECIDE EN PREMIER. Une categorie ne peut jamais ouvrir ce que
     * le scrutin a ferme : sa fenetre propre ne fait que RESTREINDRE. Sans cette
     * subordination, une categorie mal reglee laisserait voter apres la cloture
     * du concours, et le classement fige n'aurait plus de sens.
     */
    public function acceptsBallots(): bool
    {
        // Echec en ferme : sans scrutin lisible, le vote est ferme. Une anomalie
        // ne doit jamais ouvrir un droit de vote.
        if (! $this->is_active || ! $this->resolvedPoll()?->acceptsBallots()) {
            return false;
        }

        $now = now();

        return $this->effectiveStartsAt()->lessThanOrEqualTo($now)
            && $this->effectiveEndsAt()->greaterThan($now);
    }

    /**
     * Pourquoi le vote est-il ferme, ou null s'il est ouvert.
     *
     * Rendre une phrase et non un booleen : elle remonte telle quelle a l'ecran.
     * « Les votes de cette categorie ouvrent le 3 mars » renseigne la ou un
     * `false` laisse le visiteur chercher ce qu'il a mal fait.
     */
    public function closureReason(): ?string
    {
        if ($this->acceptsBallots()) {
            return null;
        }

        if (! $this->is_active) {
            return 'Cette categorie n\'est pas ouverte au vote.';
        }

        $poll = $this->resolvedPoll();

        if (! $poll?->acceptsBallots()) {
            return $poll?->hasLapsed()
                ? 'Les votes sont termines.'
                : 'Les votes ne sont pas encore ouverts.';
        }

        if ($this->effectiveStartsAt()->isFuture()) {
            return sprintf(
                'Les votes de cette categorie ouvrent le %s.',
                $this->effectiveStartsAt()->timezone(config('app.display_timezone'))->format('d/m/Y a H\hi')
            );
        }

        return 'Les votes de cette categorie sont termines.';
    }

    /*
     * ---------------------------------------------------------------------
     * Resultats
     * ---------------------------------------------------------------------
     */

    public function effectiveResultsVisibility(): ResultsVisibility
    {
        return $this->results_visibility ?? $this->resolvedPoll()->results_visibility;
    }

    /** Le public peut-il voir le classement de CETTE categorie, maintenant ? */
    public function showsPublicResults(): bool
    {
        $poll = $this->resolvedPoll();

        // Echec en ferme, comme `acceptsBallots` : sans scrutin lisible, le
        // classement reste scelle. Un doute ne doit pas ouvrir un resultat.
        if (! $poll) {
            return false;
        }

        return $this->effectiveResultsVisibility()->isPublicFor($poll->displayStatus());
    }

    /*
     * ---------------------------------------------------------------------
     * Modele economique
     * ---------------------------------------------------------------------
     */

    /**
     * Prix a payer pour `n` votes.
     *
     * CALCULE ICI, JAMAIS RECU DU NAVIGATEUR (section 86.5, point 4). Une
     * quantite venue du client est une intention ; le montant, lui, se derive du
     * prix unitaire en base. Confondre les deux laisserait un bulletin fixer
     * lui-meme ce qu'il coute.
     */
    public function priceFor(int $quantity): int
    {
        return $this->is_paid ? $this->unit_price * max(0, $quantity) : 0;
    }

    /** Le plafond par votant, ou null si les votes sont illimites (section 86.3). */
    public function voteLimit(): ?int
    {
        return $this->max_votes_per_voter;
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
