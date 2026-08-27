<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le journal des webhooks cesse de ne connaitre que les commandes (D-041).
 *
 * `order_id` supposait qu'un paiement porte toujours sur des billets. Un don,
 * puis un vote payant, passent par le meme webhook, le meme prestataire et la
 * meme contrainte d'idempotence — mais ne sont pas des commandes.
 *
 * La colonne devient donc polymorphe. Les lignes existantes sont reportees
 * telles quelles : un message recu hier doit rester rattache a sa commande,
 * sans quoi on perdrait la trace de ce qui a confirme quel paiement.
 *
 * CE QUI NE CHANGE PAS : l'unicite sur (provider, event_id), qui porte
 * l'idempotence (section 23). Elle ne depend pas de ce que le message paie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->string('payable_type')->nullable()->after('event_id');
            $table->unsignedBigInteger('payable_id')->nullable()->after('payable_type');
        });

        // Report des messages deja recus. Le nom de classe est ecrit en dur :
        // c'est ce que produit `getMorphClass()` en l'absence de table de
        // correspondance, et cette migration doit rester lisible dans dix ans
        // meme si la classe est renommee entre-temps.
        DB::table('payment_webhooks')
            ->whereNotNull('order_id')
            ->update([
                'payable_type' => 'App\\Models\\Order',
                'payable_id' => DB::raw('order_id'),
            ]);

        Schema::table('payment_webhooks', function (Blueprint $table) {
            // Supprime la cle etrangere et son index en meme temps que la
            // colonne.
            $table->dropConstrainedForeignId('order_id');

            $table->index(['payable_type', 'payable_id', 'created_at'], 'payment_webhooks_payable_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
        });

        DB::statement("UPDATE payment_webhooks SET order_id = payable_id WHERE payable_type = 'App\\Models\\Order'");

        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->dropIndex('payment_webhooks_payable_idx');
            $table->dropColumn(['payable_type', 'payable_id']);
            $table->index(['order_id', 'created_at']);
        });
    }
};
