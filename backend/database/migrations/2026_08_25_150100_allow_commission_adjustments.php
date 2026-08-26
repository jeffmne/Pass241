<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustements de commission apres remboursement (section 24.1, decision D-027).
 *
 * La section 24.1 impose de recalculer la commission au prorata lors d'un
 * remboursement. La table l'interdisait : `unique(commissionable_type,
 * commissionable_id)` empechait toute seconde ligne pour une meme commande.
 *
 * La contrainte n'est pas supprimee, elle est RESSERREE : elle ne porte plus
 * que sur les lignes d'origine. C'est elle qui rend le calcul de commission
 * idempotent face a un webhook rejoue (section 23), et la perdre rouvrirait
 * cette faille.
 *
 * Un ajustement est une ligne a part entiere, liee a son origine par
 * `parent_commission_id`. La table reste en ajout seul : une correction ne
 * modifie jamais le passe, elle s'ajoute a lui (section 46).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            // Nul sur une ligne d'origine, renseigne sur un ajustement.
            $table->foreignId('parent_commission_id')
                ->nullable()
                ->after('commissionable_id')
                ->constrained('commissions')
                ->restrictOnDelete();
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique(['commissionable_type', 'commissionable_id']);
        });

        // Index partiel : une seule ligne D'ORIGINE par transaction, autant
        // d'ajustements que necessaire.
        DB::statement('CREATE UNIQUE INDEX commissions_one_original_per_transaction
            ON commissions (commissionable_type, commissionable_id)
            WHERE parent_commission_id IS NULL');

        // Un ajustement pointe vers une ligne d'origine, jamais vers un autre
        // ajustement : une chaine rendrait le cumul impossible a verifier.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION commissions_adjust_parent_is_original()
            RETURNS TRIGGER AS $$
            DECLARE
                parent_parent BIGINT;
            BEGIN
                IF NEW.parent_commission_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT parent_commission_id INTO parent_parent
                FROM commissions WHERE id = NEW.parent_commission_id;

                IF parent_parent IS NOT NULL THEN
                    RAISE EXCEPTION 'Un ajustement doit pointer vers une commission d''origine.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER commissions_adjustment_parent_check
                BEFORE INSERT ON commissions
                FOR EACH ROW EXECUTE FUNCTION commissions_adjust_parent_is_original();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS commissions_adjustment_parent_check ON commissions');
        DB::unprepared('DROP FUNCTION IF EXISTS commissions_adjust_parent_is_original()');
        DB::statement('DROP INDEX IF EXISTS commissions_one_original_per_transaction');

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_commission_id');
            $table->unique(['commissionable_type', 'commissionable_id']);
        });
    }
};
