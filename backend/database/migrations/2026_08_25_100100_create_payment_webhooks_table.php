<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des webhooks de paiement (sections 22, 23).
 *
 * Cette table EST le mecanisme d'idempotence. La contrainte d'unicite sur
 * (provider, event_id) fait porter la garantie a PostgreSQL plutot qu'a du
 * code applicatif : deux livraisons simultanees du meme message ne peuvent pas
 * toutes deux passer un `exists()` puis inserer.
 *
 * Tous les messages sont conserves, y compris ceux dont la signature est
 * invalide : c'est ce qui permet de comprendre apres coup pourquoi un paiement
 * n'a pas ete confirme, ou de constater une tentative de fraude.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();

            $table->string('provider');

            // Identifiant du message chez l'operateur. Nullable : un message
            // malforme n'en porte pas, et doit malgre tout etre conserve.
            $table->string('event_id')->nullable();

            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('signature_valid')->default(false);
            $table->jsonb('payload')->nullable();

            // Renseigne quand le message a reellement produit un effet. Un
            // doublon reste a null : il a ete recu, jamais rejoue.
            $table->timestamp('processed_at')->nullable();
            $table->text('outcome')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['provider', 'event_id']);
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
    }
};
