<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'order_id',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
