<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le bulletin devient encaissable (jalon 9.4, sections 86.4, 86.5).
 *
 * TROIS MONTANTS, ET LE MEME DECOUPAGE QUE LES DONS ET LES COMMANDES :
 *
 * - `subtotal` est ce que valent les voix, et l'assiette de la commission ;
 * - `psp_fee_amount` est ce que prend l'operateur, AVANCE PAR LE VOTANT
 *   (decision D-023) ;
 * - `amount` est ce qui part du telephone, soit la somme des deux.
 *
 * `amount` VALAIT JUSQU'ICI LE SOUS-TOTAL. C'etait sans consequence tant que
 * seul le vote gratuit existait — les trois montants y sont nuls — mais cela
 * aurait fait dire a `amount` deux choses differentes selon le module : le
 * total debite pour un don, le sous-total pour un bulletin. Les statistiques,
 * les reversements et les exports lisent ces colonnes sans savoir d'ou elles
 * viennent ; une meme colonne doit y avoir un seul sens. La reprise se fait
 * maintenant, alors que la table ne porte que des donnees de demonstration.
 *
 * Aucune commission n'est prelevee sur les frais de l'operateur : ce serait
 * prelever sur l'argent d'un tiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ballots', function (Blueprint $table) {
            $table->bigInteger('subtotal')->default(0)->after('quantity');
            $table->bigInteger('psp_fee_amount')->default(0)->after('unit_price');

            /*
             * INSTANTANE DU BAREME DE FRAIS, comme sur les dons.
             *
             * Le prestataire et son bareme sont recopies sur le bulletin plutot
             * que relus dans la configuration : sans cet instantane, une
             * transaction devient inexplicable apres un changement de
             * prestataire ou de tarif. On saurait qu'on a preleve 125 francs,
             * sans plus pouvoir dire pourquoi.
             */
            $table->string('psp')->nullable()->after('psp_fee_amount');
            $table->unsignedInteger('psp_fee_basis_points')->default(0)->after('psp');
            $table->bigInteger('psp_fee_fixed')->default(0)->after('psp_fee_basis_points');

            /*
             * Un bulletin payant immobilise une voix le temps du paiement : le
             * quota est reserve des sa creation, sinon deux personnes pourraient
             * payer pour la meme derniere place. L'echeance est ce qui rend la
             * voix si le payeur ne valide jamais.
             *
             * Nul pour un vote gratuit, qui nait confirme et n'attend rien.
             */
            $table->timestamp('expires_at')->nullable()->after('failure_reason');

            // Balayage des bulletins expires : la tache cherche les PENDING dont
            // l'echeance est passee.
            $table->index(['status', 'expires_at']);
        });

        /*
         * Reprise des lignes existantes AVANT de poser les contraintes.
         *
         * Les bulletins gratuits deja en base portent `amount` = 0 et
         * `subtotal` = 0 par defaut : la nouvelle egalite est donc satisfaite.
         * La copie explicite couvre le cas ou un bulletin payant aurait ete
         * cree entre-temps, et rend la migration juste quel que soit l'etat.
         */
        DB::statement('UPDATE ballots SET subtotal = amount WHERE subtotal = 0');

        // L'ancienne egalite portait sur `amount` ; elle porte desormais sur le
        // sous-total, qui est le seul des trois a decouler des voix.
        DB::statement('ALTER TABLE ballots DROP CONSTRAINT IF EXISTS ballots_amount_check');
        DB::statement('ALTER TABLE ballots DROP CONSTRAINT IF EXISTS ballots_prices_check');

        DB::statement('ALTER TABLE ballots ADD CONSTRAINT ballots_amounts_check CHECK (
            unit_price >= 0
            AND psp_fee_amount >= 0
            AND subtotal = quantity * unit_price
            AND amount = subtotal + psp_fee_amount
        )');

        /*
         * Une echeance n'a de sens qu'en attente.
         *
         * Un bulletin confirme qui garderait une date d'expiration serait
         * ramasse par la tache de balayage et passe en echec — apres que
         * l'argent est parti.
         */
        DB::statement('ALTER TABLE ballots ADD CONSTRAINT ballots_expiry_check CHECK (
            expires_at IS NULL OR status = \'PENDING\'
        )');

        /*
         * L'immuabilite s'etend aux deux nouvelles colonnes.
         *
         * Sans cela, `subtotal` resterait modifiable et l'assiette de la
         * commission avec lui : on pourrait, en base, faire dire a un bulletin
         * paye 500 francs qu'il en valait 50 000. Le declencheur d'origine
         * figeait `amount` pour exactement cette raison.
         */
        DB::statement('
            CREATE OR REPLACE FUNCTION ballots_refuse_rewrite() RETURNS trigger AS $$
            BEGIN
                IF NEW.candidate_id IS DISTINCT FROM OLD.candidate_id
                    OR NEW.poll_category_id IS DISTINCT FROM OLD.poll_category_id
                    OR NEW.poll_id IS DISTINCT FROM OLD.poll_id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.quantity IS DISTINCT FROM OLD.quantity
                    OR NEW.unit_price IS DISTINCT FROM OLD.unit_price
                    OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
                    OR NEW.psp_fee_amount IS DISTINCT FROM OLD.psp_fee_amount
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.reference IS DISTINCT FROM OLD.reference
                    OR NEW.voter_hash IS DISTINCT FROM OLD.voter_hash
                THEN
                    RAISE EXCEPTION
                        \'Un bulletin ne se reecrit pas : candidat, quantite et montants sont definitifs.\';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ');
    }

    public function down(): void
    {
        DB::statement('
            CREATE OR REPLACE FUNCTION ballots_refuse_rewrite() RETURNS trigger AS $$
            BEGIN
                IF NEW.candidate_id IS DISTINCT FROM OLD.candidate_id
                    OR NEW.poll_category_id IS DISTINCT FROM OLD.poll_category_id
                    OR NEW.poll_id IS DISTINCT FROM OLD.poll_id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.quantity IS DISTINCT FROM OLD.quantity
                    OR NEW.unit_price IS DISTINCT FROM OLD.unit_price
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.reference IS DISTINCT FROM OLD.reference
                    OR NEW.voter_hash IS DISTINCT FROM OLD.voter_hash
                THEN
                    RAISE EXCEPTION
                        \'Un bulletin ne se reecrit pas : candidat, quantite et montant sont definitifs.\';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ');

        DB::statement('ALTER TABLE ballots DROP CONSTRAINT IF EXISTS ballots_expiry_check');
        DB::statement('ALTER TABLE ballots DROP CONSTRAINT IF EXISTS ballots_amounts_check');

        DB::statement('UPDATE ballots SET amount = subtotal');

        DB::statement('ALTER TABLE ballots ADD CONSTRAINT ballots_amount_check CHECK (
            amount = quantity * unit_price
        )');

        DB::statement('ALTER TABLE ballots ADD CONSTRAINT ballots_prices_check CHECK (
            unit_price >= 0 AND amount >= 0
        )');

        Schema::table('ballots', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn([
                'subtotal', 'psp_fee_amount', 'psp',
                'psp_fee_basis_points', 'psp_fee_fixed', 'expires_at',
            ]);
        });
    }
};
