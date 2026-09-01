<?php

namespace App\Livewire\Concerns;

use App\Enums\PermissionName;

/**
 * Reserve un ecran Livewire aux porteurs d'une permission (sections 9, 10).
 *
 * `booted` ET NON `mount`, ET C'EST TOUTE LA RAISON D'ETRE DE CE TRAIT.
 *
 * `mount` ne s'execute qu'au premier chargement de la page. Livewire ne le
 * rappelle jamais ensuite : un clic poste sur `/livewire/update`, qui rehydrate
 * le composant depuis son instantane et appelle directement la methode
 * demandee. Une garde posee dans `mount` ne protege donc que l'ouverture de
 * l'ecran, pas sa duree de vie.
 *
 * Tant qu'un ecran ne fait que lire, la consequence est mince. Mais ces
 * ecrans-la ECRIVENT, et leurs methodes d'ecriture ne portaient pour la plupart
 * aucune garde propre : creer un tarif, modifier le prix d'un billet, ajouter
 * une categorie a un concours reposaient entierement sur la garde du montage.
 * Un organisateur qui retirait ses droits a un membre ne les lui retirait donc
 * qu'au prochain rechargement de sa page — une regle d'autorisation qui depend
 * de la duree de vie d'un onglet n'en est pas une.
 *
 * `booted` s'execute a CHAQUE requete, montage et mises a jour comprises.
 *
 * LES PERMISSIONS SONT RELUES A CHAQUE FOIS, jamais mises en cache ici : c'est
 * ce qui fait qu'un retrait prend effet au clic suivant. Spatie tient son
 * propre cache pour la duree de la requete, ce qui est le bon grain.
 *
 * UNE SEULE PERMISSION, celle qui ouvre l'ecran. Une operation plus sensible
 * que l'ecran lui-meme garde la sienne, a son propre endroit : consulter les
 * remboursements demande `finances.view`, en trancher un demande en plus
 * `finances.refund` (section 24.1). Ce trait ne remplace pas ces gardes-la, il
 * pose le plancher sous elles.
 */
trait RequiresPermission
{
    public function bootedRequiresPermission(): void
    {
        abort_unless(
            auth()->user()?->can($this->requiredPermission()->value),
            403,
        );
    }

    /** La permission sans laquelle cet ecran ne s'ouvre pas. */
    abstract protected function requiredPermission(): PermissionName;
}
