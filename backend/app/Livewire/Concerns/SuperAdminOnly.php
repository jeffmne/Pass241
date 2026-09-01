<?php

namespace App\Livewire\Concerns;

/**
 * Reserve un ecran Livewire au Super Admin (section 58).
 *
 * `booted` ET NON `mount`, ET C'EST TOUT L'INTERET.
 *
 * `mount` ne s'execute qu'au premier chargement de la page. Livewire ne le
 * rappelle jamais ensuite : un clic poste sur `/livewire/update`, qui rehydrate
 * le composant depuis son instantane et appelle directement la methode
 * demandee. Une garde posee dans `mount` ne protege donc que l'ouverture.
 *
 * `booted` s'execute a CHAQUE requete, montage et mises a jour comprises. C'est
 * le seul endroit ou une garde vaut pour la duree de vie de l'ecran, et non
 * pour son premier instant.
 *
 * POURQUOI CETTE GARDE EXISTE ALORS QUE LA ROUTE PORTE DEJA `super-admin` :
 * Livewire ne rejoue sur `/livewire/update` qu'une LISTE BLANCHE de
 * middlewares. `EnsureSuperAdmin` n'en fait pas partie par defaut. La console
 * etait donc protegee au chargement de la page, et plus du tout ensuite —
 * quelqu'un dont le statut venait d'etre retire gardait la main tant que son
 * onglet restait ouvert, y compris sur l'ecran qui fixe les taux de commission
 * de la plateforme.
 *
 * Le middleware est desormais persiste lui aussi (AppServiceProvider). Les deux
 * protections se recouvrent volontairement : le middleware couvre la requete,
 * cette garde couvre le composant — et un futur ecran d'administration ajoute
 * hors du groupe de routes resterait couvert par la seconde.
 *
 * 404 et non 403, comme le middleware : l'existence meme de la console n'a pas
 * a etre confirmee a qui n'y a pas acces.
 */
trait SuperAdminOnly
{
    public function bootedSuperAdminOnly(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);
    }
}
