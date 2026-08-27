<?php

namespace App\Providers;

use App\Http\Middleware\EnsureOrganizationContext;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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

        /*
         * LE CONTEXTE D'ORGANISATION SURVIT AUX REQUETES LIVEWIRE.
         *
         * Un clic sur un ecran Livewire ne rejoue pas la route d'origine : il
         * poste sur `/livewire/update`, et Livewire n'y rejoue qu'une LISTE
         * BLANCHE de middlewares — l'authentification, l'autorisation, et rien
         * d'autre par defaut.
         *
         * Sans cet enregistrement, la requete arrive donc sans organisation
         * courante. La portee globale se ferme (elle est fermee par defaut,
         * section 9), la rehydratation d'une propriete `public Event $event` ne
         * retrouve plus son modele, et le clic repond 404 — alors que la page
         * elle-meme s'etait affichee sans probleme.
         *
         * C'est ce qui rendait « Ajouter une porte » et « Ajouter un agent »
         * inutilisables. Le defaut ne pouvait pas apparaitre dans les tests :
         * `Livewire::test()` n'exerce aucun middleware et pose le contexte
         * lui-meme.
         */
        Livewire::addPersistentMiddleware([
            EnsureOrganizationContext::class,
        ]);
    }
}
