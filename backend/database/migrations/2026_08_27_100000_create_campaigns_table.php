<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campagnes de collecte (sections 34, 35).
 *
 * CE QUI N'EXISTE PAS ICI, ET N'EXISTERA JAMAIS : `collected_amount`,
 * `percentage`, `donations_count`.
 *
 * C'est la regle 80.1, traduite en schema. Le montant collecte est la SOMME DES
 * DONS CONFIRMES, moins les remboursements (sections 38, 47) : une colonne le
 * porterait en double, et cette seconde verite serait modifiable. Un
 * organisateur ne peut pas gonfler un chiffre qui n'est stocke nulle part.
 *
 * Le slug est unique sur toute la plateforme, comme celui des evenements :
 * l'adresse publique est `/collecte/{slug}`, sans segment d'organisation
 * (section 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            /*
             * Objectif financier, en entiers XAF (decision D-003).
             *
             * Toujours renseigne et strictement positif : c'est le
             * denominateur du pourcentage de progression (section 65). Un
             * objectif nul rendrait la barre de progression indefinie, et la
             * page publique mensongere.
             */
            $table->bigInteger('goal_amount');
            $table->string('currency', 3)->default('XAF');

            /*
             * Section 34.1 : le beneficiaire reste un texte libre pour le MVP.
             *
             * C'est presque toujours l'organisateur lui-meme aujourd'hui.
             * `beneficiary_id` est reserve — sans contrainte, faute d'entite a
             * referencer — pour le jour ou un beneficiaire tiers verifie sera
             * introduit, sans casser les campagnes existantes.
             */
            $table->string('beneficiary_label');
            $table->unsignedBigInteger('beneficiary_id')->nullable();

            // Dates en UTC (decision D-007), affichees au fuseau de Libreville.
            // Les deux sont facultatives : une collecte peut demarrer a sa
            // publication et rester ouverte tant que l'objectif n'est pas
            // atteint.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->string('poster_path')->nullable();
            $table->string('cover_path')->nullable();

            $table->string('status')->default('DRAFT');

            $table->timestamp('published_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            // La tache de cloture balaie les campagnes publiees dont la date de
            // fin est passee : cet index lui evite un parcours complet.
            $table->index(['status', 'ends_at']);
        });

        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_status_check CHECK (
            status IN ('DRAFT', 'PUBLISHED', 'PAUSED', 'COMPLETED', 'CANCELLED', 'EXPIRED')
        )");

        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_goal_check CHECK (
            goal_amount > 0
        )');

        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_dates_check CHECK (
            starts_at IS NULL OR ends_at IS NULL OR ends_at >= starts_at
        )');

        /*
         * Un statut et sa date vont ensemble, dans les deux sens.
         *
         * Une campagne « annulee » sans date d'annulation ne permettrait pas de
         * dire quand elle s'est arretee ; une date sans le statut laisserait
         * croire l'inverse. La base refuse les deux, comme pour les invitations.
         */
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_cancelled_check CHECK (
            (status = 'CANCELLED') = (cancelled_at IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
