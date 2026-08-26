<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Frais de prestataire a la charge de l'acheteur (decision D-023).
 *
 * `amount` cesse d'etre le prix des billets pour devenir le montant REELLEMENT
 * DEBITE. Le prix des billets passe dans `subtotal`, et c'est desormais lui —
 * jamais `amount` — qui sert d'assiette a la commission Pass241 : un billet a
 * 10 000 FCFA rapporte 500 a la plateforme, que les frais du prestataire
 * soient de 200 ou de 400.
 *
 * Le bareme applique est fige sur la commande. Sans cet instantane, une
 * transaction d'aujourd'hui deviendrait inexplicable apres un changement de
 * prestataire — et un rapport recalcule six mois plus tard donnerait des
 * chiffres differents de ce qui a ete debite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Prix des billets seuls, assiette de la commission.
            $table->bigInteger('subtotal')->default(0)->after('amount');

            // Frais du prestataire, et le bareme qui les a produits.
            $table->bigInteger('psp_fee_amount')->default(0)->after('subtotal');
            $table->unsignedSmallInteger('psp_fee_basis_points')->default(0)->after('psp_fee_amount');
            $table->bigInteger('psp_fee_fixed')->default(0)->after('psp_fee_basis_points');

            // Prestataire ayant traite la transaction, distinct de l'operateur
            // choisi par l'acheteur (`payment_provider`, AIRTEL ou MOOV) : le
            // jour d'un changement d'agregateur, c'est cette colonne qui
            // permet encore de reconcilier l'historique.
            $table->string('psp')->nullable()->after('payment_provider');
        });

        // Les commandes existantes n'ont jamais porte de frais : leur montant
        // debite etait le prix des billets.
        DB::statement('UPDATE orders SET subtotal = amount');

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_check CHECK (
            subtotal >= 0 AND psp_fee_amount >= 0 AND psp_fee_fixed >= 0
        )');

        // L'eclatement doit tomber juste, toujours. Un total qui ne se
        // reconcilie pas avec ses parts est une erreur comptable qui passerait
        // inapercue dans un rapport.
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_split_check CHECK (
            amount = subtotal + psp_fee_amount
        )');

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_psp_rate_check CHECK (
            psp_fee_basis_points <= 10000
        )');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_psp_rate_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_split_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_amounts_check');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'subtotal',
                'psp_fee_amount',
                'psp_fee_basis_points',
                'psp_fee_fixed',
                'psp',
            ]);
        });
    }
};
