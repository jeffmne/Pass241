<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dons (sections 39 a 46, 63).
 *
 * CE QUI N'EXISTE PAS ICI, ET NE DOIT JAMAIS EXISTER : `message`, `comment`,
 * `note`, `testimonial`. La section 42 l'interdit, et la facon la plus sure de
 * tenir cette regle est qu'aucune colonne ne puisse en accueillir un.
 *
 * L'ANONYMAT NE PORTE QUE SUR L'AFFICHAGE (sections 40, 41). L'identite reelle
 * est conservee — paiement, audit, rapprochement financier, lutte contre la
 * fraude en dependent — mais `is_anonymous` decide de ce que la page publique
 * montre. Le montant, lui, reste public dans tous les cas.
 *
 * UN DON CONFIRME EST IMMUABLE, et c'est la base qui le garantit (voir le
 * declencheur en fin de fichier). Les sections 44 a 46 interdisent a
 * l'organisateur de supprimer un don, d'en modifier le montant, la date ou
 * l'identite, ou de reveler un donateur anonyme. Une regle applicative seule
 * ne tiendrait pas devant une commande artisan ou une correction manuelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            /*
             * `restrictOnDelete` et non `cascade` : supprimer une campagne ne
             * doit pas pouvoir emporter ses dons (section 45). Le modele refuse
             * deja de supprimer une collecte publiee ; la base ferme la porte
             * qui reste.
             */
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();

            // Reference lisible, citee au support et portee par les webhooks.
            $table->string('reference')->unique();

            /*
             * Identite du donateur. Facultative : on peut donner sans laisser
             * son nom, exactement comme on achete un billet sans compte
             * (section 15).
             */
            $table->string('donor_name')->nullable();
            $table->string('donor_email')->nullable();
            $table->boolean('is_anonymous')->default(false);

            // Sections 16.1, 52 : donnees de paiement, privees.
            $table->string('payment_provider');
            $table->string('payer_phone');
            $table->string('psp')->nullable();

            /*
             * Trois montants, comme pour une commande (decision D-023) :
             *
             * - `subtotal` est LE DON, et l'assiette de la commission ;
             * - `psp_fee_amount` est ce que prend le prestataire, avance par le
             *   donateur pour que la cause recoive le montant annonce ;
             * - `amount` est ce qui part du telephone.
             *
             * Les confondre ferait prelever une commission sur l'argent d'un
             * tiers, et afficherait sur la page publique un montant collecte
             * qui n'est pas celui qui a ete donne.
             */
            $table->bigInteger('subtotal');
            $table->bigInteger('psp_fee_amount')->default(0);
            $table->unsignedSmallInteger('psp_fee_basis_points')->default(0);
            $table->bigInteger('psp_fee_fixed')->default(0);
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('XAF');

            $table->string('status')->default('PENDING');
            $table->string('provider_reference')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            // L'historique public et le total collecte lisent tous deux les
            // dons confirmes d'une campagne : c'est l'index qui compte.
            $table->index(['campaign_id', 'status', 'confirmed_at']);
            $table->index(['organization_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement("ALTER TABLE donations ADD CONSTRAINT donations_status_check CHECK (
            status IN ('PENDING', 'CONFIRMED', 'FAILED', 'CANCELLED')
        )");

        DB::statement("ALTER TABLE donations ADD CONSTRAINT donations_provider_check CHECK (
            payment_provider IN ('AIRTEL', 'MOOV')
        )");

        // Un don a zero franc n'est pas un don. Le montant debite ne peut pas
        // etre inferieur au don lui-meme.
        DB::statement('ALTER TABLE donations ADD CONSTRAINT donations_amounts_check CHECK (
            subtotal > 0 AND psp_fee_amount >= 0 AND amount = subtotal + psp_fee_amount
        )');

        /*
         * Un statut et sa date vont ensemble, dans les deux sens. Un don
         * « confirme » sans date de confirmation ne permettrait pas de dire
         * quand l'argent est entre ; une date sans le statut laisserait croire
         * l'inverse.
         */
        DB::statement("ALTER TABLE donations ADD CONSTRAINT donations_confirmed_check CHECK (
            (status = 'CONFIRMED') = (confirmed_at IS NOT NULL)
        )");

        /*
         * Immuabilite d'un don confirme (sections 44 a 46).
         *
         * Le declencheur fige ce qui fait la valeur du registre : le montant,
         * la campagne beneficiaire, l'identite affichee, le statut et sa date.
         * Ce qui reste modifiable — la reference du prestataire, par exemple —
         * ne change rien a ce que le public lit.
         *
         * Un remboursement ne passera pas par ici : ce sera une operation
         * distincte, liee au don d'origine, jamais un UPDATE (section 47).
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION donations_confirmed_immutable()
            RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'CONFIRMED' THEN
                        RAISE EXCEPTION 'Un don confirme ne se supprime pas (sections 44, 45)';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status = 'CONFIRMED' AND (
                    NEW.amount <> OLD.amount
                    OR NEW.subtotal <> OLD.subtotal
                    OR NEW.campaign_id <> OLD.campaign_id
                    OR NEW.status <> OLD.status
                    OR NEW.confirmed_at IS DISTINCT FROM OLD.confirmed_at
                    OR NEW.is_anonymous <> OLD.is_anonymous
                    OR NEW.donor_name IS DISTINCT FROM OLD.donor_name
                ) THEN
                    RAISE EXCEPTION 'Un don confirme est immuable (sections 44 a 46)';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER donations_no_tampering
                BEFORE UPDATE OR DELETE ON donations
                FOR EACH ROW EXECUTE FUNCTION donations_confirmed_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS donations_no_tampering ON donations');
        DB::unprepared('DROP FUNCTION IF EXISTS donations_confirmed_immutable()');

        Schema::dropIfExists('donations');
    }
};
