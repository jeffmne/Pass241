<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billets (sections 18, 19).
 *
 * Un billet n'existe qu'apres confirmation du paiement (section 15.1) :
 * l'emission est branchee au module de paiement, ce schema en pose le
 * receptacle et les garanties.
 *
 * Le QR encode un jeton signe, jamais l'identifiant present ici (section 18).
 * `reference` est l'identifiant lisible imprime sur le billet, pas un secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->restrictOnDelete();

            $table->string('reference')->unique();
            $table->string('holder_name');

            $table->string('status')->default('ACTIVE');

            // Section 19 : un billet utilise ne peut jamais l'etre une seconde
            // fois. L'horodatage sert aussi a l'historique de l'agent.
            $table->timestamp('used_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['order_id']);
        });

        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (
            status IN ('ACTIVE', 'USED', 'CANCELLED', 'REFUNDED', 'EXPIRED')
        )");

        // Un billet marque comme utilise doit porter la date de son scan, et
        // reciproquement. Sans cette contrainte, un billet pourrait etre
        // « utilise » sans qu'on sache ni quand ni par qui.
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_used_at_check CHECK (
            (status = 'USED') = (used_at IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
