<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');

            /*
             * Organisation active de l'utilisateur. Section 9 : le backend
             * determine seul l'organisation courante. Cette colonne est la
             * source de verite cote serveur — un organizationId envoye par le
             * frontend n'est jamais pris en compte.
             */
            $table->foreignId('current_organization_id')
                ->nullable()
                ->after('phone')
                ->constrained('organizations')
                ->nullOnDelete();

            /*
             * Le Super Admin est un fait de plateforme, pas un role portee par
             * une organisation. Le garder hors du systeme de roles scopes evite
             * qu'une elevation de privilege dans une organisation puisse
             * accorder un acces global (section 58).
             */
            $table->boolean('is_super_admin')->default(false)->after('current_organization_id');

            $table->timestamp('last_login_at')->nullable();

            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_organization_id');
            $table->dropIndex(['phone']);
            $table->dropColumn(['phone', 'is_super_admin', 'last_login_at']);
        });
    }
};
