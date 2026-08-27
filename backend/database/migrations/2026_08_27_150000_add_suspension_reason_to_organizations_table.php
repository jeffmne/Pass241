<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motif de suspension d'une organisation (sections 10, 58).
 *
 * `suspended_at` disait QUAND, jamais POURQUOI. Un organisateur suspendu
 * telephone, et la seule reponse acceptable est une phrase — pas une date.
 *
 * Le motif est CONSERVE apres reactivation, volontairement : l'historique d'un
 * compte doit rester lisible, sans quoi trois suspensions successives se
 * liraient comme trois incidents isoles. Ce que la reactivation efface, c'est
 * la suspension elle-meme, pas sa memoire.
 *
 * La colonne reste hors de `$fillable` (section 67) : seule l'action dediee
 * l'ecrit, et chaque ecriture passe par le journal d'audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('suspension_reason')->nullable()->after('suspended_at');
        });

        /*
         * Une suspension a toujours un motif. La contrainte ne vaut que dans ce
         * sens : un motif peut survivre a la reactivation, mais une suspension
         * sans explication ne doit pas pouvoir exister — pas meme par une
         * commande artisan ou une correction manuelle.
         */
        DB::statement('ALTER TABLE organizations ADD CONSTRAINT organizations_suspension_reason_check CHECK (
            suspended_at IS NULL OR suspension_reason IS NOT NULL
        )');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_suspension_reason_check');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('suspension_reason');
        });
    }
};
