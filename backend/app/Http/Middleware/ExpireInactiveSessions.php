<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deconnexion apres une periode d'inactivite (section 66).
 *
 * POURQUOI CE MIDDLEWARE EXISTE ALORS QUE LARAVEL EXPIRE DEJA LES SESSIONS.
 *
 * `SESSION_LIFETIME` est un plafond cote serveur : la session disparait du
 * magasin au bout de deux heures. Trois choses lui manquent, et ce sont
 * exactement les trois qui comptent pour un produit qui manipule de l'argent.
 *
 * 1. ELLE NE DIT RIEN. L'utilisateur revient, la page se recharge, et il se
 *    retrouve sur l'ecran de connexion sans savoir pourquoi. Il croit a une
 *    panne, ou pire, a une deconnexion subie.
 *
 * 2. ELLE NE SURVIT PAS AU « SE SOUVENIR DE MOI ». Le cookie de rappel
 *    reconnecte silencieusement des la requete suivante : sans ce middleware,
 *    l'inactivite ne deconnecte donc RIEN pour un utilisateur qui a coche la
 *    case. `Auth::logout()` invalide le jeton de rappel en base — le simple
 *    fait que la session expire, non.
 *
 * 3. ELLE NE SE REGLE PAS SEPAREMENT. Le plafond de session et le delai
 *    d'inactivite repondent a deux questions differentes : « combien de temps
 *    une session peut-elle vivre au maximum » et « au bout de combien de temps
 *    sans rien faire coupe-t-on ». Les confondre oblige a choisir la plus
 *    courte des deux valeurs pour les deux usages.
 *
 * Le delai vit dans `config/auth.php`, sous `idle_timeout`.
 */
class ExpireInactiveSessions
{
    /** Cle de session portant l'horodatage de la derniere requete. */
    private const KEY = 'auth.last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * L'API agent est hors sujet : elle s'authentifie par jeton Sanctum,
         * sans session (section 27). Un agent au controle des entrees ne doit
         * surtout pas etre deconnecte parce qu'il n'a rien scanne pendant
         * trente minutes — c'est le creux entre deux vagues d'arrivees.
         */
        if (! $request->hasSession() || ! Auth::check()) {
            return $next($request);
        }

        $timeout = (int) config('auth.idle_timeout');

        // Zero ou negatif : la coupure est desactivee. Utile en developpement,
        // et il vaut mieux que ce soit un reglage explicite qu'un middleware
        // qu'on retire du groupe et qu'on oublie de remettre.
        if ($timeout <= 0) {
            return $next($request);
        }

        $last = $request->session()->get(self::KEY);

        if ($last !== null && (now()->getTimestamp() - (int) $last) > $timeout * 60) {
            return $this->disconnect($request, $timeout);
        }

        $request->session()->put(self::KEY, now()->getTimestamp());

        return $next($request);
    }

    /**
     * Coupe la session, et le dit.
     *
     * L'ordre des trois gestes n'est pas indifferent :
     *
     * - `Auth::logout()` d'abord, parce que lui seul fait tourner le jeton de
     *   rappel en base. Sans lui, le cookie « se souvenir de moi » rouvrirait
     *   la porte a la requete suivante et toute cette classe ne servirait a
     *   rien ;
     * - `invalidate()` ensuite, qui jette le contenu de la session et lui donne
     *   un identifiant neuf — c'est ce qui empeche la fixation de session ;
     * - `regenerateToken()` enfin, faute de quoi le formulaire de connexion
     *   qu'on s'apprete a afficher porterait un jeton CSRF perime, et la
     *   premiere tentative de reconnexion echouerait en 419.
     *
     * Le message part APRES l'invalidation : flashe avant, il serait emporte
     * avec le reste.
     */
    private function disconnect(Request $request, int $timeout): Response
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = sprintf(
            'Vous avez ete deconnecte apres %d minutes sans activite. Reconnectez-vous pour continuer.',
            $timeout,
        );

        /*
         * Livewire ne sait pas suivre une redirection renvoyee au milieu d'une
         * requete de composant : il recevrait du HTML la ou il attend du JSON,
         * et l'ecran resterait fige sur un etat qui n'existe plus cote serveur.
         *
         * Un 419 est le code qu'il sait deja traiter — c'est celui d'une page
         * expiree, ce qui est exactement le cas. Il recharge la page, la
         * redirection normale l'emmene a la connexion, et le message y est
         * toujours puisqu'il a ete flashe dans la nouvelle session.
         */
        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
            $request->session()->flash('notice', $message);

            return response('Session expiree.', 419);
        }

        return redirect()->route('login')->with('notice', $message);
    }
}
