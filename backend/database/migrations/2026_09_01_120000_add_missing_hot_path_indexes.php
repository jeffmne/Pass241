<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index manquants sur les chemins chauds (section 72).
 *
 * POSTGRESQL N'INDEXE PAS LES COLONNES DE CLE ETRANGERE. Il cree un index sur
 * la colonne REFERENCEE — une cle primaire, deja indexee — et rien du tout sur
 * la colonne qui reference. Vingt-neuf colonnes du schema etaient dans ce cas ;
 * la plupart sans consequence, quelques-unes sur les requetes les plus
 * frequentes du produit.
 *
 * CE QUI EST AJOUTE ICI A ETE MESURE OU JUSTIFIE PAR UN CHEMIN D'APPEL REEL, pas
 * ajoute au cas ou. Un index se paie a chaque ecriture, et une table de billets
 * s'ecrit autant qu'elle se lit le soir d'un concert.
 *
 * CE QUI A ETE ECARTE, ET POURQUOI, est aussi important : voir la note en fin de
 * fichier sur la jointure de `StockLedger::sold()`, ou l'index n'est pas le bon
 * levier.
 *
 * Les index sont crees sans `CONCURRENTLY` : Laravel enveloppe chaque migration
 * dans une transaction sur PostgreSQL, ce que `CONCURRENTLY` interdit. Sur ces
 * volumes la prise de verrou est breve ; le jour ou ces tables seront lourdes en
 * production, une creation concurrente devra se faire hors migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * MESURE, ET LE PLUS COUTEUX DES QUATRE.
         *
         * `WHERE order_id = ?` faisait un parcours SEQUENTIEL de toute la table
         * des articles. Sur 200 000 lignes : 10,7 ms et 199 999 lignes
         * ecartees par le filtre, contre 2,5 ms avec l'index — et surtout un
         * cout qui cesse de croitre avec le nombre total de commandes de la
         * plateforme.
         *
         * Cette requete n'est pas rare : elle s'execute a l'ouverture de chaque
         * commande, a chaque confirmation de paiement (`guardPaymentConfirmation`
         * parcourt les articles), a chaque emission de billets et sur l'export
         * des commandes. C'est le chemin le plus emprunte du produit.
         */
        Schema::table('order_items', function (Blueprint $table) {
            $table->index('order_id');
        });

        /*
         * La portee multi-tenant filtre CHAQUE requete sur les billets
         * (`BelongsToOrganization`, section 9). L'index existant commence par
         * `event_id` : il sert quand on regarde un evenement, jamais quand on
         * agrege toute une organisation.
         *
         * Or c'est exactement ce que fait la vue generale de l'organisateur —
         * billets vendus, recettes, repartition par categorie. Sans cet index,
         * l'ouverture du tableau de bord d'un organisateur parcourt les billets
         * de TOUTE la plateforme pour n'en garder que les siens.
         */
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['organization_id', 'status']);
        });

        /*
         * Une invitation retrouve sa commande a l'acceptation et a l'annulation
         * (section 25).
         */
        Schema::table('invitations', function (Blueprint $table) {
            $table->index('order_id');
        });

        /*
         * INDEX PARTIEL, et c'est tout son interet.
         *
         * `payment_webhooks` est en ajout seul : elle ne cesse jamais de
         * grandir. L'ecran de supervision y cherche les callbacks non traites
         * (decision D-063) — quelques lignes au plus, et zero en marche
         * normale. Un index complet grossirait comme la table pour indexer une
         * information dont on ne consulte qu'une valeur ; celui-ci ne contient
         * que les lignes en souffrance, et reste donc minuscule a jamais.
         */
        DB::statement(
            'CREATE INDEX payment_webhooks_unprocessed_idx
             ON payment_webhooks (created_at)
             WHERE processed_at IS NULL'
        );

        /*
         * ------------------------------------------------------------------
         * CE QUI A ETE ECARTE
         * ------------------------------------------------------------------
         *
         * `StockLedger::sold()` joint les articles aux commandes pour ne
         * compter que celles qui sont payees. A 200 000 commandes, un index
         * couvrant `orders (id) INCLUDE (status)` faisait basculer le plan en
         * boucle imbriquee et gagnait un tiers du temps (33 ms a 22 ms).
         *
         * A 500 000 commandes, LE PLANIFICATEUR L'ABANDONNE : il repasse a une
         * jointure par fusion qui parcourt l'index en entier, et le gain se
         * reduit a presque rien. L'index n'est donc pas le levier — le cout du
         * parcours aleatoire dans le modele de couts de PostgreSQL l'est
         * (`random_page_cost`, encore regle pour des disques a plateaux).
         *
         * Livrer cet index aurait donne l'illusion d'avoir traite le sujet.
         * Il est consigne comme point ouvert plutot qu'a moitie corrige.
         */
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payment_webhooks_unprocessed_idx');

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
        });
    }
};
