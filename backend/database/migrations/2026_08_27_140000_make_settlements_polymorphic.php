<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le reversement cesse de ne connaitre que les evenements (decision D-041).
 *
 * `event_id` supposait qu'un reversement porte toujours sur de la billetterie.
 * Une collecte encaisse de l'argent par le meme chemin, preleve la meme
 * commission — et n'a pas d'evenement. Sans cette migration, les dons seraient
 * encaissables mais jamais reversables : le pire des etats possibles.
 *
 * CE QUI NE CHANGE PAS, et qui est l'essentiel : UN SEUL reversement par
 * objet. L'unicite passe de `event_id` au couple polymorphe. C'est elle qui
 * empeche de payer deux fois apres un echec transitoire, et une somme envoyee
 * en double ne se recupere pas.
 *
 * Le declencheur qui gele les montants est reecrit pour surveiller le nouveau
 * couple : sans cela, on pourrait deplacer un reversement d'un evenement vers
 * une collecte apres coup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->string('settlable_type')->nullable()->after('organization_id');
            $table->unsignedBigInteger('settlable_id')->nullable()->after('settlable_type');
        });

        // Report des reversements existants : ils portent tous sur un
        // evenement, et doivent continuer a dire ou l'argent est parti.
        DB::table('settlements')
            ->whereNotNull('event_id')
            ->update([
                'settlable_type' => 'App\\Models\\Event',
                'settlable_id' => DB::raw('event_id'),
            ]);

        Schema::table('settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');

            // L'unicite du couple remplace celle de `event_id` : un seul
            // reversement par evenement, par collecte, et demain par scrutin.
            $table->unique(['settlable_type', 'settlable_id'], 'settlements_settlable_unique');
        });

        DB::statement('ALTER TABLE settlements ALTER COLUMN settlable_type SET NOT NULL');
        DB::statement('ALTER TABLE settlements ALTER COLUMN settlable_id SET NOT NULL');

        // Le gel des montants suit la colonne : on ne doit pas pouvoir
        // rattacher un reversement deja parti a autre chose.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION settlements_freeze_money()
            RETURNS TRIGGER AS $$
            BEGIN
                IF NEW.gross_amount IS DISTINCT FROM OLD.gross_amount
                    OR NEW.commission_amount IS DISTINCT FROM OLD.commission_amount
                    OR NEW.net_amount IS DISTINCT FROM OLD.net_amount
                    OR NEW.recipient_phone IS DISTINCT FROM OLD.recipient_phone
                    OR NEW.recipient_operator IS DISTINCT FROM OLD.recipient_operator
                    OR NEW.settlable_type IS DISTINCT FROM OLD.settlable_type
                    OR NEW.settlable_id IS DISTINCT FROM OLD.settlable_id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                THEN
                    RAISE EXCEPTION 'Le montant et le beneficiaire d''un reversement sont figes.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->constrained()->restrictOnDelete();
        });

        DB::statement("UPDATE settlements SET event_id = settlable_id WHERE settlable_type = 'App\\Models\\Event'");

        // Les reversements de collectes n'ont pas d'equivalent dans l'ancien
        // schema : les conserver sans rattachement vaut mieux que les perdre.
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropUnique('settlements_settlable_unique');
            $table->dropColumn(['settlable_type', 'settlable_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION settlements_freeze_money()
            RETURNS TRIGGER AS $$
            BEGIN
                IF NEW.gross_amount IS DISTINCT FROM OLD.gross_amount
                    OR NEW.commission_amount IS DISTINCT FROM OLD.commission_amount
                    OR NEW.net_amount IS DISTINCT FROM OLD.net_amount
                    OR NEW.recipient_phone IS DISTINCT FROM OLD.recipient_phone
                    OR NEW.recipient_operator IS DISTINCT FROM OLD.recipient_operator
                    OR NEW.event_id IS DISTINCT FROM OLD.event_id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                THEN
                    RAISE EXCEPTION 'Le montant et le beneficiaire d''un reversement sont figes.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
