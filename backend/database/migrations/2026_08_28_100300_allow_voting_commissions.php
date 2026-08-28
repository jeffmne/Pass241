<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ouvre la commission aux votes payants (phase 9).
 *
 * `commissions.type` n'acceptait que `TICKETING` et `DONATION` : la contrainte
 * avait ete ecrite quand ces deux modules etaient les seuls prevus. Le reste de
 * la chaine — colonne polymorphe `commissionable_type`, calcul au taux fige,
 * ajustement au remboursement — n'a besoin de rien d'autre.
 *
 * Le taux des votes est de 5 %, comme la billetterie : un vote payant est une
 * vente de service a un public, pas un don a une cause. Il vit dans
 * `config/commission.php`, reglable ensuite sans deploiement par le Super Admin
 * (`PlatformSettings`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE commissions DROP CONSTRAINT IF EXISTS commissions_type_check');

        DB::statement('ALTER TABLE commissions ADD CONSTRAINT commissions_type_check CHECK (
            type IN (\'TICKETING\', \'DONATION\', \'VOTING\')
        )');
    }

    public function down(): void
    {
        /*
         * Le retour arriere efface d'abord les commissions de votes.
         *
         * Sans cela, la contrainte restreinte serait refusee par PostgreSQL des
         * qu'un vote payant aurait ete encaisse, et la migration echouerait au
         * pire moment — pendant un retour arriere, donc pendant un incident.
         */
        DB::statement('DELETE FROM commissions WHERE type = \'VOTING\'');

        DB::statement('ALTER TABLE commissions DROP CONSTRAINT IF EXISTS commissions_type_check');

        DB::statement('ALTER TABLE commissions ADD CONSTRAINT commissions_type_check CHECK (
            type IN (\'TICKETING\', \'DONATION\')
        )');
    }
};
