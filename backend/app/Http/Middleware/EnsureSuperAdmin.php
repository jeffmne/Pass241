<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reserve une route au Super Admin de la plateforme (section 58).
 *
 * Volontairement distinct du systeme de roles Spatie, qui est SCOPE PAR
 * ORGANISATION : un role s'evalue dans le contexte d'une organisation, alors
 * que le Super Admin n'appartient a aucune et les voit toutes. Faire passer
 * cet acces par un role obligerait a lui inventer une organisation, et
 * ouvrirait la porte a ce qu'un organisateur se l'attribue chez lui.
 *
 * Repond 404 et non 403 : l'existence meme de la console d'administration n'a
 * pas a etre confirmee a qui n'y a pas acces.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isSuperAdmin(), 404);

        return $next($request);
    }
}
