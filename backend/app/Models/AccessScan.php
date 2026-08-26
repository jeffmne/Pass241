<?php

namespace App\Models;

use App\Enums\ScanResult;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Controle a l'entree (sections 28, 31.1).
 *
 * Les FAITS sont figes — billet, appareil, agent, horodatage local — et des
 * declencheurs PostgreSQL le font respecter. Seule l'issue de l'arbitrage peut
 * encore basculer, une fois, quand un scan hors connexion plus ancien arrive
 * apres coup (section 31.1).
 *
 * Les refus sont journalises AU MEME TITRE que les acceptations. C'est meme le
 * refus qui compte le plus le lendemain, quand quelqu'un affirme s'etre vu
 * refuser l'entree avec un billet valide.
 *
 * `manual` distingue une admission faite a la reference d'un scan signe
 * (section 27.15) : la verification y est plus faible, et confondre les deux
 * effacerait la seule trace permettant de savoir comment quelqu'un est entre.
 */
class AccessScan extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id',
        'ticket_id',
        'entry_point_id',
        'agent_id',
        'device_id',
        'manual',
        'result',
        'scanned_at',
        'clock_offset_seconds',
        'conflict_with',
    ];

    protected function casts(): array
    {
        return [
            'result' => ScanResult::class,
            'manual' => 'boolean',
            'scanned_at' => 'datetime',
            'synced_at' => 'datetime',
            'clock_offset_seconds' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $scan) {
            $frozen = ['ticket_id', 'event_id', 'organization_id', 'agent_id', 'device_id', 'manual', 'scanned_at'];

            if ($scan->isDirty($frozen)) {
                throw new RuntimeException('Les faits d\'un scan sont figes.');
            }

            if ($scan->isDirty('result')
                && ! ($scan->getRawOriginal('result') === ScanResult::Accepted->value
                    && $scan->result === ScanResult::ConflictLost)) {
                throw new RuntimeException(
                    'Un scan ne change d\'issue que pour perdre un arbitrage de double scan.'
                );
            }
        });

        static::deleting(function () {
            throw new RuntimeException('Un scan ne se supprime pas.');
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function entryPoint(): BelongsTo
    {
        return $this->belongsTo(EntryPoint::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** Scan gagnant, quand celui-ci a perdu l'arbitrage. */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(self::class, 'conflict_with');
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('result', ScanResult::Accepted->value);
    }

    public function allowedEntry(): bool
    {
        return $this->result->allowsEntry();
    }

    /**
     * L'horloge de l'appareil etait-elle anormalement deregle ?
     *
     * La regle du double scan repose sur l'horodatage local (section 31.1) :
     * un appareil deregle de plusieurs minutes peut faire gagner le mauvais
     * scan. Ce signal ne bloque rien — il permet de le demontrer apres coup.
     */
    public function hasSuspiciousClock(int $toleranceSeconds = 120): bool
    {
        return $this->clock_offset_seconds !== null
            && abs($this->clock_offset_seconds) > $toleranceSeconds;
    }
}
