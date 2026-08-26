<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Points d'entree d'un evenement (section 29).
 *
 * Entree principale, entree VIP, entree invites, entree secondaire. Un agent
 * est affecte a un point par session de scan, mais peut etre reaffecte sans
 * qu'on recree son compte (section 30).
 *
 * Le point d'entree n'a AUCUN role de filtrage : il ne decide pas qu'un billet
 * VIP passe par l'entree VIP. La section 29 ne le prevoit pas, et l'inventer
 * ferait refuser a l'entree des porteurs parfaitement valides — le pire defaut
 * possible pour un controle d'acces. Il sert a savoir PAR OU quelqu'un est
 * entre, et a repartir les agents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('description')->nullable();

            // Un point ferme ne recoit plus de scan : c'est ainsi qu'on ferme
            // une entree en cours de soiree sans toucher aux agents.
            $table->boolean('is_active')->default(true);

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['event_id', 'is_active']);
        });

        // Deux entrees du meme nom sur un meme evenement rendraient
        // l'historique de controle inexploitable.
        DB::statement('CREATE UNIQUE INDEX entry_points_unique_name_per_event
            ON entry_points (event_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_points');
    }
};
