<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affectation d'un agent a un evenement (sections 27.2, 30).
 *
 * « Un agent ne voit que les evenements auxquels il est affecte. » Cette table
 * EST cette regle : sans ligne ici, l'application mobile ne telecharge rien et
 * le scan est refuse. Ce n'est pas une preference d'affichage, c'est le
 * perimetre de ce que l'agent peut voir et faire.
 *
 * L'agent est un utilisateur portant le role ACCESS_AGENT, pas une entite
 * separee : il se connecte par Sanctum comme tout le monde, et le systeme de
 * roles existant lui interdit deja l'administration (section 27.2). Recreer un
 * modele d'authentification a cote serait dupliquer ce qui fonctionne, avec
 * deux fois plus de surface a securiser.
 *
 * Le point d'entree est NULLABLE : un agent affecte a l'evenement sans point
 * precis controle n'importe quelle entree. C'est le cas courant d'un petit
 * evenement a une seule porte, ou l'imposer serait une friction inutile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_point_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Revocation par desactivation, jamais par suppression : la ligne
             * est ce qui explique pourquoi tel agent a scanne tel billet il y a
             * six mois. La supprimer effacerait cette explication (section 67).
             */
            $table->boolean('is_active')->default(true);
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // Un agent n'est affecte qu'une fois par evenement : le point
            // d'entree se change sur la ligne existante.
            $table->unique(['event_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_agents');
    }
};
