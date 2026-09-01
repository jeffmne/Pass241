<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;

/**
 * Prestataire qui exige un accuse de reception d'une forme precise.
 *
 * La plupart se contentent d'un 200. Certains verifient le CORPS de la reponse
 * et considerent la notification comme perdue s'il ne leur convient pas —
 * PVit refuse de valider une integration tant que l'accuse n'est pas conforme,
 * meme lorsque tous les paiements aboutissent.
 *
 * Interface SEPAREE et non methode ajoutee a `CollectionProvider`, sur le meme
 * modele que `SettlementProvider` : c'est une capacite que certains ont et
 * d'autres pas. L'ajouter au contrat principal obligerait chaque driver a
 * declarer qu'il n'en a pas besoin, ce qui est du bruit.
 */
interface AcknowledgesCallbacks
{
    /**
     * Corps de la reponse a renvoyer au prestataire.
     *
     * Recoit la requete ENTRANTE, et non le resultat normalise : un accuse de
     * reception est un echo de ce qui a ete recu. Le construire depuis nos
     * propres donnees le rendrait faux des que notre lecture differerait de
     * leur envoi — or c'est precisement ce que le prestataire verifie.
     *
     * @return array<string, mixed>
     */
    public function callbackAcknowledgement(Request $request): array;
}
