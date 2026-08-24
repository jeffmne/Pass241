<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evenements (sections 11 a 14).
 *
 * Le slug est unique sur toute la plateforme, et non par organisation :
 * l'adresse publique est `/e/{slug}` sans segment d'organisation (section 6).
 * Deux organisations ne peuvent donc pas revendiquer le meme slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Section 7 : le mini-site affiche un programme et des
            // informations pratiques distincts de la description.
            $table->text('programme')->nullable();
            $table->text('practical_info')->nullable();

            // Section 15.2 : « Musique • Culture • Lifestyle ». Liste fermee
            // cote applicatif (EventCategory), stockee en jsonb pour rester
            // interrogeable si un filtre par categorie devient utile.
            $table->jsonb('categories')->nullable();

            // Toutes les dates sont en UTC (decision D-007). L'affichage se
            // fait au fuseau de Libreville.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            // Section 11 : capacite maximale. Distincte du stock des types de
            // billets (section 17) : c'est la limite physique du lieu, pas ce
            // qui est mis en vente.
            $table->unsignedInteger('max_capacity')->nullable();

            $table->string('poster_path')->nullable();
            $table->string('cover_path')->nullable();

            $table->string('status')->default('DRAFT');
            $table->string('visibility')->default('LISTED');

            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['status', 'starts_at']);
        });

        DB::statement("ALTER TABLE events ADD CONSTRAINT events_status_check CHECK (
            status IN ('DRAFT', 'PUBLISHED', 'SOLD_OUT', 'ONGOING', 'COMPLETED', 'CANCELLED', 'ARCHIVED')
        )");

        DB::statement("ALTER TABLE events ADD CONSTRAINT events_visibility_check CHECK (
            visibility IN ('LISTED', 'UNLISTED')
        )");

        // Une date de fin anterieure au debut n'est pas une erreur de saisie
        // rattrapable plus tard : elle casserait le passage automatique en
        // ONGOING puis COMPLETED. La base la refuse.
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_dates_check CHECK (
            ends_at IS NULL OR ends_at >= starts_at
        )');

        DB::statement('ALTER TABLE events ADD CONSTRAINT events_capacity_check CHECK (
            max_capacity IS NULL OR max_capacity > 0
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
