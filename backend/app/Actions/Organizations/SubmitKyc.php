<?php

namespace App\Actions\Organizations;

use App\Enums\AuditAction;
use App\Enums\DocumentType;
use App\Enums\KycStatus;
use App\Models\Organization;
use App\Models\OrganizationDocument;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Depot des pieces justificatives et passage en verification (section 10.1).
 *
 * Le statut ne passe a SUBMITTED que si toutes les pieces exigees par la nature
 * de l'organisation sont presentes. Autrement, un organisateur pourrait se
 * declarer « en cours de verification » sans avoir rien fourni, et attendre un
 * reversement qui ne viendrait jamais sans comprendre pourquoi.
 */
class SubmitKyc
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, UploadedFile>  $documents  indexe par valeur de DocumentType
     */
    public function handle(Organization $organization, User $actor, array $documents): Organization
    {
        if ($organization->kyc_status === KycStatus::Verified) {
            throw new RuntimeException('Cette organisation est deja verifiee.');
        }

        $required = DocumentType::requiredFor($organization->type);

        return DB::transaction(function () use ($organization, $actor, $documents, $required) {
            foreach ($required as $type) {
                $file = $documents[$type->value] ?? null;

                if (! $file instanceof UploadedFile) {
                    // Deja fournie lors d'une soumission precedente : inutile de
                    // redemander la piece a chaque nouvel essai.
                    if ($this->hasDocument($organization, $type)) {
                        continue;
                    }

                    throw new RuntimeException("Piece manquante : {$type->label()}.");
                }

                $this->store($organization, $actor, $type, $file);
            }

            $organization->forceFill([
                'kyc_status' => KycStatus::Submitted,
                'kyc_submitted_at' => now(),
                'kyc_rejection_reason' => null,
            ])->save();

            $this->audit->log(
                AuditAction::KycSubmitted,
                $organization,
                ['documents' => array_map(fn (DocumentType $t) => $t->value, $required)],
                actor: $actor,
                organizationId: $organization->getKey(),
            );

            return $organization;
        });
    }

    private function hasDocument(Organization $organization, DocumentType $type): bool
    {
        return OrganizationDocument::query()
            ->where('organization_id', $organization->getKey())
            ->where('type', $type->value)
            ->exists();
    }

    private function store(Organization $organization, User $actor, DocumentType $type, UploadedFile $file): void
    {
        // Disque prive : une piece d'identite ne doit jamais etre servie depuis
        // une URL publique devinable (section 66).
        $path = $file->store("kyc/{$organization->getKey()}", 'local');

        OrganizationDocument::create([
            'organization_id' => $organization->getKey(),
            'type' => $type->value,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $actor->getKey(),
        ]);
    }
}
