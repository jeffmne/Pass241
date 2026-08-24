<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Types de billets (section 17).
 *
 * Le prix est un entier de francs CFA (decision D-003) : le franc n'a pas de
 * subdivision utilisee, et l'entier supprime toute erreur d'arrondi sur le
 * calcul de commission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // 0 = billet gratuit (section 17). Jamais de flottant.
            $table->bigInteger('price')->default(0);
            $table->string('currency', 3)->default('XAF');

            // Section 17 : « Ne jamais depasser la quantite disponible. »
            $table->unsignedInteger('quantity');

            // Section 17 : periode de vente. Nullable des deux cotes — la vente
            // suit alors simplement l'etat de l'evenement.
            $table->timestamp('sales_start_at')->nullable();
            $table->timestamp('sales_end_at')->nullable();

            // Section 17 : limite par commande, configuree par l'organisateur.
            $table->unsignedSmallInteger('max_per_order')->default(10);

            $table->boolean('is_visible')->default(true);
            $table->boolean('is_active')->default(true);

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['event_id', 'position']);
        });

        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_price_check
            CHECK (price >= 0)');

        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_quantity_check
            CHECK (quantity > 0)');

        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_max_per_order_check
            CHECK (max_per_order > 0)');

        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_sales_window_check CHECK (
            sales_start_at IS NULL OR sales_end_at IS NULL OR sales_end_at >= sales_start_at
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_types');
    }
};
