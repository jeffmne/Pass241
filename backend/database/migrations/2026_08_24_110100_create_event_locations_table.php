<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lieu d'un evenement (sections 7, 11, 62).
 *
 * Table separee plutot que quelques colonnes sur `events` : le lieu accueillera
 * des coordonnees, un plan d'acces et, au module de controle d'acces, des
 * points d'entree (section 29). Le sortir maintenant evite d'elargir la table
 * `events` a chaque ajout.
 *
 * Relation 1-1 : un evenement a un lieu. Un catalogue de salles reutilisables
 * n'est pas demande, et l'introduire imposerait un ecran de gestion des lieux
 * dont personne n'a besoin aujourd'hui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->default('Libreville');
            $table->string('country', 2)->default('GA');

            // Renseignees plus tard, quand un plan d'acces sera propose sur la
            // page publique. Nullable : exiger des coordonnees a la creation
            // d'un evenement serait une friction inutile.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_locations');
    }
};
