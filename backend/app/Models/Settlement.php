<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\PayoutState;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Reversement du net a l'organisateur (section 21.2, decision D-026).
 *
 * Un par evenement, cree apres la fin de celui-ci. Le statut evolue ; les
 * montants et le beneficiaire sont figes. Des declencheurs PostgreSQL le font
 * respecter (voir la migration) ; les garde-fous ci-dessous produisent une
 * erreur plus lisible cote applicatif, avant d'atteindre la base.
 *
 * La plateforme n'est jamais depositaire definitive des fonds (section 21.2) :
 * cette ligne dit ce qui a ete envoye, a qui et quand — elle ne represente pas
 * un solde detenu, et aucun ecran ne permet de la declencher a la main.
 */
class Settlement extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'settlable_type',
        'settlable_id',
        'reference',
        'gross_amount',
        'commission_amount',
        'net_amount',
        'recipient_phone',
        'recipient_operator',
        'recipient_name',
        'status',
        'psp',
        'idempotency_key',
        'scheduled_for',
    ];

    protected $hidden = [
        // Coordonnees de paiement, privees (section 52).
        'recipient_phone',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'integer',
            'commission_amount' => 'integer',
            'net_amount' => 'integer',
            'status' => PayoutState::class,
            'recipient_operator' => PaymentProvider::class,
            'scheduled_for' => 'datetime',
            'settled_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $settlement) {
            $frozen = [
                'gross_amount', 'commission_amount', 'net_amount',
                'recipient_phone', 'recipient_operator',
                'settlable_type', 'settlable_id', 'organization_id', 'idempotency_key',
            ];

            if ($settlement->isDirty($frozen)) {
                throw new RuntimeException(
                    'Le montant et le beneficiaire d\'un reversement sont figes. '
                    .'Une correction passe par une nouvelle operation tracee, jamais par une modification.'
                );
            }
        });

        static::deleting(function () {
            throw new RuntimeException('Un reversement ne se supprime pas.');
        });
    }

    /**
     * Ce qui est reverse : un evenement, une collecte (decision D-041).
     *
     * Polymorphe depuis que les collectes encaissent : la regle du virement
     * unique et du montant fige ne depend pas de ce qui a rapporte l'argent.
     */
    public function settlable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Nom de ce qui est reverse, pour les ecrans et les notifications.
     *
     * Les deux types reversables portent un `name` ; le lire ici evite que
     * chaque vue teste le type, et qu'une nouvelle sorte de reversement oblige
     * a les reprendre toutes.
     */
    public function settlableName(): ?string
    {
        return $this->settlable?->name;
    }

    /** Date annoncee a l'organisateur, ou date reelle si le virement est parti. */
    public function expectedOn(): ?CarbonInterface
    {
        return $this->settled_at ?? $this->scheduled_for;
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /** Le prestataire a pris l'ordre en charge, sans qu'il soit forcement arrive. */
    public function isAccepted(): bool
    {
        return $this->status->isAccepted();
    }

    public function isSettled(): bool
    {
        return $this->status === PayoutState::Succeeded;
    }

    /**
     * L'ordre peut-il etre retente ?
     *
     * Ni un succes ni un ordre deja accepte : rejouer l'un ou l'autre
     * enverrait l'argent deux fois. Un etat UNKNOWN n'est pas rejouable non
     * plus — il faut d'abord interroger le prestataire (job de
     * reconciliation), sans quoi on paie une seconde fois un virement peut-etre
     * deja parti.
     */
    public function isRetryable(): bool
    {
        return $this->status === PayoutState::Failed;
    }

    public function formattedNet(): string
    {
        return Money::format($this->net_amount);
    }

    public function formattedGross(): string
    {
        return Money::format($this->gross_amount);
    }

    public function formattedCommission(): string
    {
        return Money::format($this->commission_amount);
    }

    /** Numero du beneficiaire, masque pour l'affichage (section 52). */
    public function maskedRecipient(): string
    {
        $phone = (string) $this->recipient_phone;

        return str_repeat('*', max(0, mb_strlen($phone) - 4)).mb_substr($phone, -4);
    }
}
