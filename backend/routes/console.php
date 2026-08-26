<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Taches planifiees
|--------------------------------------------------------------------------
|
| Toutes les heures sont exprimees dans le fuseau d'affichage du produit
| (Africa/Libreville), et non en UTC : « minuit » doit vouloir dire minuit
| pour un organisateur gabonais, pas 1h du matin (decision D-007).
|
*/

$timezone = config('app.display_timezone');

/*
 * Reversement du net des evenements termines (section 21.2, decision D-026).
 *
 * Une fois par heure et non une fois par jour : un evenement se termine a
 * n'importe quelle heure, et le delai de reversement se compte en heures
 * depuis sa fin. Un balayage quotidien ferait attendre jusqu'a vingt-trois
 * heures de plus, sans raison.
 *
 * `withoutOverlapping` est indispensable : deux executions simultanees
 * tenteraient de reverser les memes evenements. L'unicite en base les
 * empecherait de payer deux fois, mais autant ne pas s'appuyer sur le dernier
 * rempart.
 */
Schedule::command('pass241:settle-events')
    ->hourly()
    ->timezone($timezone)
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Reconciliation avec le prestataire.
 *
 * Deux fois par jour. C'est la seule tache autorisee a conclure qu'un
 * virement a echoue : nulle part ailleurs on ne transforme un etat inconnu en
 * echec, ce qui reviendrait a autoriser une nouvelle tentative sur un ordre
 * peut-etre deja parti.
 */
/*
 * Fermeture des demandes de remboursement restees sans reponse (section 24.1).
 *
 * AVANT le reversement dans l'ordre de la journee : une demande en suspens
 * bloquerait l'argent de l'organisateur sans que personne ait rien decide.
 */
Schedule::command('pass241:expire-refund-requests')
    ->hourly()
    ->timezone($timezone)
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('pass241:reconcile-settlements')
    ->twiceDaily(7, 19)
    ->timezone($timezone)
    ->withoutOverlapping()
    ->runInBackground();
