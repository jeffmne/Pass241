<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\CommissionType;
use App\Enums\DonationStatus;
use App\Enums\PaymentProvider;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\Payments\Payable;
use App\Support\Money;
use Database\Factories\DonationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\URL;

/**
 * Don a une campagne (sections 39 a 46, 63).
 *
 * AUCUN CHAMP DE MESSAGE (section 42) : ni ici, ni en base.
 *
 * `Payable` (decision D-041) : un don est encaisse par exactement le meme
 * chemin qu'une commande de billets — meme idempotence, meme verrou, meme
 * verification de montant, meme commission au taux fige. Ce qui suit dans la
 * section « Encaissement » est le peu qui differe, et il tient en quelques
 * lignes : un don n'a ni stock a verifier, ni billet a emettre.
 */
class Donation extends Model implements Payable
{
    /** @use HasFactory<DonationFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'campaign_id',
        'donor_name',
        'donor_email',
        'is_anonymous',
        'payment_provider',
        'payer_phone',
        'psp',
        'subtotal',
        'psp_fee_amount',
        'psp_fee_basis_points',
        'psp_fee_fixed',
        'amount',
        'expires_at',
        'ip_address',
    ];

    protected $hidden = [
        // Donnees privees (section 52). Les exclure par defaut evite qu'elles
        // partent dans une reponse JSON par simple oubli — le nom d'un donateur
        // anonyme au premier chef.
        'donor_name',
        'donor_email',
        'payer_phone',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'is_anonymous' => 'boolean',
            'subtotal' => 'integer',
            'psp_fee_amount' => 'integer',
            'psp_fee_basis_points' => 'integer',
            'psp_fee_fixed' => 'integer',
            'amount' => 'integer',
            'status' => DonationStatus::class,
            'payment_provider' => PaymentProvider::class,
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Commission retenue par la plateforme (2,5 %, section 21.2).
     *
     * La ligne D'ORIGINE seulement : un remboursement ajoutera un ajustement
     * partageant la meme transaction, et les additionner doublerait le chiffre.
     */
    public function commission(): MorphOne
    {
        return $this->morphOne(Commission::class, 'commissionable')
            ->whereNull('parent_commission_id');
    }

    /*
     * ---------------------------------------------------------------------
     * Requetes
     * ---------------------------------------------------------------------
     */

    /** Section 39 : les seuls dons qui comptent dans le montant collecte. */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', DonationStatus::Confirmed->value);
    }

    /**
     * Recherche par reference pour la page de suivi.
     *
     * La levee de portee est repetee sur chaque relation : un `with('campaign')`
     * simple appliquerait la portee globale, la campagne reviendrait nulle hors
     * contexte d'organisation, et la page planterait sur une propriete
     * manquante. Ce piege s'est deja referme plusieurs fois.
     */
    public static function publicByReference(string $reference): ?self
    {
        return static::query()
            ->acrossOrganizations()
            ->with(['campaign' => fn ($query) => $query->acrossOrganizations()->with('organization')])
            ->where('reference', $reference)
            ->first();
    }

    /*
     * ---------------------------------------------------------------------
     * Etat et presentation
     * ---------------------------------------------------------------------
     */

    public function isConfirmed(): bool
    {
        return $this->status === DonationStatus::Confirmed;
    }

    public function isPending(): bool
    {
        return $this->status === DonationStatus::Pending;
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

    /**
     * Nom affiche publiquement (sections 40, 41, 43).
     *
     * L'anonymat ne concerne QUE cette methode. L'identite reelle reste en
     * base — le paiement, l'audit et le rapprochement financier en dependent —
     * et l'organisateur n'a aucun chemin pour la reveler.
     *
     * Un don sans nom est traite comme anonyme : personne n'a a apparaitre sous
     * une chaine vide.
     */
    public function publicDonorName(): string
    {
        if ($this->is_anonymous || trim((string) $this->donor_name) === '') {
            return 'Donateur anonyme';
        }

        return $this->donor_name;
    }

    /** Le don lui-meme, seul montant publie (section 40). */
    public function formattedSubtotal(): string
    {
        return Money::format($this->subtotal);
    }

    /** Ce qui part reellement du telephone, frais compris. */
    public function formattedAmount(): string
    {
        return Money::format($this->amount);
    }

    public function formattedPspFee(): string
    {
        return Money::format($this->psp_fee_amount);
    }

    public function hasPspFee(): bool
    {
        return $this->psp_fee_amount > 0;
    }

    /**
     * Adresse de suivi du don, signee et sans expiration.
     *
     * Meme raison que pour une commande (decision D-004) : le donateur n'a pas
     * de compte, il garde ce lien, et la signature empeche de parcourir les
     * dons en devinant des references.
     */
    public function statusUrl(): string
    {
        return URL::signedRoute('donations.show', ['reference' => $this->reference]);
    }

    /*
     * ---------------------------------------------------------------------
     * Encaissement (contrat Payable, decision D-041)
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
     * Assiette de la commission : LE DON, jamais le montant debite.
     *
     * Les frais du prestataire sont avances par le donateur pour que la cause
     * recoive le montant annonce (decision D-023). Les inclure ferait prelever
     * 2,5 % sur l'argent du prestataire.
     */
    public function commissionBase(): int
    {
        return (int) $this->subtotal;
    }

    public function commissionType(): CommissionType
    {
        return CommissionType::Donation;
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
     * Un don annule ne peut plus etre confirme. Rien d'autre ne refuse.
     *
     * NI L'ECHEANCE, NI LA FERMETURE DE LA COLLECTE. Contrairement a une
     * commande, un don n'immobilise aucun stock : si l'argent est parti du
     * telephone du donateur, il doit etre compte, meme si le callback arrive
     * en retard ou si l'organisateur a cloture entre-temps. Refuser
     * reviendrait a encaisser sans rien enregistrer — le pire des deux mondes.
     */
    public function paymentRefusalReason(): ?string
    {
        if ($this->status === DonationStatus::Cancelled) {
            return 'Ce don a ete annule.';
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

    /** Un don n'a ni stock a verifier ni place a reserver. */
    public function guardPaymentConfirmation(): void {}

    public function applyPaymentConfirmed(?string $providerReference): void
    {
        $this->forceFill([
            'status' => DonationStatus::Confirmed,
            'confirmed_at' => now(),
            'provider_reference' => $providerReference ?? $this->provider_reference,
            'expires_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
        ])->save();
    }

    /**
     * Rien pour l'instant.
     *
     * Le recu de don (section 53) arrive avec le module de recus du jalon
     * suivant. Il partira d'ici, par un job, comme la copie du billet — jamais
     * dans la requete qui confirme le paiement.
     */
    public function afterPaymentConfirmed(): void {}

    public function applyPaymentFailed(string $reason): void
    {
        $this->forceFill([
            'status' => DonationStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ])->save();
    }

    public function paymentAuditContext(): array
    {
        return [
            'reference' => $this->reference,
            'campaign_id' => $this->campaign_id,
            // Les trois montants : un audit doit pouvoir dire ce qui revenait a
            // qui sans recalculer avec un bareme qui aura peut-etre change.
            'amount' => $this->amount,
            'subtotal' => $this->subtotal,
            'psp_fee' => $this->psp_fee_amount,
            'anonymous' => $this->is_anonymous,
            'provider_reference' => $this->provider_reference,
        ];
    }

    public function confirmedAuditAction(): AuditAction
    {
        return AuditAction::DonationConfirmed;
    }

    public function failedAuditAction(): AuditAction
    {
        return AuditAction::DonationFailed;
    }
}
