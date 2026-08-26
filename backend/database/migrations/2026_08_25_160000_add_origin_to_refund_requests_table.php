<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Origine du remboursement, et sort des frais de paiement (decision D-028).
 *
 * Deux remboursements de meme montant n'ont pas la meme signification :
 *
 * - un client change d'avis : les frais du prestataire restent chez
 *   l'operateur, ils ne lui sont pas rendus (decision D-023) ;
 * - l'organisateur annule son evenement : le client n'y est pour rien, il
 *   recoit TOUT, frais compris. C'est l'organisateur qui les supporte.
 *
 * Sans cette distinction, `psp_fee_amount` serait ambigu — la meme colonne
 * signifierait « frais retenus » dans un cas et « frais rendus » dans l'autre,
 * et aucun rapport ne serait fiable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->string('origin')->default('CUSTOMER')->after('status');

            // Les frais sont-ils compris dans le montant rendu ?
            $table->boolean('includes_psp_fee')->default(false)->after('psp_fee_amount');
        });

        DB::statement("ALTER TABLE refund_requests ADD CONSTRAINT refund_requests_origin_check CHECK (
            origin IN ('CUSTOMER', 'EVENT_CANCELLED')
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refund_requests DROP CONSTRAINT IF EXISTS refund_requests_origin_check');

        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropColumn(['origin', 'includes_psp_fee']);
        });
    }
};
