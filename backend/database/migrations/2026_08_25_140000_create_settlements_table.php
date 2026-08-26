<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reversements vers l'organisateur (section 21.2, decision D-026).
 *
 * Une ligne par EVENEMENT, pas par commande : un virement unique coute moins
 * cher en frais fixes qu'une centaine de petits, et se lit sans effort sur le
 * relevé de l'organisateur.
 *
 * Le statut evolue — un virement est instruit, puis programme, puis arrive —
 * mais les MONTANTS et le BENEFICIAIRE sont figes des la creation, et un
 * declencheur PostgreSQL le fait respecter. C'est la difference avec la table
 * des commissions, immuable de bout en bout : ici le cycle de vie est reel,
 * seule la partie financiere ne doit jamais bouger.
 *
 * Le beneficiaire est un INSTANTANE, jamais une jointure vers l'organisation.
 * Si quelqu'un change le numero de reversement le lendemain, ce reversement-ci
 * doit continuer a dire vers quel numero l'argent est parti — c'est
 * exactement la trace qu'on cherche quand on soupconne un detournement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();

            // `restrictOnDelete` et non `cascadeOnDelete`, contrairement au
            // reste du schema : supprimer une organisation ou un evenement
            // effacerait la trace d'un virement reellement parti. La base
            // refuse, et c'est le comportement voulu.
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            // Un seul reversement par evenement : une nouvelle tentative
            // reutilise cette ligne au lieu d'en creer une seconde. C'est ce
            // qui empeche de payer deux fois apres un echec transitoire.
            $table->foreignId('event_id')->constrained()->restrictOnDelete()->unique();

            // Reference lisible, communiquee a l'organisateur et citee au support.
            $table->string('reference')->unique();

            // Montants en entiers XAF (decision D-003), figes a la creation.
            $table->bigInteger('gross_amount');
            $table->bigInteger('commission_amount');
            $table->bigInteger('net_amount');
            $table->string('currency', 3)->default('XAF');

            // Beneficiaire au moment du reversement, jamais relu depuis
            // l'organisation.
            $table->string('recipient_phone');
            $table->string('recipient_operator');
            $table->string('recipient_name')->nullable();

            $table->string('status')->default('PENDING');

            // Prestataire ayant execute le virement, et sa reference de
            // transaction : sans elles, aucune reconciliation n'est possible
            // le jour d'un changement d'agregateur.
            $table->string('psp')->nullable();
            $table->string('provider_reference')->nullable();

            /*
             * Cle d'idempotence transmise au prestataire.
             *
             * Unique en base : c'est la derniere barriere si deux workers
             * traitent le meme evenement en meme temps. Un ordre de virement
             * rejoue envoie l'argent deux fois, et une somme envoyee en double
             * ne se recupere pas.
             */
            $table->string('idempotency_key')->unique();

            // Date a laquelle les fonds doivent etre liberes : apres
            // l'evenement, une fois la fenetre de remboursement fermee.
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            // Le job de reconciliation balaie les reversements non aboutis :
            // cet index evite un parcours complet de la table.
            $table->index(['status', 'scheduled_for']);
        });

        DB::statement("ALTER TABLE settlements ADD CONSTRAINT settlements_status_check CHECK (
            status IN ('PENDING', 'SCHEDULED', 'SUCCEEDED', 'FAILED', 'UNKNOWN')
        )");

        DB::statement("ALTER TABLE settlements ADD CONSTRAINT settlements_operator_check CHECK (
            recipient_operator IN ('AIRTEL', 'MOOV')
        )");

        DB::statement('ALTER TABLE settlements ADD CONSTRAINT settlements_amounts_check CHECK (
            gross_amount >= 0 AND commission_amount >= 0 AND net_amount > 0
        )');

        // La somme doit tomber juste, toujours. Un reversement qui ne se
        // reconcilie pas avec son brut est une erreur comptable qui passerait
        // inapercue dans un rapport.
        DB::statement('ALTER TABLE settlements ADD CONSTRAINT settlements_split_check CHECK (
            gross_amount = commission_amount + net_amount
        )');

        /*
         * Le statut peut changer ; l'argent et le beneficiaire, jamais.
         *
         * Sans ce declencheur, une correction « rapide » en base pourrait
         * rediriger un virement ou en modifier le montant sans laisser la
         * moindre trace. Le journal d'audit couvre les transitions d'etat ;
         * ceci couvre ce qu'aucun journal ne rattraperait.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION settlements_freeze_money()
            RETURNS TRIGGER AS $$
            BEGIN
                IF NEW.gross_amount IS DISTINCT FROM OLD.gross_amount
                    OR NEW.commission_amount IS DISTINCT FROM OLD.commission_amount
                    OR NEW.net_amount IS DISTINCT FROM OLD.net_amount
                    OR NEW.recipient_phone IS DISTINCT FROM OLD.recipient_phone
                    OR NEW.recipient_operator IS DISTINCT FROM OLD.recipient_operator
                    OR NEW.event_id IS DISTINCT FROM OLD.event_id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                THEN
                    RAISE EXCEPTION 'Le montant et le beneficiaire d''un reversement sont figes.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER settlements_money_is_frozen
                BEFORE UPDATE ON settlements
                FOR EACH ROW EXECUTE FUNCTION settlements_freeze_money();

            CREATE OR REPLACE FUNCTION settlements_no_delete()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Un reversement ne se supprime pas.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER settlements_never_deleted
                BEFORE DELETE ON settlements
                FOR EACH ROW EXECUTE FUNCTION settlements_no_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS settlements_never_deleted ON settlements');
        DB::unprepared('DROP TRIGGER IF EXISTS settlements_money_is_frozen ON settlements');
        DB::unprepared('DROP FUNCTION IF EXISTS settlements_no_delete()');
        DB::unprepared('DROP FUNCTION IF EXISTS settlements_freeze_money()');

        Schema::dropIfExists('settlements');
    }
};
