<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resultats figes a la cloture (sections 86.4, 86.5).
 *
 * CETTE TABLE EST LA SEULE DU MODULE QUI STOCKE DES CHIFFRES DE VOTES, et c'est
 * une exception assumee a la regle qui l'interdit partout ailleurs.
 *
 * Pendant le scrutin, aucun total n'existe en base : tout est agrege en SQL sur
 * les bulletins, precisement pour qu'il n'y ait rien a falsifier (section 86.5,
 * point 1). Mais un classement PUBLIE doit cesser de bouger. Un remboursement
 * arrive apres la cloture retirerait de l'argent, et un recalcul ferait alors
 * varier un revenu deja annonce — voire, si un bulletin changeait de statut, un
 * rang deja proclame.
 *
 * D'ou cet instantane : il ne remplace pas le calcul, il le CONSERVE a un
 * instant precis. Avant la cloture, le classement se calcule ; apres, il se
 * lit ici.
 *
 * EN AJOUT SEUL, comme les commissions. Ni modification ni suppression : un
 * resultat proclame ne se corrige pas, il se reprocheraient. Deux declencheurs
 * le garantissent, y compris contre une commande artisan ou un psql — c'est la
 * difference entre une regle interdite et une regle impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poll_results', function (Blueprint $table) {
            $table->id();

            /*
             * `organization_id` figure ici alors que le scrutin le porte deja,
             * pour la meme raison que sur les bulletins : le filtrage par
             * organisation se fait sans jointure, et une tache planifiee — qui
             * travaille sans contexte — retrouve l'organisation en une lecture.
             */
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('poll_category_id')->constrained()->cascadeOnDelete();

            /*
             * `restrictOnDelete` : supprimer un candidat emporterait son
             * resultat proclame. La base refuse ; l'application, elle, propose
             * la desactivation, qui laisse les voix comptees.
             */
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();

            /*
             * Le nom du candidat est RECOPIE, et ce n'est pas une duplication
             * de confort.
             *
             * Un rapport final doit pouvoir etre relu dans deux ans et dire ce
             * qui a ete proclame ce jour-la. Si l'organisateur corrige
             * l'orthographe d'un nom apres la cloture, le classement archive
             * doit garder celui sous lequel le public a vote.
             */
            $table->string('candidate_name');
            $table->string('candidate_number')->nullable();

            $table->unsignedInteger('rank');
            $table->unsignedBigInteger('votes');
            $table->unsignedBigInteger('ballots');

            /*
             * Revenu = SOUS-TOTAL des bulletins confirmes, jamais le total
             * debite : les frais de l'operateur ne reviennent pas a
             * l'organisateur (decision D-023). Meme definition que dans
             * `VotingStatistics`, sans quoi le rapport final contredirait
             * l'ecran de suivi.
             */
            $table->unsignedBigInteger('revenue')->default(0);

            // Part des voix, en centiemes de point : 4235 = 42,35 %. Entier,
            // comme tout le reste (decision D-003) — un flottant finirait par
            // faire un total de 99,99 % sur un rapport officiel.
            $table->unsignedInteger('share_basis_points')->default(0);

            $table->timestamp('frozen_at');

            /*
             * Une categorie ne se fige QU'UNE FOIS. C'est l'unicite qui rend
             * l'operation rejouable sans danger : une tache planifiee relancee,
             * ou une cloture manuelle suivie du balayage automatique, ne
             * produisent pas deux classements concurrents.
             */
            $table->unique(['poll_category_id', 'candidate_id']);

            $table->index(['poll_id', 'rank']);
            $table->index(['organization_id', 'frozen_at']);
        });

        DB::statement('ALTER TABLE poll_results ADD CONSTRAINT poll_results_rank_check CHECK (
            rank >= 1
        )');

        DB::statement('ALTER TABLE poll_results ADD CONSTRAINT poll_results_share_check CHECK (
            share_basis_points <= 10000
        )');

        /*
         * -------------------------------------------------------------------
         * L'AJOUT SEUL, TENU PAR LA BASE
         * -------------------------------------------------------------------
         *
         * Un resultat proclame ne se modifie pas et ne s'efface pas. Ecrite
         * dans une action PHP, la regle ne vaudrait que pour le code qui passe
         * par l'action ; ecrite ici, elle vaut aussi pour un
         * `PollResult::query()->update()` oublie dans une commande, pour un
         * tinker, et pour quelqu'un qui ouvre psql.
         */
        DB::statement('
            CREATE OR REPLACE FUNCTION poll_results_are_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    \'Un resultat proclame ne se modifie pas : le classement fige a la cloture est definitif.\';
            END;
            $$ LANGUAGE plpgsql;
        ');

        DB::statement('
            CREATE TRIGGER poll_results_refuse_update
            BEFORE UPDATE ON poll_results
            FOR EACH ROW EXECUTE FUNCTION poll_results_are_append_only();
        ');

        DB::statement('
            CREATE TRIGGER poll_results_refuse_delete
            BEFORE DELETE ON poll_results
            FOR EACH ROW EXECUTE FUNCTION poll_results_are_append_only();
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS poll_results_refuse_delete ON poll_results');
        DB::statement('DROP TRIGGER IF EXISTS poll_results_refuse_update ON poll_results');
        DB::statement('DROP FUNCTION IF EXISTS poll_results_are_append_only()');

        Schema::dropIfExists('poll_results');
    }
};
