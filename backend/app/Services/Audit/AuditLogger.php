<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Point d'entree unique du journal d'audit (section 68).
 *
 * Passer par ce service plutot que par `AuditLog::create()` garantit que
 * l'organisation, l'acteur, l'adresse IP et l'agent utilisateur sont toujours
 * renseignes de la meme facon. Un journal dont les champs sont remplis au cas
 * par cas devient inexploitable des la premiere enquete serieuse.
 */
class AuditLogger
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        AuditAction $action,
        ?Model $subject = null,
        array $metadata = [],
        ?User $actor = null,
        ?int $organizationId = null,
    ): AuditLog {
        $actor ??= $this->request->user();

        return AuditLog::create([
            'organization_id' => $organizationId ?? $this->context->currentId(),
            'user_id' => $actor?->getKey(),
            'action' => $action->value,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 1000) ?: null,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
