<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reglages de la plateforme, modifiables par le Super Admin (section 58).
 *
 * CE QUI ENTRE ICI, ET CE QUI N'Y ENTRE JAMAIS.
 *
 * Y entrent les reglages METIER : le taux de commission, la mise en
 * maintenance. Des valeurs qu'un responsable produit change en connaissance de
 * cause, sans deploiement.
 *
 * N'y entrent PAS les secrets : cles d'API du prestataire de paiement,
 * identifiants S3, mot de passe SMTP. Ils restent en variables
 * d'environnement. Les deplacer en base les rendrait lisibles par toute
 * requete SQL, les ferait apparaitre dans une sauvegarde, et exigerait un
 * ecran pour les saisir — alors que la regle est justement qu'ils ne
 * s'affichent jamais.
 *
 * `updated_by` et le journal d'audit disent qui a change quoi : un taux de
 * commission qui bouge sans trace serait indefendable le jour ou un
 * organisateur conteste son reversement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();

            // Cle technique stable : le code s'y refere, jamais a un libelle.
            $table->string('key')->unique();

            /*
             * Valeur en JSON plutot qu'en colonnes typees.
             *
             * Les reglages n'ont pas de forme commune — un entier ici, un
             * booleen la, une phrase ensuite — et une table par type serait
             * absurde pour une poignee de lignes. Le typage vit dans le code,
             * la ou il se lit.
             */
            $table->jsonb('value');

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        // Les valeurs de depart sont celles de la configuration : la table ne
        // change rien tant que personne n'y touche.
        DB::table('platform_settings')->insert([
            [
                'key' => 'commission.basis_points.TICKETING',
                'value' => json_encode(500),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'commission.basis_points.DONATION',
                'value' => json_encode(250),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
