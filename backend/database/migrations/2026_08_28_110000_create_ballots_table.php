<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulletins (sections 86.4, 86.5).
 *
 * `Ballot` et non `Vote` : en francais, « vote » designe a la fois le scrutin et
 * le bulletin. Le scrutin est un `Poll`, le bulletin un `ballot`.
 *
 * UN BULLETIN N'EST PAS UNE LIGNE COMME LES AUTRES. Une fois cree, le candidat
 * choisi, la quantite et le montant ne doivent plus jamais changer — sinon
 * l'organisateur pourrait, en base, reattribuer des voix d'un candidat a un
 * autre. Un declencheur PostgreSQL l'interdit (voir plus bas), et il tient aussi
 * contre une commande artisan ou une correction manuelle.
 *
 * L'AJOUT SEUL PUR NE CONVIENT PAS ICI, contrairement aux commissions : un
 * bulletin payant doit pouvoir passer de PENDING a CONFIRMED quand le
 * prestataire confirme. Seuls le statut et sa date peuvent donc bouger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ballots', function (Blueprint $table) {
            $table->id();

            /*
             * `organization_id` figure ICI alors que le scrutin le porte deja.
             *
             * Ce n'est pas une duplication de confort : c'est ce qui permet a
             * `BelongsToOrganization` de filtrer les bulletins sans jointure, et
             * surtout ce qui permet a la couche paiement — qui travaille sans
             * contexte, depuis un worker de file d'attente — de retrouver
             * l'organisation a crediter en une seule lecture.
             *
             * Le meme choix a ete fait pour les dons.
             */
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('poll_category_id')->constrained()->cascadeOnDelete();

            /*
             * `restrictOnDelete` et non `cascade` : supprimer un candidat
             * emporterait ses bulletins, donc des voix deja exprimees et, pour
             * les votes payants, de l'argent deja encaisse. La base refuse la
             * suppression — l'application, elle, propose la desactivation.
             */
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();

            /** Reference lisible, portee par les webhooks : VT-4KX9-27B. */
            $table->string('reference')->unique();

            $table->unsignedInteger('quantity');

            /*
             * Prix unitaire et montant FIGES au moment du vote (decision D-003,
             * entiers XAF). Recopies depuis la categorie plutot que lus par
             * jointure : le prix d'une categorie peut changer avant l'ouverture,
             * et un bulletin doit garder le prix auquel il a reellement ete
             * paye. C'est le meme raisonnement que le taux de commission.
             */
            $table->bigInteger('unit_price');
            $table->bigInteger('amount');

            $table->string('status')->default('PENDING');

            /*
             * Empreinte du votant (section 86.5).
             *
             * JAMAIS le numero en clair : la page est publique, et la liste des
             * numeros ayant vote pour tel candidat serait une donnee personnelle
             * exposee. Le hachage sert uniquement a compter — il permet de dire
             * « ce votant a deja utilise ses trois voix », pas de dire qui il
             * est.
             */
            $table->string('voter_hash', 64);

            // Renseigne pour un vote payant : c'est le numero vers lequel la
            // demande de paiement est poussee.
            $table->string('payer_phone')->nullable();

            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();

            /*
             * Traces techniques, pour l'analyse d'abus.
             *
             * L'agent utilisateur est hache et non stocke en clair : sa valeur
             * brute est un identifiant de navigateur assez discriminant pour
             * pister quelqu'un, et rien dans le produit n'en a besoin en clair.
             */
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent_hash', 64)->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            // Le comptage des voix par candidat est LA requete du module : elle
            // sert le classement public, le tableau de bord et le rapport final.
            $table->index(['candidate_id', 'status']);
            $table->index(['poll_category_id', 'status']);
            $table->index(['poll_id', 'status']);
            $table->index(['organization_id', 'status']);
            $table->index(['poll_category_id', 'voter_hash']);
        });

        DB::statement('ALTER TABLE ballots ADD CONSTRAINT ballots_status_check CHECK (
            status IN (\'PENDING\', \'CONFIRMED\', \'FAILED\', \'REFUNDED\')
        )');

        DB::statement('ALTER TABLE ballots ADD CONSTRAINT ballots_quantity_check CHECK (
            quantity >= 1
        )');

        /*
         * Le montant est le produit de la quantite par le prix unitaire.
         *
         * Verifie en base parce que c'est la seule facon de garantir qu'aucun
         * chemin — action, commande, correction manuelle — ne puisse enregistrer
         * un bulletin dont le montant ne correspond pas a ce qui a ete vote.
         */
        DB::statement('ALTER TABLE ballots ADD CONSTRAINT ballots_amount_check CHECK (
            amount = quantity * unit_price
        )');

        DB::statement('ALTER TABLE ballots ADD CONSTRAINT ballots_prices_check CHECK (
            unit_price >= 0 AND amount >= 0
        )');

        /*
         * Un statut et sa date vont ensemble, dans les deux sens — meme regle
         * que les scrutins et les collectes.
         *
         * REFUNDED COMPTE PARMI LES STATUTS DATES, et ce n'est pas un
         * assouplissement : un bulletin rembourse A ETE confirme, sinon il n'y
         * aurait rien eu a rembourser. Sa date de confirmation reste vraie.
         *
         * Ecrite `(status = 'CONFIRMED')`, la contrainte rendait REFUNDED
         * litteralement inatteignable : passer un bulletin confirme au
         * remboursement violait la regle, et l'effacer d'abord etait interdit
         * par le declencheur. Le remboursement d'un vote paye — attendu au
         * jalon 9.5 — n'aurait eu aucun chemin possible.
         *
         * C'est coherent avec le comptage : `VotingStatistics` retient les voix
         * des bulletins CONFIRMED et REFUNDED, et ne compte le revenu que des
         * CONFIRMED. Un remboursement rend l'argent, pas la voix.
         */
        DB::statement('ALTER TABLE ballots ADD CONSTRAINT ballots_confirmed_check CHECK (
            (status IN (\'CONFIRMED\', \'REFUNDED\')) = (confirmed_at IS NOT NULL)
        )');

        /*
         * -------------------------------------------------------------------
         * L'IMMUABILITE, TENUE PAR LA BASE
         * -------------------------------------------------------------------
         *
         * Section 86.4 : « une fois un vote valide, l'organisateur ne peut ni le
         * supprimer, ni le modifier, ni changer le nombre de votes, ni changer
         * retroactivement le candidat choisi ».
         *
         * Ecrite dans une action PHP, cette regle ne vaudrait que pour le code
         * qui passe par l'action. Ecrite ici, elle vaut aussi pour un
         * `Ballot::query()->update()` oublie dans une commande, pour un tinker,
         * et pour quelqu'un qui ouvre psql. C'est la difference entre une regle
         * interdite et une regle impossible.
         *
         * Le statut et ses dates restent libres : un bulletin payant doit
         * pouvoir passer de PENDING a CONFIRMED, puis a REFUNDED.
         */
        DB::statement('
            CREATE OR REPLACE FUNCTION ballots_refuse_rewrite() RETURNS trigger AS $$
            BEGIN
                IF NEW.candidate_id IS DISTINCT FROM OLD.candidate_id
                    OR NEW.poll_category_id IS DISTINCT FROM OLD.poll_category_id
                    OR NEW.poll_id IS DISTINCT FROM OLD.poll_id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.quantity IS DISTINCT FROM OLD.quantity
                    OR NEW.unit_price IS DISTINCT FROM OLD.unit_price
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.reference IS DISTINCT FROM OLD.reference
                    OR NEW.voter_hash IS DISTINCT FROM OLD.voter_hash
                THEN
                    RAISE EXCEPTION
                        \'Un bulletin ne se reecrit pas : candidat, quantite et montant sont definitifs.\';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ');

        DB::statement('
            CREATE TRIGGER ballots_refuse_rewrite_trigger
            BEFORE UPDATE ON ballots
            FOR EACH ROW EXECUTE FUNCTION ballots_refuse_rewrite();
        ');

        /*
         * Un bulletin CONFIRME ne se supprime pas non plus.
         *
         * Le declencheur ci-dessus ne couvre que les modifications. Sans
         * celui-ci, l'effacement resterait la faille evidente : supprimer les
         * bulletins d'un candidat reviendrait exactement a lui retirer ses voix.
         *
         * Un bulletin en attente ou echoue reste supprimable : il n'a jamais
         * compte, et le menage des paniers abandonnes doit rester possible.
         */
        DB::statement('
            CREATE OR REPLACE FUNCTION ballots_refuse_delete() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN (\'CONFIRMED\', \'REFUNDED\') THEN
                    RAISE EXCEPTION
                        \'Un bulletin confirme ne se supprime pas : les voix exprimees sont definitives.\';
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
        ');

        DB::statement('
            CREATE TRIGGER ballots_refuse_delete_trigger
            BEFORE DELETE ON ballots
            FOR EACH ROW EXECUTE FUNCTION ballots_refuse_delete();
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS ballots_refuse_delete_trigger ON ballots');
        DB::statement('DROP TRIGGER IF EXISTS ballots_refuse_rewrite_trigger ON ballots');
        DB::statement('DROP FUNCTION IF EXISTS ballots_refuse_delete()');
        DB::statement('DROP FUNCTION IF EXISTS ballots_refuse_rewrite()');

        Schema::dropIfExists('ballots');
    }
};
