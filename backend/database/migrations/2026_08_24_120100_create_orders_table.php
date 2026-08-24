<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes (section 20).
 *
 * Une commande nait en PENDING avec une date d'expiration, et ne reste jamais
 * dans cet etat : un job la fait basculer en FAILED passe le delai de la
 * section 21.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // Reference lisible, communiquee a l'acheteur et citee au support.
            $table->string('reference')->unique();

            // Section 16 : nom, prenom, email facultatif. L'acheteur n'a pas de
            // compte (section 15), ces champs sont donc portes par la commande.
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();

            // Sections 16.1, 52 : donnees de paiement, privees.
            $table->string('payment_provider');
            $table->string('payer_phone');

            $table->string('status')->default('PENDING');

            // Montant total en entiers XAF (decision D-003).
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('XAF');

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['event_id', 'status']);
            // Le job d'expiration balaie les commandes en attente arrivees a
            // echeance : cet index evite un parcours complet de la table.
            $table->index(['status', 'expires_at']);
            // Section 15.1 : l'acheteur retrouve ses billets par son numero,
            // meme sans adresse email.
            $table->index('payer_phone');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (
            status IN ('PENDING', 'PAID', 'FAILED', 'CANCELLED', 'REFUNDED', 'PARTIALLY_REFUNDED')
        )");

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_provider_check CHECK (
            payment_provider IN ('AIRTEL', 'MOOV')
        )");

        // Un montant negatif n'a aucun sens metier et signalerait un calcul
        // errone : la base refuse de l'enregistrer.
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_check CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
