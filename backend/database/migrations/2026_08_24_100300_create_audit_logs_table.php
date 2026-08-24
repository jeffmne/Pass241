<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'audit (section 68). Table en ajout seul : aucune ligne ne doit
 * jamais etre modifiee ni supprimee, y compris par un Super Admin. Les regles
 * declenchees plus bas font respecter cette contrainte au niveau de
 * PostgreSQL, et pas seulement dans le code applicatif — un journal qu'on
 * peut reecrire ne prouve rien (sections 46, 67).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable : certaines actions (connexion, inscription) precedent
            // tout contexte d'organisation.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action');
            $table->nullableMorphs('auditable');

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('metadata')->nullable();

            // Pas de updated_at : une ligne d'audit n'est jamais mise a jour.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs est en ajout seul : % interdit', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only();

            CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete ON audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_append_only()');

        Schema::dropIfExists('audit_logs');
    }
};
