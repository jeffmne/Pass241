<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Candidats d'une categorie (section 86.4).
 *
 * AUCUNE COLONNE DE COMPTEUR DE VOTES, et c'est le point le plus important de
 * cette table.
 *
 * C'est la traduction en schema de la regle « l'organisateur peut ajouter,
 * modifier ou desactiver un candidat — jamais modifier son nombre de votes ».
 * Le total d'un candidat est la somme de ses bulletins confirmes, agregee en
 * SQL avec un cache court, comme le montant collecte d'une campagne (regle
 * 80.1, decision D-031). On ne peut pas falsifier une colonne qui n'existe pas :
 * la regle n'est pas seulement interdite, elle est impossible.
 *
 * Un candidat se DESACTIVE, ne se supprime pas des qu'un bulletin existe : les
 * votes deja exprimes le referencent, et les faire disparaitre avec lui
 * fausserait le total de la categorie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_category_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('biography')->nullable();
            $table->string('photo_path')->nullable();

            /*
             * Numero de candidat — « votez 7 ».
             *
             * Chaine et non entier : les concours numerotent aussi en « A1 » ou
             * « MISS-03 », et un entier obligerait a inventer une correspondance
             * au moment de l'affichage. Unique DANS LA CATEGORIE seulement : le
             * candidat 1 de « Miss Libreville » et le candidat 1 de « Meilleur
             * Artiste » sont deux personnes differentes, et se numeroter a
             * partir de 1 dans chaque categorie est ce que tout le monde fait.
             */
            $table->string('number')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['poll_category_id', 'position']);

            $table->unique(['poll_category_id', 'number']);
        });

        /*
         * Un numero vide n'est pas un numero.
         *
         * Sans cette contrainte, deux candidats a numero `''` violeraient
         * l'unicite alors que l'organisateur pensait simplement n'avoir rempli
         * aucun des deux. Le null, lui, echappe a l'unicite en PostgreSQL —
         * c'est exactement le comportement voulu pour un champ facultatif.
         */
        DB::statement('ALTER TABLE candidates ADD CONSTRAINT candidates_number_check CHECK (
            number IS NULL OR length(trim(number)) > 0
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
