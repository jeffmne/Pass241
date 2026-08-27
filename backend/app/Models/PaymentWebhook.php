<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Message recu d'un operateur de paiement (sections 22, 23).
 *
 * Ne porte pas le trait BelongsToOrganization : un webhook arrive avant qu'on
 * sache a quelle organisation il se rattache, et parfois sans qu'on puisse le
 * savoir du tout — un message malforme doit quand meme etre conserve.
 *
 * La contrainte d'unicite sur (provider, event_id) porte l'idempotence.
 */
class PaymentWebhook extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'provider',
        'event_id',
        'payable_type',
        'payable_id',
        'signature_valid',
        'payload',
        'processed_at',
        'outcome',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Ce que ce message paie : une commande, un don (decision D-041).
     *
     * Nullable, et ce n'est pas une facilite : un message malforme, ou dont la
     * reference ne correspond a rien, doit etre conserve tel quel. C'est ce qui
     * permet de comprendre apres coup pourquoi un paiement n'a pas ete
     * confirme.
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Un message deja traite ne doit jamais produire un second effet. */
    public function wasProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    public function markProcessed(string $outcome): void
    {
        $this->forceFill(['processed_at' => now(), 'outcome' => $outcome])->save();
    }
}
