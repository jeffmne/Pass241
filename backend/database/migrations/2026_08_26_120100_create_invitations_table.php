<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations (section 25).
 *
 * Une invitation est une PLACE MISE DE COTE pour quelqu'un, pas encore un
 * billet. Elle devient un billet quand l'invite l'accepte — c'est la difference
 * entre les statuts ACCEPTED et USED de la section 25, et c'est ce qui permet
 * d'inviter cinquante personnes sans emettre cinquante billets dont la moitie
 * ne viendra pas.
 *
 * CHAQUE INVITATION PORTE SA COMMANDE, a zero franc (decision D-039). Ce n'est
 * pas une coquetterie comptable : la commande est ce qui retient le stock
 * (decision D-017 — « reserve » = commande en attente non expiree), et ce qui
 * donne ensuite le billet, le QR signe, le PDF et le controle a l'entree sans
 * qu'une seule ligne de ces modules ait a connaitre l'existence des
 * invitations.
 *
 * L'expiration de l'invitation EST celle de sa commande : une invitation qui
 * expire rend sa place au public a la seconde ou elle expire, sans qu'aucun
 * traitement n'ait besoin de s'executer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // La commande qui retient la place, et qui portera le billet.
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            /*
             * Jeton du lien d'invitation.
             *
             * C'est lui qu'on envoie par email ou qu'on colle dans un message :
             * il doit etre impossible a deviner, donc long et aleatoire. Il ne
             * remplace pas le jeton du billet (section 18), qui n'existera
             * qu'apres acceptation.
             */
            $table->string('token', 64)->unique();

            $table->string('guest_name');
            $table->string('guest_email')->nullable();

            $table->string('status')->default('CREATED');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            /*
             * Duplique de `orders.expires_at`, et volontairement.
             *
             * La commande est le mecanisme, l'invitation est ce que
             * l'organisateur lit. Garder la date ici permet de l'afficher et de
             * la trier sans jointure, et surtout de la conserver telle qu'elle
             * a ete promise si la commande venait a etre annulee.
             */
            $table->timestamp('expires_at');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['event_id', 'status']);
        });

        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_status_check CHECK (
            status IN ('CREATED', 'SENT', 'ACCEPTED', 'EXPIRED', 'CANCELLED')
        )");

        /*
         * Un statut et sa date vont ensemble, dans les deux sens.
         *
         * Une invitation « acceptee » sans date d'acceptation ne permettrait
         * pas de dire quand la place a ete prise ; une date sans le statut
         * laisserait croire l'inverse. La base refuse les deux.
         */
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_accepted_check CHECK (
            (status = 'ACCEPTED') = (accepted_at IS NOT NULL)
        )");

        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_cancelled_check CHECK (
            (status = 'CANCELLED') = (cancelled_at IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
