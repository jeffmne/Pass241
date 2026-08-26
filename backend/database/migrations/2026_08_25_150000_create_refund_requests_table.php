<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes de remboursement (section 24.1, decision D-027).
 *
 * Modele hybride : le client DEMANDE, l'organisateur DECIDE. Le client ne
 * declenche jamais un remboursement lui-meme.
 *
 * Cette table porte le PROCESSUS DE DECISION, distinct du statut du billet.
 * Sans elle, un billet refuse et un billet jamais demande se ressembleraient —
 * or ils n'ont rien a voir du point de vue du client, ni pour arbitrer un
 * litige.
 *
 * Le montant demande est fige a la creation. Il ne se recalcule jamais : le
 * prix a pu changer entre l'achat et la demande, et c'est ce qui a ete paye
 * qui doit etre rendu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Nul pour une demande portant sur toute la commande.
            $table->foreignId('ticket_id')->nullable()->constrained()->cascadeOnDelete();

            // Reference lisible, citee au support par le client comme par
            // l'organisateur.
            $table->string('reference')->unique();

            $table->string('status')->default('REQUESTED');

            // Montant en entiers XAF (decision D-003), fige a la demande.
            $table->bigInteger('amount');

            /*
             * Frais du prestataire, rappeles pour memoire.
             *
             * Non rembourses quand c'est l'acheteur qui demande — ils ont ete
             * payes a l'operateur, qui ne les rend pas (decision D-023). La
             * colonne existe pour que le client puisse verifier l'ecart entre
             * ce qu'il a paye et ce qu'il recoit.
             */
            $table->bigInteger('psp_fee_amount')->default(0);

            // Motif du client, puis motif de la decision. Deux champs
            // distincts : melanger la demande et la reponse rendrait tout
            // arbitrage impossible.
            $table->text('reason')->nullable();
            $table->text('decision_reason')->nullable();

            // Qui a decide. Nul pour un refus automatique hors delai, ce qui
            // est en soi une information.
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            // Trace du remboursement reellement execute chez le prestataire.
            $table->string('provider_reference')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['order_id', 'status']);
        });

        DB::statement("ALTER TABLE refund_requests ADD CONSTRAINT refund_requests_status_check CHECK (
            status IN ('REQUESTED', 'APPROVED', 'REJECTED', 'EXPIRED')
        )");

        DB::statement('ALTER TABLE refund_requests ADD CONSTRAINT refund_requests_amount_check CHECK (
            amount > 0 AND psp_fee_amount >= 0
        )');

        /*
         * Une seule demande EN COURS par billet.
         *
         * Index partiel plutot que contrainte simple : un client dont la
         * demande a ete refusee doit pouvoir en soumettre une nouvelle si sa
         * situation change, mais il ne doit pas pouvoir en empiler dix en
         * attente pour forcer la main a l'organisateur.
         */
        DB::statement("CREATE UNIQUE INDEX refund_requests_one_pending_per_ticket
            ON refund_requests (ticket_id)
            WHERE status = 'REQUESTED' AND ticket_id IS NOT NULL");

        DB::statement("CREATE UNIQUE INDEX refund_requests_one_pending_per_order
            ON refund_requests (order_id)
            WHERE status = 'REQUESTED' AND ticket_id IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};
