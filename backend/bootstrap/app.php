<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureAgentToken;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ExpireInactiveSessions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '')),
        )));

        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        // Protection commune aux pages HTML, a l'API et aux reponses d'erreur.
        $middleware->append(AddSecurityHeaders::class);

        $middleware->alias([
            'organization' => EnsureOrganizationContext::class,
            'super-admin' => EnsureSuperAdmin::class,

            /*
             * Abilites Sanctum : Laravel ne les alias pas par defaut.
             *
             * L'API agent en depend entierement — un jeton emis pour un
             * telephone d'agent ne doit donner acces a rien d'autre. Sans cet
             * alias, la route echouerait a la resolution du middleware plutot
             * que de refuser proprement.
             */
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'agent-token' => EnsureAgentToken::class,
        ]);

        /*
         * LE CONTEXTE D'ORGANISATION AVANT LA LIAISON DE ROUTE.
         *
         * Sans cette priorite, `SubstituteBindings` resout `{event}` AVANT que
         * `EnsureOrganizationContext` n'ait pose l'organisation courante. La
         * requete part alors sans contexte, la portee globale se ferme — elle
         * est fermee par defaut, et c'est voulu (section 9) — et la liaison ne
         * trouve rien : tous les ecrans rattaches a un evenement ou a une
         * collecte repondent 404.
         *
         * Le defaut ne se voyait pas dans les tests : ceux-ci posent le
         * contexte a la main avant d'appeler, ce qu'une vraie requete ne fait
         * jamais. Voir `tests/Feature/Tenancy/RouteBindingTest.php`, ecrit
         * exactement sans ce raccourci.
         */
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureOrganizationContext::class,
        );

        /*
         * Coupure apres inactivite (section 66).
         *
         * Sur le groupe `web` uniquement : l'API agent s'authentifie par jeton
         * Sanctum, sans session, et un agent au controle des entrees ne doit
         * surtout pas etre deconnecte parce qu'il n'a rien scanne pendant une
         * demi-heure — c'est le creux entre deux vagues d'arrivees.
         */
        $middleware->web(append: [
            ExpireInactiveSessions::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
