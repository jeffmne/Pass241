<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appartenance d'un utilisateur a une organisation. C'est cette table, et elle
 * seule, qui autorise l'acces au contexte d'une organisation (section 9).
 * Les roles associes sont geres par Spatie Permission, scopes sur la meme
 * organisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at');

            // Une appartenance revoquee est conservee, jamais supprimee :
            // l'historique des acces doit rester auditable (section 68).
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_members');
    }
};
