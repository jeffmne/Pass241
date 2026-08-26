<?php

namespace App\Models;

use App\Enums\KycStatus;
use App\Enums\OrganizationType;
use App\Enums\PaymentProvider;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

/**
 * Unite d'isolation du SaaS (section 9) et titulaire des reversements
 * (section 21.2).
 *
 * Ce modele ne porte pas le trait BelongsToOrganization : il est
 * l'organisation. Son acces est controle par l'appartenance verifiee
 * (OrganizationMember), pas par une portee globale.
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'contact_email',
        'contact_phone',
    ];

    /**
     * `kyc_status`, `suspended_at`, `owner_id` et TOUTE la destination de
     * reversement sont volontairement absents de $fillable : ces valeurs ne
     * changent que par des methodes dediees et auditees, jamais par
     * affectation en masse depuis un formulaire (sections 10.1, 67).
     *
     * Pour la destination de reversement, c'est la protection principale :
     * rediriger les fonds vers un autre numero est le vecteur de fraude
     * central une fois qu'une plateforme sait payer. Aucun champ de formulaire
     * ne doit pouvoir l'atteindre par inadvertance.
     */
    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'payout_operator' => PaymentProvider::class,
            'payout_updated_at' => 'datetime',
            'kyc_status' => KycStatus::class,
            'kyc_submitted_at' => 'datetime',
            'kyc_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->withPivot(['joined_at', 'revoked_at'])
            ->wherePivotNull('revoked_at');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Adresse a laquelle notifier l'organisation.
     *
     * L'email de contact et non celui du proprietaire : c'est l'adresse que
     * l'organisation a designee, et elle survit a un changement de personne.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->contact_email;
    }

    /** Une destination de reversement est renseignee et exploitable. */
    public function hasPayoutDestination(): bool
    {
        return $this->payout_phone !== null && $this->payout_operator !== null;
    }

    /**
     * L'organisation peut-elle recevoir un reversement ?
     *
     * Trois conditions, et plus aucune piece justificative (decision D-025) :
     *
     * 1. une destination renseignee — on ne peut pas virer sans numero ;
     * 2. l'organisation n'est pas suspendue ;
     * 3. le Super Admin n'a pas explicitement rejete le compte.
     *
     * Le KYC n'exige plus de documents : le compte mobile money de destination
     * est deja verifie par l'operateur, qui reclame une piece d'identite a
     * l'ouverture. `kyc_status` subsiste comme levier du Super Admin — c'est
     * pourquoi la condition porte sur REJECTED et non sur VERIFIED. Exiger
     * VERIFIED bloquerait tout le monde, plus personne ne soumettant de
     * dossier.
     */
    public function canReceivePayouts(): bool
    {
        return $this->hasPayoutDestination()
            && ! $this->isSuspended()
            && $this->kyc_status !== KycStatus::Rejected;
    }

    /**
     * Ce qui manque avant de pouvoir etre paye, en une phrase.
     *
     * Renvoie null quand tout est en ordre. L'organisateur doit savoir quoi
     * faire, pas seulement que quelque chose bloque.
     */
    public function payoutBlocker(): ?string
    {
        if ($this->isSuspended()) {
            return 'Votre organisation est suspendue.';
        }

        if ($this->kyc_status === KycStatus::Rejected) {
            return 'Votre compte a ete refuse. Contactez le support.';
        }

        if (! $this->hasPayoutDestination()) {
            return 'Renseignez le numero mobile money sur lequel recevoir vos recettes.';
        }

        return null;
    }

    /**
     * Quelque chose empeche-t-il d'etre paye ?
     *
     * Ne porte plus sur les pieces justificatives, qui ne sont plus demandees
     * (decision D-025), mais sur ce qui bloque reellement un versement.
     */
    public function needsKycAttention(): bool
    {
        return $this->payoutBlocker() !== null;
    }
}
