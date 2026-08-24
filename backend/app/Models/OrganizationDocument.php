<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Piece justificative rattachee a une organisation (section 10.1).
 *
 * Porte la portee multi-tenant : un document d'identite ne doit jamais
 * apparaitre dans une requete faite depuis une autre organisation, meme par
 * accident (sections 9, 66).
 */
class OrganizationDocument extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'type',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'size_bytes' => 'integer',
        ];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
