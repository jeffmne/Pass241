<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Origine d'une commande (sections 15, 25).
 *
 * Les invitations empruntent le meme chemin que les achats : une commande, une
 * ligne, puis des billets. C'est ce qui leur donne gratuitement le stock
 * (decision D-017 : « reserve » = commande en attente non expiree), le QR
 * signe, le PDF, l'espace « Mes billets » et le controle a l'entree.
 *
 * Ce que la colonne evite, c'est la confusion inverse : une invitation n'est
 * PAS une vente. Sans elle, le tableau de bord annoncerait des billets vendus
 * qui n'ont rapporte rien, et un organisateur qui invite cinquante personnes
 * lirait un chiffre d'affaires faux.
 *
 * `payment_provider` et `payer_phone` deviennent nuls pour les invitations : y
 * inscrire « AIRTEL » pour une commande qui n'a jamais touche Airtel
 * corromprait la seule colonne sur laquelle on peut rapprocher les paiements
 * d'un operateur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('origin')->default('PURCHASE')->after('reference');
        });

        DB::statement('ALTER TABLE orders ALTER COLUMN payment_provider DROP NOT NULL');
        DB::statement('ALTER TABLE orders ALTER COLUMN payer_phone DROP NOT NULL');

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_origin_check CHECK (
            origin IN ('PURCHASE', 'INVITATION')
        )");

        /*
         * Un achat a toujours son operateur et son numero ; une invitation n'en
         * a jamais. La contrainte dit les deux sens : elle empeche autant une
         * vente sans moyen de paiement qu'une invitation qui pretendrait avoir
         * ete payee par Airtel.
         */
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_provider_check');

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_provider_check CHECK (
            (origin = 'PURCHASE' AND payment_provider IN ('AIRTEL', 'MOOV') AND payer_phone IS NOT NULL)
            OR
            (origin = 'INVITATION' AND payment_provider IS NULL AND payer_phone IS NULL)
        )");

        /*
         * Une invitation ne coute rien, et ne peut pas couter quelque chose.
         * La regle vit en base et non dans le code : c'est la seule facon
         * qu'elle tienne aussi pour un import, une commande artisan ou une
         * correction manuelle.
         */
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_invitation_is_free_check CHECK (
            origin <> 'INVITATION' OR (amount = 0 AND subtotal = 0 AND psp_fee_amount = 0)
        )");

        // Les ecrans qui listent des ventes filtrent sur cette colonne : autant
        // qu'elle soit indexee avec le statut, qui les accompagne toujours.
        DB::statement('CREATE INDEX orders_origin_status_idx ON orders (origin, status)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_origin_status_idx');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_invitation_is_free_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_provider_check');

        DB::statement("DELETE FROM orders WHERE origin = 'INVITATION'");

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_provider_check CHECK (
            payment_provider IN ('AIRTEL', 'MOOV')
        )");

        DB::statement('ALTER TABLE orders ALTER COLUMN payment_provider SET NOT NULL');
        DB::statement('ALTER TABLE orders ALTER COLUMN payer_phone SET NOT NULL');

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_origin_check');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
