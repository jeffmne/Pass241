<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compteur de votes par votant et par categorie (sections 86.3, 86.5).
 *
 * POURQUOI UNE TABLE PLUTOT QU'UN `COUNT` SUR LES BULLETINS.
 *
 * Compter les bulletins a chaque soumission laisserait passer deux envois
 * simultanes : les deux liraient « deux voix utilisees sur trois », les deux
 * concluraient qu'il reste de la place, et le plafond serait depasse d'une
 * voix. La fenetre est etroite, mais un concours ou l'on peut voter deux fois
 * en cliquant vite est un concours truque.
 *
 * Cette table permet un `UPDATE … SET used = used + n WHERE used + n <= max`
 * ATOMIQUE : PostgreSQL serialise les deux ecritures sur la ligne, la seconde
 * lit la valeur deja incrementee, et son `WHERE` echoue. Aucun verrou explicite,
 * aucune transaction longue. C'est le mecanisme de `StockLedger::commit`,
 * applique a un compteur au lieu d'un stock.
 *
 * LE COMPTEUR N'EST PAS LA VERITE DES RESULTATS. Le total d'un candidat reste
 * la somme de ses bulletins confirmes ; cette table ne sert qu'a faire respecter
 * un plafond. Les deux valeurs peuvent d'ailleurs diverger legitimement — un
 * bulletin payant en attente consomme son quota sans etre compte dans les voix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voter_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_category_id')->constrained()->cascadeOnDelete();

            // Meme empreinte que sur les bulletins : jamais le numero en clair.
            $table->string('voter_hash', 64);

            $table->unsignedInteger('used')->default(0);

            $table->timestamps();

            /*
             * L'unicite EST le mecanisme, pas une precaution.
             *
             * Elle garantit qu'il n'existe qu'une seule ligne a incrementer par
             * couple votant-categorie. Deux lignes concurrentes rendraient
             * l'`UPDATE` atomique sans objet : chacune compterait de son cote.
             */
            $table->unique(['poll_category_id', 'voter_hash']);
        });

        DB::statement('ALTER TABLE voter_quotas ADD CONSTRAINT voter_quotas_used_check CHECK (
            used >= 0
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('voter_quotas');
    }
};
