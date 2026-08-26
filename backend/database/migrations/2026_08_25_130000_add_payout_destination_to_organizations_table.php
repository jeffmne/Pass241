<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Destination de reversement de l'organisation (section 21.2, decision D-025).
 *
 * Le numero mobile money sur lequel l'organisateur recoit le net de ses
 * ventes. Renseigne par lui, sans verification par code : le compte mobile
 * money est deja verifie par Airtel ou Moov, qui exigent une piece d'identite
 * a l'ouverture. Il y a donc bien une identite derriere ce numero, meme si
 * Pass241 ne la collecte pas.
 *
 * `payout_name` conserve le titulaire renvoye par le prestataire lors du
 * dernier enregistrement. Il n'a aucune valeur juridique : il sert a montrer a
 * l'organisateur, avant le premier versement, chez qui l'argent va partir. Un
 * virement mobile money ne se rappelle pas — une faute de frappe est
 * definitive, et c'est le seul garde-fou qui subsiste.
 *
 * `payout_updated_at` date le dernier changement. Rediriger les fonds vers un
 * autre numero est le vecteur de fraude principal une fois qu'une plateforme
 * sait payer : cette date, avec le journal d'audit, est ce qui permet de
 * reperer un changement suspect juste avant un reversement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('payout_phone')->nullable()->after('contact_phone');
            $table->string('payout_operator')->nullable()->after('payout_phone');
            $table->string('payout_name')->nullable()->after('payout_operator');
            $table->timestamp('payout_updated_at')->nullable()->after('payout_name');
        });

        // Meme contrainte que sur les commandes : l'operateur est l'un des deux
        // reseaux mobile money du marche, pas une chaine libre.
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_payout_operator_check CHECK (
            payout_operator IS NULL OR payout_operator IN ('AIRTEL', 'MOOV')
        )");

        // Un numero sans operateur, ou l'inverse, ne permet pas de virer :
        // les deux vont ensemble ou aucun des deux.
        DB::statement('ALTER TABLE organizations ADD CONSTRAINT organizations_payout_pair_check CHECK (
            (payout_phone IS NULL AND payout_operator IS NULL)
            OR (payout_phone IS NOT NULL AND payout_operator IS NOT NULL)
        )');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_payout_pair_check');
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_payout_operator_check');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'payout_phone',
                'payout_operator',
                'payout_name',
                'payout_updated_at',
            ]);
        });
    }
};
