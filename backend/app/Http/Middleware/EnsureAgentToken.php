<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un VRAI jeton d'appareil sur l'API agent (section 27.3).
 *
 * Sanctum, faute de jeton, retombe sur la session web : l'utilisateur connecte
 * au dashboard est alors authentifie sur ces routes, et son jeton transitoire
 * repond « oui » a TOUTES les abilites — y compris `agent`. L'API agent
 * deviendrait joignable depuis un navigateur connecte, avec les droits d'un
 * agent sur les evenements de son organisation.
 *
 * Ce middleware ferme ce chemin : seul un jeton personnel emis pour un
 * appareil passe. Un organisateur qui tient lui-meme la porte utilise
 * l'application et se connecte normalement — il obtient un vrai jeton.
 */
class EnsureAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        abort_unless($token instanceof PersonalAccessToken, 401);

        return $next($request);
    }
}
