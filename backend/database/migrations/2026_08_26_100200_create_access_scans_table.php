<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des controles a l'entree (sections 28, 31.1).
 *
 * EN AJOUT SEUL, comme le journal d'audit et les commissions. Chaque scan y
 * figure, ACCEPTE COMME REFUSE : c'est le refus qui a le plus de valeur le
 * lendemain, quand quelqu'un affirme s'etre vu refuser l'entree avec un billet
 * valide.
 *
 * `scanned_at` est l'heure LOCALE DE L'APPAREIL, `synced_at` celle du serveur.
 * Les deux sont indispensables et ne se remplacent pas : la section 31.1 fait
 * gagner le scan au timestamp local le plus ancien, quel que soit l'ordre
 * d'arrivee sur le reseau. Un agent hors connexion peut synchroniser une heure
 * plus tard sans perdre son antériorite.
 *
 * LIMITE CONNUE, documentee dans la spec : cette regle suppose une horloge
 * d'appareil fiable. `clock_offset_seconds` conserve l'ecart mesure entre
 * l'horloge de l'appareil et celle du serveur au moment de la synchronisation
 * — sans lui, un appareil deregle fausserait un arbitrage sans qu'on puisse le
 * demontrer apres coup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // Nul quand le QR n'a pas ete reconnu : le scan est journalise
            // quand meme, c'est meme le cas le plus interessant.
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('entry_point_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Identifiant d'appareil, fourni par l'application.
             *
             * Sert a deux choses : reconnaitre un rejeu de la file locale apres
             * une synchronisation interrompue, et retrouver quel terminal a
             * laisse passer un billet en cas de litige.
             */
            $table->string('device_id')->nullable();

            $table->string('result');

            $table->timestamp('scanned_at');
            $table->timestamp('synced_at')->useCurrent();
            $table->integer('clock_offset_seconds')->nullable();

            // Scan gagnant, quand celui-ci a perdu l'arbitrage du double scan.
            $table->foreignId('conflict_with')->nullable()->constrained('access_scans')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_id', 'scanned_at']);
            $table->index(['ticket_id', 'result']);
            $table->index(['agent_id', 'scanned_at']);
        });

        DB::statement("ALTER TABLE access_scans ADD CONSTRAINT access_scans_result_check CHECK (
            result IN ('ACCEPTED', 'ALREADY_USED', 'INVALID', 'REFUNDED', 'CANCELLED', 'WRONG_EVENT', 'CONFLICT_LOST')
        )");

        /*
         * Un appareil ne produit qu'un scan par billet et par horodatage local.
         *
         * C'est ce qui rend la synchronisation IDEMPOTENTE : une file locale
         * renvoyee apres une coupure ne cree pas de doublons, et l'application
         * peut retransmettre sans risque plutot que de deviner ce qui est passe.
         */
        DB::statement('CREATE UNIQUE INDEX access_scans_unique_per_device
            ON access_scans (device_id, ticket_id, scanned_at)
            WHERE device_id IS NOT NULL AND ticket_id IS NOT NULL');

        /*
         * Les FAITS d'un scan sont figes ; seule l'issue de l'arbitrage peut
         * encore etre tranchee.
         *
         * Un scan hors connexion arrive parfois APRES un scan plus recent : le
         * plus ancien gagne (section 31.1), et celui qui avait ete accepte doit
         * alors etre declasse. C'est la seule modification permise, et elle ne
         * peut se produire qu'une fois — un scan deja perdant ne se rejoue pas.
         *
         * Tout le reste — billet, appareil, agent, horodatage local — est
         * definitif : c'est ce qui fait foi si quelqu'un conteste une entree.
         */
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

            CREATE TRIGGER access_scans_facts_are_frozen
                BEFORE UPDATE ON access_scans
                FOR EACH ROW EXECUTE FUNCTION access_scans_guard();

            CREATE OR REPLACE FUNCTION access_scans_no_delete()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Un scan ne se supprime pas.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER access_scans_never_deleted
                BEFORE DELETE ON access_scans
                FOR EACH ROW EXECUTE FUNCTION access_scans_no_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS access_scans_facts_are_frozen ON access_scans');
        DB::unprepared('DROP TRIGGER IF EXISTS access_scans_never_deleted ON access_scans');
        DB::unprepared('DROP FUNCTION IF EXISTS access_scans_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS access_scans_no_delete()');

        Schema::dropIfExists('access_scans');
    }
};
