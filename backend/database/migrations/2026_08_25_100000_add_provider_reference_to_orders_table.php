<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Identifiant de la transaction chez l'operateur. Indispensable
            // pour reconcilier avec un releve Airtel ou Moov, et pour
            // interroger l'etat d'un paiement en polling de secours
            // (section 21.1).
            $table->string('provider_reference')->nullable()->after('payer_phone');

            $table->index('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['provider_reference']);
            $table->dropColumn('provider_reference');
        });
    }
};
