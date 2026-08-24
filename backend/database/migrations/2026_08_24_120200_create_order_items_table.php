<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lignes d'une commande (section 20).
 *
 * `unit_price` est un instantane du prix au moment de l'achat, jamais une
 * lecture du type de billet. Si l'organisateur change son tarif demain, la
 * commande d'hier doit continuer a dire ce qui a reellement ete paye : c'est
 * la base de toute reconciliation et de tout remboursement (section 46).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // `restrictOnDelete` : un type de billet vendu ne peut plus etre
            // supprime. Effacer la ligne d'origine d'une commande payee
            // rendrait la comptabilite inexploitable.
            $table->foreignId('ticket_type_id')->constrained()->restrictOnDelete();

            $table->string('ticket_type_name');
            $table->unsignedSmallInteger('quantity');
            $table->bigInteger('unit_price');
            $table->bigInteger('subtotal');

            $table->timestamps();

            $table->index('ticket_type_id');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_check
            CHECK (quantity > 0)');

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_prices_check
            CHECK (unit_price >= 0 AND subtotal >= 0)');

        // Le sous-total doit etre le produit exact de ses deux facteurs. Une
        // incoherence ici passerait inapercue dans un rapport de revenus.
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_subtotal_check
            CHECK (subtotal = unit_price * quantity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
