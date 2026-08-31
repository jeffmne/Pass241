<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\BallotStatus;
use App\Enums\CommissionType;
use App\Enums\PaymentProvider;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\Payments\Payable;
use App\Services\Voting\VoteQuotaLedger;
use Database\Factories\BallotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\URL;

/**
 * Bulletin (sections 86.4, 86.5).
 *
 * `Ballot` et non `Vote` : en francais, « vote » designe a la fois le scrutin et
 * le bulletin.
 *
 * AUCUN ATTRIBUT N'EST MODIFIABLE EN MASSE. `$fillable` est vide, et c'est
 * volontaire : un bulletin se cree par `CastFreeBallot` ou `CreatePaidBallot`,
 * jamais par affectation depuis une requete. La base tient la meme regle par un
 * declencheur — candidat, quantite et montant sont definitifs apres creation.
 *
 * LE CONTRAT `Payable` EST IMPLEMENTE PLUS BAS (jalon 9.4). Le chemin de
 * l'argent — idempotence, verrou, verification du montant, commission, journal
 * — n'est donc pas ecrit ici : il vit dans `ConfirmPayment`, une seule fois pour
 * la billetterie, les collectes et les votes (decision D-041).
 */
class Ballot extends Model implements Payable
{
    /** @use HasFactory<BallotFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * VIDE, et c'est le point.
     *
     * Les valeurs qui decident d'une voix et d'un montant ne viennent jamais
     * d'un formulaire : elles sont derivees au serveur depuis la categorie
     * (section 86.5, point 4). Un `$fillable` complaisant laisserait un jour
     * passer un `amount` envoye par le navigateur.
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'subtotal' => 'integer',
            'psp_fee_amount' => 'integer',
            'amount' => 'integer',
            'status' => BallotStatus::class,
            'provider' => PaymentProvider::class,
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * La commission prelevee sur ce bulletin (section 86.3, decision D-047).
     *
     * Polymorphe, comme pour une commande et un don : c'est ce qui a permis au
     * module Votes de reutiliser le chemin de l'argent sans le reecrire.
     */
    public function commission(): MorphOne
    {
        return $this->morphOne(Commission::class, 'commissionable');
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(PollCategory::class, 'poll_category_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /*
     * ---------------------------------------------------------------------
     * Requetes
     * ---------------------------------------------------------------------
     */

    /**
     * Les bulletins qui COMPTENT dans les voix.
     *
     * Les rembourses en font partie : le remboursement rend l'argent, il ne
     * retire pas la voix. Voir `BallotStatus::countsAsVote()` — c'est la que le
     * raisonnement est ecrit, et il n'a pas a etre redit a chaque requete.
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BallotStatus::Confirmed->value,
            BallotStatus::Refunded->value,
        ]);
    }

    /** Les bulletins qui ont rapporte de l'argent non rendu. */
    public function scopeEarning(Builder $query): Builder
    {
        return $query->where('status', BallotStatus::Confirmed->value);
    }

    /*
     * ---------------------------------------------------------------------
     * Etat
     * ---------------------------------------------------------------------
     */

    public function isConfirmed(): bool
    {
        return $this->status === BallotStatus::Confirmed;
    }

    public function isPending(): bool
    {
        return $this->status === BallotStatus::Pending;
    }

    /** Un vote gratuit n'a rien coute : il ne passe par aucun paiement. */
    public function isFree(): bool
    {
        return $this->amount === 0;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Secondes restantes pour valider le paiement, jamais negatives. */
    public function secondsBeforeExpiry(): int
    {
        if ($this->expires_at === null) {
            return 0;
        }

        return max(0, now()->diffInSeconds($this->expires_at, false));
    }

    /** Suivi public du paiement, par lien signe : le votant n'a pas de compte. */
    public function statusUrl(): string
    {
        return URL::signedRoute('ballots.show', ['reference' => $this->reference]);
    }

    /**
     * Bulletin lu depuis un chemin public, hors portee d'organisation.
     *
     * Le votant n'appartient a aucune organisation. L'acces n'est pas protege
     * par la portee mais par la SIGNATURE de l'URL, verifiee par le middleware
     * de la route : sans elle, on parcourrait les bulletins en devinant des
     * references, et le numero du payeur avec.
     */
    public static function publicByReference(string $reference): ?self
    {
        return static::query()
            ->acrossOrganizations()
            ->where('reference', $reference)
            ->first();
    }

    /**
     * Candidat choisi, lisible hors portee.
     *
     * `Candidate` n'en porte pas, mais Livewire rehydrate le modele sans ses
     * relations a chaque rafraichissement : la methode evite que chaque
     * appelant y repense.
     */
    public function publicCandidate(): ?Candidate
    {
        if ($this->relationLoaded('candidate') && $this->candidate !== null) {
            return $this->candidate;
        }

        $candidate = Candidate::query()->find($this->candidate_id);

        if ($candidate) {
            $this->setRelation('candidate', $candidate);
        }

        return $candidate;
    }

    /*
     * ---------------------------------------------------------------------
     * Contrat Payable (decision D-041)
     * ---------------------------------------------------------------------
     *
     * Le chemin de l'argent — idempotence, verrou, verification du montant,
     * commission, journal — vit dans `ConfirmPayment` et n'est ecrit qu'une
     * fois pour les trois modules. Ce qui suit est ce qui est PROPRE au vote.
     */

    public function paymentReference(): string
    {
        return $this->reference;
    }

    /** Ce qui part du telephone : les voix ET les frais de l'operateur. */
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
        return $this->provider;
    }

    /**
     * Assiette de la commission : LES VOIX, jamais le montant debite.
     *
     * Les frais du prestataire sont avances par le votant (decision D-023).
     * Les inclure ferait prelever 5 % sur l'argent de l'operateur.
     */
    public function commissionBase(): int
    {
        return (int) $this->subtotal;
    }

    public function commissionType(): CommissionType
    {
        return CommissionType::Voting;
    }

    public function paymentOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function isPaymentConfirmed(): bool
    {
        return $this->isConfirmed();
    }

    public function isPaymentPending(): bool
    {
        return $this->isPending();
    }

    /**
     * Ce qui refuse encore un encaissement.
     *
     * UN BULLETIN ECHOUE NE SE RATTRAPE PAS : sa voix a ete rendue au quota, et
     * quelqu'un d'autre a pu la prendre. Confirmer par-dessus ferait deux voix
     * la ou la categorie n'en autorisait qu'une.
     *
     * NI L'ECHEANCE DU BULLETIN NI LA CLOTURE DU SCRUTIN NE REFUSENT, et c'est
     * le meme raisonnement que pour les dons : si l'argent est parti du
     * telephone, il doit etre compte, meme si le callback arrive en retard. La
     * fenetre de vote a ete verifiee A LA CREATION du bulletin, c'est-a-dire au
     * moment ou le votant a agi. Refuser ici reviendrait a encaisser sans rien
     * enregistrer — le pire des deux mondes, et un litige garanti.
     */
    public function paymentRefusalReason(): ?string
    {
        if ($this->status === BallotStatus::Failed) {
            return 'Ce vote a echoue : sa voix a ete rendue.';
        }

        return null;
    }

    public function lockedForPayment(): ?static
    {
        return static::query()
            ->acrossOrganizations()
            ->lockForUpdate()
            ->find($this->getKey());
    }

    /**
     * Le quota a deja ete reserve a la creation du bulletin.
     *
     * Rien a verifier ici, donc — contrairement a une commande, dont la
     * reservation de stock peut expirer entre la validation sur le telephone et
     * l'arrivee du callback. La voix, elle, reste tenue tant que le bulletin
     * n'est pas passe en echec, et un bulletin en echec est refuse plus haut.
     */
    public function guardPaymentConfirmation(): void {}

    public function applyPaymentConfirmed(?string $providerReference): void
    {
        $this->forceFill([
            'status' => BallotStatus::Confirmed,
            'confirmed_at' => now(),
            'provider_reference' => $providerReference ?? $this->provider_reference,
            // L'echeance tombe : la contrainte en base refuse une date
            // d'expiration sur autre chose qu'un bulletin en attente, et la
            // tache de balayage ne doit plus jamais voir cette ligne.
            'expires_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
        ])->save();
    }

    /**
     * Rien a envoyer.
     *
     * Un votant ne recoit ni billet ni recu : sa voix est comptee, et la page
     * de suivi le lui dit. Lui ecrire supposerait de lui demander une adresse,
     * donc d'ajouter un champ sur le seul ecran qu'on cherche a garder court.
     */
    public function afterPaymentConfirmed(): void {}

    /**
     * L'echec REND LA VOIX au quota, et c'est le vrai travail de cette methode.
     *
     * Une commande n'a rien de tel a faire : ses reservations de stock sont
     * CALCULEES depuis les commandes en attente, donc une commande echouee
     * cesse d'elle-meme de compter. Le quota d'un votant, lui, est un compteur
     * REEL en base — il faut le decrementer explicitement.
     *
     * Sans cela, quelqu'un qui lance un paiement puis abandonne resterait bloque
     * pour toujours : son plafond serait consomme par un vote qu'il n'a jamais
     * paye, et il ne pourrait plus voter du tout.
     *
     * La liberation vit ICI plutot que chez les deux appelants — refus de
     * l'operateur d'un cote, echeance de l'autre — pour qu'il n'y ait pas deux
     * versions de cette regle, dont une finirait par etre oubliee. C'est le
     * meme parti pris que `Order::applyPaymentConfirmed`, qui emet les billets.
     */
    public function applyPaymentFailed(string $reason): void
    {
        $this->forceFill([
            'status' => BallotStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $reason,
            'expires_at' => null,
        ])->save();

        $category = $this->resolvedCategoryForQuota();

        if ($category) {
            app(VoteQuotaLedger::class)->release(
                $category,
                (string) $this->voter_hash,
                (int) $this->quantity,
            );
        }
    }

    /**
     * Categorie porteuse, lisible depuis un worker sans contexte.
     *
     * `PollCategory` ne porte pas de portee par organisation, mais la relation
     * peut ne pas etre chargee : un webhook arrive avec une reference et rien
     * d'autre.
     */
    private function resolvedCategoryForQuota(): ?PollCategory
    {
        if ($this->relationLoaded('category') && $this->category) {
            return $this->category;
        }

        return PollCategory::query()->find($this->poll_category_id);
    }

    public function paymentAuditContext(): array
    {
        return [
            'reference' => $this->reference,
            'poll_id' => $this->poll_id,
            'poll_category_id' => $this->poll_category_id,
            // LE CANDIDAT EN PREMIER LIEU : en cas de contestation, la seule
            // question posee sera « sur qui cet argent a-t-il pese ».
            'candidate_id' => $this->candidate_id,
            'quantity' => $this->quantity,
            // Les trois montants : un audit doit pouvoir dire ce qui revenait a
            // qui sans recalculer avec un bareme qui aura peut-etre change.
            'amount' => $this->amount,
            'subtotal' => $this->subtotal,
            'psp_fee' => $this->psp_fee_amount,
            'provider_reference' => $this->provider_reference,
        ];
    }

    public function confirmedAuditAction(): AuditAction
    {
        return AuditAction::BallotConfirmed;
    }

    public function failedAuditAction(): AuditAction
    {
        return AuditAction::BallotFailed;
    }
}
