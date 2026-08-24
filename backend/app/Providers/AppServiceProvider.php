<?php

namespace App\Providers;

use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Une seule instance par requete : la portee globale, les modeles et
        // les services lisent tous le meme contexte d'organisation (section 9).
        $this->app->scoped(OrganizationContext::class);
    }

    public function boot(): void
    {
        /*
         * Interdit l'acces a une relation non chargee. Sur un produit ou une
         * page de dashboard boucle sur des commandes, des billets et des
         * participants, une requete N+1 passee inapercue en developpement
         * devient un incident en production le soir d'un concert (section 72).
         */
        Model::preventLazyLoading(! $this->app->isProduction());

        /*
         * Interdit l'affectation silencieuse d'un attribut inexistant. Sur les
         * modeles financiers, une faute de frappe dans un nom de colonne ne
         * doit jamais etre ignoree en silence.
         */
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Date::use(Carbon::class);
    }
}
