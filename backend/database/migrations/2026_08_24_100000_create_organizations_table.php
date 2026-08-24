<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'organisation est l'unite d'isolation du SaaS (section 9). Toute donnee
 * metier lui appartient, et aucune organisation ne doit jamais voir les
 * donnees d'une autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type');

            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            // Section 10.1 : l'inscription et la vente restent libres, seul le
            // reversement est bloque tant que le KYC n'est pas verifie.
            $table->string('kyc_status')->default('PENDING');
            $table->timestamp('kyc_submitted_at')->nullable();
            $table->timestamp('kyc_verified_at')->nullable();
            $table->text('kyc_rejection_reason')->nullable();

            // Section 58 : le Super Admin peut suspendre une organisation.
            $table->timestamp('suspended_at')->nullable();

            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index('kyc_status');
        });

        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_type_check
            CHECK (type IN ('INDIVIDUAL', 'COMPANY', 'ASSOCIATION'))");

        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_kyc_status_check
            CHECK (kyc_status IN ('PENDING', 'SUBMITTED', 'VERIFIED', 'REJECTED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
