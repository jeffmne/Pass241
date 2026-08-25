<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commissions prelevees par la plateforme (section 21.2).
 *
 * Table en ajout seul, comme le journal d'audit : une commission enregistree
 * est une donnee financiere definitive. Une correction passe par une nouvelle
 * ligne liee au remboursement, jamais par une modification (sections 46, 67).
 *
 * `rate_basis_points` est un instantane du taux applique. Changer le taux
 * demain ne doit rien changer aux transactions d'hier — sans cette colonne, un
 * rapport recalcule six mois plus tard donnerait des chiffres differents de
 * ceux qui ont ete reverses.
 *
 * Polymorphe : la billetterie aujourd'hui, les collectes au module suivant,
 * avec des taux distincts (5 % et 2,5 %).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->morphs('commissionable');

            $table->string('type');

            // Tous les montants en entiers XAF (decision D-003).
            $table->bigInteger('gross_amount');
            $table->unsignedSmallInteger('rate_basis_points');
            $table->bigInteger('commission_amount');
            $table->bigInteger('net_amount');
            $table->string('currency', 3)->default('XAF');

            // Pas de updated_at : une commission ne se met jamais a jour.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            // Une transaction ne peut porter qu'une seule commission : c'est ce
            // qui rend le calcul idempotent face a un webhook rejoue.
            $table->unique(['commissionable_type', 'commissionable_id']);
        });

        DB::statement("ALTER TABLE commissions ADD CONSTRAINT commissions_type_check CHECK (
            type IN ('TICKETING', 'DONATION')
        )");

        DB::statement('ALTER TABLE commissions ADD CONSTRAINT commissions_amounts_check CHECK (
            gross_amount >= 0 AND commission_amount >= 0 AND net_amount >= 0
        )');

        // La somme doit tomber juste, toujours. Une commission qui ne se
        // reconcilie pas avec son brut est une erreur comptable qui passerait
        // inapercue dans un rapport.
        DB::statement('ALTER TABLE commissions ADD CONSTRAINT commissions_split_check CHECK (
            gross_amount = commission_amount + net_amount
        )');

        DB::statement('ALTER TABLE commissions ADD CONSTRAINT commissions_rate_check CHECK (
            rate_basis_points <= 10000
        )');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION commissions_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'commissions est en ajout seul : % interdit', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER commissions_no_update
                BEFORE UPDATE ON commissions
                FOR EACH ROW EXECUTE FUNCTION commissions_append_only();

            CREATE TRIGGER commissions_no_delete
                BEFORE DELETE ON commissions
                FOR EACH ROW EXECUTE FUNCTION commissions_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS commissions_no_update ON commissions');
        DB::unprepared('DROP TRIGGER IF EXISTS commissions_no_delete ON commissions');
        DB::unprepared('DROP FUNCTION IF EXISTS commissions_append_only()');

        Schema::dropIfExists('commissions');
    }
};
