<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pieces justificatives de la verification d'identite (section 10.1) :
 * piece d'identite pour un particulier, RCCM ou equivalent pour une entreprise
 * ou une association.
 *
 * Les fichiers sont stockes sur un disque prive, jamais accessibles par URL
 * publique : ce sont des documents d'identite (section 66).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'type']);
        });

        DB::statement("ALTER TABLE organization_documents ADD CONSTRAINT organization_documents_type_check
            CHECK (type IN ('IDENTITY', 'RCCM'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_documents');
    }
};
