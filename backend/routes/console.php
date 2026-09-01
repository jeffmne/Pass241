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

/*
 * Fermeture des invitations restees sans reponse (section 25).
 *
 * Toutes les heures, comme les autres fermetures. La place, elle, est deja
 * revenue au public a l'echeance (decision D-017) : cette tache met les mots
 * justes sur un fait acquis, elle ne libere rien. Si elle prend du retard,
 * aucune place n'est perdue ni vendue deux fois.
 */
Schedule::command('pass241:expire-invitations')
    ->hourly()
    ->timezone($timezone)
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Fermeture des collectes arrivees a leur date de fin (section 35).
 *
 * Meme propriete que la tache precedente : la collecte refuse deja les dons
 * hors delai, `Campaign::acceptsDonations()` verifiant la date et non le
 * statut. Un retard de cette tache ne laisse donc passer aucun don — il laisse
 * seulement une etiquette en retard.
 */
Schedule::command('pass241:expire-campaigns')
    ->hourly()
    ->timezone($timezone)
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Cloture des scrutins arrives a leur date de fin, et figeage de leurs
 * resultats (sections 86.4, 86.5).
 *
 * Meme propriete que les taches precedentes : le scrutin refuse deja les votes
 * hors delai, `PollCategory::acceptsBallots()` verifiant les dates et non le
 * statut. Un retard de cette tache ne laisse donc passer aucun bulletin — il
 * laisse seulement une etiquette en retard, et un classement pas encore fige.
 *
 * Toutes les heures et non chaque nuit : un concours annonce se termine
 * souvent a une heure precise, et le public vient lire le resultat dans la
 * foulee. Attendre le lendemain afficherait « votes en cours » sur un scrutin
 * termine.
 */
Schedule::command('pass241:close-polls')
    ->hourly()
    ->timezone($timezone)
    ->withoutOverlapping()
    ->runInBackground();
