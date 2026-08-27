<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remboursements de dons (sections 46, 47).
 *
 * UNE OPERATION NOUVELLE, LIEE AU DON D'ORIGINE — jamais un `UPDATE` sur le
 * don. C'est la section 46 : « en cas d'erreur ou de remboursement, creer une
 * nouvelle operation financiere liee a la donation initiale. Ne jamais modifier
 * silencieusement l'ancien enregistrement. »
 *
 * Le don reste donc CONFIRME, y compris apres un remboursement total : il a bien
 * eu lieu. Ce qui change, c'est le montant NET qu'il represente, recalcule
 * partout a partir de ces lignes (section 47).
 *
 * Cette table est donc, elle aussi, en AJOUT SEUL pour ce qui compte : le
 * montant et le don rembourse ne bougent plus une fois ecrits, et une ligne ne
 * se supprime pas. Seule la trace du prestataire peut etre completee apres coup,
 * puisqu'elle arrive apres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // `restrictOnDelete` : un don confirme ne se supprime deja pas
            // (sections 44, 45). La base ferme la porte qui reste.
            $table->foreignId('donation_id')->constrained()->restrictOnDelete();

            // Reference lisible, citee au support et au donateur.
            $table->string('reference')->unique();

            /*
             * Part rendue, en entiers XAF (decision D-003). Toujours prise sur
             * le DON, jamais sur le montant debite : les frais du prestataire
             * n'ont jamais appartenu a l'organisateur (decision D-023), il n'y
             * a donc rien a lui reprendre dessus.
             */
            $table->bigInteger('amount');

            // Section 47 : l'operation doit etre tracable. Un remboursement sans
            // motif ne se justifie pas six mois plus tard.
            $table->text('reason');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('provider_reference')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['donation_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });

        DB::statement('ALTER TABLE donation_refunds ADD CONSTRAINT donation_refunds_amount_check CHECK (
            amount > 0
        )');

        /*
         * Immuabilite de ce qui fait le registre.
         *
         * Le montant et le don rembourse sont figes des l'ecriture ; la
         * reference du prestataire et la date d'execution, elles, arrivent
         * apres et doivent pouvoir etre completees. Une ligne ne se supprime
         * jamais : ce serait rendre de l'argent sans laisser de trace, donc
         * fausser le montant collecte affiche publiquement.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION donation_refunds_immutable()
            RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un remboursement de don ne se supprime pas (sections 46, 47)';
                END IF;

                IF NEW.amount <> OLD.amount
                    OR NEW.donation_id <> OLD.donation_id
                    OR NEW.organization_id <> OLD.organization_id
                THEN
                    RAISE EXCEPTION 'Le montant d''un remboursement est fige (section 46)';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER donation_refunds_no_tampering
                BEFORE UPDATE OR DELETE ON donation_refunds
                FOR EACH ROW EXECUTE FUNCTION donation_refunds_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS donation_refunds_no_tampering ON donation_refunds');
        DB::unprepared('DROP FUNCTION IF EXISTS donation_refunds_immutable()');

        Schema::dropIfExists('donation_refunds');
    }
};
