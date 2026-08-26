<?php

use App\Http\Middleware\EnsureAgentToken;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
