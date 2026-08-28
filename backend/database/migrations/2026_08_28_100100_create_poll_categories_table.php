<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Categories d'un scrutin (section 86.4).
 *
 * AUCUNE CATEGORIE PREDEFINIE. L'organisateur cree son vote, puis autant de
 * categories qu'il veut. « Miss Libreville », « Meilleur Artiste », « Prix du
 * Public » sont des lignes de cette table, jamais des cas d'un enum : une liste
 * fermee obligerait a livrer une version a chaque concours d'un type nouveau.
 *
 * LES CATEGORIES SONT INDEPENDANTES ENTRE ELLES. Fenetre, gratuite, prix,
 * plafond et visibilite des resultats se reglent categorie par categorie — un
 * meme evenement peut vouloir un prix du public gratuit en direct, et une
 * election principale payante scellee jusqu'a la fin.
 *
 * Les colonnes nullables `starts_at`, `ends_at` et `results_visibility` HERITENT
 * du scrutin quand elles sont vides. Le null porte donc une information : « pas
 * de reglage propre », qui n'est pas la meme chose qu'une valeur recopiee. Une
 * copie se desynchroniserait le jour ou l'organisateur decale la fenetre du
 * scrutin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poll_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();

            // Null = herite de la fenetre du scrutin (voir en-tete).
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            /*
             * Modele economique de la categorie (section 86.2).
             *
             * `unit_price` en entiers XAF (decision D-003) : jamais un flottant,
             * jamais un decimal. Le franc CFA n'a pas de subdivision en usage,
             * et tout le calcul du produit reste entier.
             */
            $table->boolean('is_paid')->default(false);
            $table->bigInteger('unit_price')->default(0);

            /*
             * Section 86.3 : c'est l'organisateur qui decide si une meme
             * personne peut voter plusieurs fois.
             *
             * NULL = illimite. C'est le defaut d'une categorie payante, ou
             * chaque vote paye compte et ou le paiement est lui-meme la
             * limite. Une valeur = plafond par votant, tenu par `voter_quotas`
             * avec un UPDATE conditionnel atomique.
             */
            $table->unsignedInteger('max_votes_per_voter')->nullable();

            // Null = herite du scrutin (voir en-tete).
            $table->string('results_visibility')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['poll_id', 'position']);
        });

        /*
         * Gratuit ET tarife n'a pas de sens, tarife A ZERO non plus.
         *
         * La regle vit en base et pas seulement dans un formulaire : une
         * categorie payante a 0 franc laisserait passer des bulletins payants
         * d'un montant nul, que la couche paiement accepterait sans rien
         * encaisser. C'est le genre d'incoherence qu'on ne decouvre qu'en
         * lisant un rapport de revenus qui ne tombe pas juste.
         */
        DB::statement('ALTER TABLE poll_categories ADD CONSTRAINT poll_categories_price_check CHECK (
            (is_paid = false AND unit_price = 0) OR (is_paid = true AND unit_price > 0)
        )');

        DB::statement('ALTER TABLE poll_categories ADD CONSTRAINT poll_categories_max_votes_check CHECK (
            max_votes_per_voter IS NULL OR max_votes_per_voter > 0
        )');

        DB::statement('ALTER TABLE poll_categories ADD CONSTRAINT poll_categories_results_visibility_check CHECK (
            results_visibility IS NULL OR results_visibility IN (\'LIVE\', \'AFTER_CLOSE\')
        )');

        /*
         * Une categorie qui regle sa propre fenetre la regle entierement.
         *
         * Une seule des deux bornes renseignee laisserait une fenetre a moitie
         * heritee, a moitie propre : impossible a expliquer a l'organisateur, et
         * impossible a verifier d'un coup d'oeil au moment de decider si un
         * bulletin est dans les temps.
         */
        DB::statement('ALTER TABLE poll_categories ADD CONSTRAINT poll_categories_window_check CHECK (
            (starts_at IS NULL AND ends_at IS NULL)
            OR (starts_at IS NOT NULL AND ends_at IS NOT NULL AND ends_at > starts_at)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_categories');
    }
};
