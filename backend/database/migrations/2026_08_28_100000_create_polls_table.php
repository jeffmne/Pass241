<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scrutins (section 86.4).
 *
 * `Poll` et non `Vote` : en francais, « vote » designe a la fois le scrutin et
 * le bulletin, et cette ambiguite se paierait a chaque relecture — `$vote->vote`
 * ne veut rien dire. En base et en code, le scrutin est un `poll` et le bulletin
 * un `ballot`. En interface, tout reste « Vote » : le vocabulaire de
 * l'organisateur ne change pas (section 86.5).
 *
 * CE QUI N'EXISTE PAS ICI, ET N'EXISTERA JAMAIS : aucune colonne de compteur de
 * votes, ni ici, ni sur les categories, ni sur les candidats. Le total est la
 * SOMME DES BULLETINS CONFIRMES, agregee en SQL. Une colonne la porterait en
 * double, et cette seconde verite serait modifiable — on ne peut pas falsifier
 * un chiffre qui n'est stocke nulle part (section 86.5, point 1).
 *
 * Le slug est unique sur toute la plateforme, comme celui des evenements et des
 * collectes : l'adresse publique est `/vote/{slug}`, sans segment
 * d'organisation (section 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('poster_path')->nullable();
            $table->string('banner_path')->nullable();

            /*
             * Fenetre de vote, en UTC (decision D-007), affichee au fuseau de
             * Libreville.
             *
             * Les deux sont OBLIGATOIRES, contrairement aux collectes. Un
             * concours sans date de fin n'a pas de gagnant : la cloture est ce
             * qui fige le classement et permet le rapport final (section 86.4).
             * Une collecte, elle, peut legitimement rester ouverte.
             */
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            $table->string('status')->default('DRAFT');

            /*
             * Visibilite des resultats sur la PAGE PUBLIQUE (section 86.4).
             *
             * Ce reglage ne cache jamais rien a l'organisateur : il voit
             * toujours ses chiffres, c'est son tableau de bord (section 87).
             *
             * Valeur par defaut prudente : masquer jusqu'a la cloture. Afficher
             * un classement en direct CHANGE le vote — un candidat en tete
             * attire, un candidat distance decourage. C'est un effet qu'on
             * choisit, pas un effet qu'on subit faute d'avoir regle un champ.
             */
            $table->string('results_visibility')->default('AFTER_CLOSE');

            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            // La tache de cloture balaie les scrutins actifs dont la date de fin
            // est passee : cet index lui evite un parcours complet.
            $table->index(['status', 'ends_at']);
            // La tache d'ouverture fait l'inverse sur les scrutins programmes.
            $table->index(['status', 'starts_at']);
        });

        DB::statement('ALTER TABLE polls ADD CONSTRAINT polls_status_check CHECK (
            status IN (\'DRAFT\', \'SCHEDULED\', \'ACTIVE\', \'CLOSED\', \'CANCELLED\')
        )');

        DB::statement('ALTER TABLE polls ADD CONSTRAINT polls_results_visibility_check CHECK (
            results_visibility IN (\'LIVE\', \'AFTER_CLOSE\')
        )');

        /*
         * Une fenetre de vote a une duree. `>=` laisserait passer un scrutin qui
         * ouvre et ferme a la meme seconde : personne ne pourrait voter, et
         * l'organisateur ne comprendrait pas pourquoi.
         */
        DB::statement('ALTER TABLE polls ADD CONSTRAINT polls_dates_check CHECK (
            ends_at > starts_at
        )');

        /*
         * Un statut et sa date vont ensemble, dans les deux sens.
         *
         * Un scrutin « annule » sans date d'annulation ne permettrait pas de
         * dire quand il s'est arrete ; une date sans le statut laisserait croire
         * l'inverse. Meme regle que les collectes et les invitations.
         */
        DB::statement('ALTER TABLE polls ADD CONSTRAINT polls_cancelled_check CHECK (
            (status = \'CANCELLED\') = (cancelled_at IS NOT NULL)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('polls');
    }
};
