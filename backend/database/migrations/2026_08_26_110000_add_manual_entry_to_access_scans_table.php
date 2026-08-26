<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admission manuelle (section 27.15).
 *
 * Quand le QR est illisible — ecran casse, papier trempe, billet imprime trop
 * clair — l'agent retrouve le billet par sa reference et laisse entrer. Le
 * controle est reel, mais la VERIFICATION EST PLUS FAIBLE : plus de signature,
 * juste une reference lue a l'oeil.
 *
 * Cette colonne est ce qui rend la difference visible. Sans elle, une admission
 * manuelle et un scan signe se ressemblent parfaitement dans le journal — et
 * c'est precisement la distinction qui compte le lendemain, quand on cherche
 * comment quelqu'un est entre. Un agent qui admettrait la moitie d'une entree a
 * la main se verrait immediatement.
 *
 * Le fait est FIGE au meme titre que les autres : le declencheur est etendu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_scans', function (Blueprint $table) {
            $table->boolean('manual')->default(false)->after('device_id');
        });

        // Retrouver les admissions manuelles d'un evenement doit rester
        // immediat : c'est une lecture d'enquete, pas une statistique.
        DB::statement('CREATE INDEX access_scans_manual_idx
            ON access_scans (event_id, scanned_at) WHERE manual');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION access_scans_guard()
            RETURNS TRIGGER AS $$
            BEGIN
                IF NEW.ticket_id IS DISTINCT FROM OLD.ticket_id
                    OR NEW.event_id IS DISTINCT FROM OLD.event_id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.agent_id IS DISTINCT FROM OLD.agent_id
                    OR NEW.device_id IS DISTINCT FROM OLD.device_id
                    OR NEW.manual IS DISTINCT FROM OLD.manual
                    OR NEW.scanned_at IS DISTINCT FROM OLD.scanned_at
                THEN
                    RAISE EXCEPTION 'Les faits d''un scan sont figes.';
                END IF;

                IF NEW.result IS DISTINCT FROM OLD.result
                    AND NOT (OLD.result = 'ACCEPTED' AND NEW.result = 'CONFLICT_LOST')
                THEN
                    RAISE EXCEPTION 'Un scan ne change d''issue que pour perdre un arbitrage.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION access_scans_guard()
            RETURNS TRIGGER AS $$
            BEGIN
                IF NEW.ticket_id IS DISTINCT FROM OLD.ticket_id
                    OR NEW.event_id IS DISTINCT FROM OLD.event_id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.agent_id IS DISTINCT FROM OLD.agent_id
                    OR NEW.device_id IS DISTINCT FROM OLD.device_id
                    OR NEW.scanned_at IS DISTINCT FROM OLD.scanned_at
                THEN
                    RAISE EXCEPTION 'Les faits d''un scan sont figes.';
                END IF;

                IF NEW.result IS DISTINCT FROM OLD.result
                    AND NOT (OLD.result = 'ACCEPTED' AND NEW.result = 'CONFLICT_LOST')
                THEN
                    RAISE EXCEPTION 'Un scan ne change d''issue que pour perdre un arbitrage.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('DROP INDEX IF EXISTS access_scans_manual_idx');

        Schema::table('access_scans', function (Blueprint $table) {
            $table->dropColumn('manual');
        });
    }
};
