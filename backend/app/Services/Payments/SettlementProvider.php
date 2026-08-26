<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;

/**
 * Contrat de REVERSEMENT vers l'organisateur (section 21.2).
 *
 * Interface distincte de CollectionProvider (decision D-024) : un prestataire
 * peut savoir encaisser sans savoir virer. Un driver qui n'implemente pas
 * cette interface annonce, par sa seule signature, qu'aucun reversement
 * automatique n'est possible avec lui — ce qu'il vaut mieux decouvrir avant
 * de signer qu'apres le premier evenement.
 *
 * Ce qui distingue ce contrat de l'encaissement : ici l'argent SORT. Une
 * erreur ne se rattrape pas — un virement mobile money ne se rappelle pas, et
 * un ordre rejoue envoie la somme deux fois. D'ou la cle d'idempotence
 * obligatoire portee par PayoutRequest, et l'etat UNKNOWN qu'il ne faut jamais
 * confondre avec un echec.
 */
interface SettlementProvider
{
    /**
     * Transmet un ordre de reversement.
     *
     * Doit etre idempotent sur la cle portee par la requete : rejouee, la
     * meme cle ne doit produire qu'un seul virement.
     */
    public function settle(PayoutRequest $request): PayoutResult;

    /**
     * Interroge le prestataire sur l'etat d'un reversement.
     *
     * Indispensable au job de reconciliation : c'est ce qui permet de
     * confronter le journal des reversements au relevé reel, et de detecter
     * un ecart avant que l'organisateur ne le signale.
     */
    public function checkPayout(string $providerReference): PayoutResult;

    /**
     * Titulaire d'un numero mobile money, si le prestataire sait le dire.
     *
     * Renvoie null quand la capacite n'existe pas. Sert a afficher « vous
     * allez recevoir sur le 077XXXXXX au nom de … » sans rien demander a
     * l'organisateur : une faute de frappe dans un numero envoie plusieurs
     * centaines de milliers de francs a un inconnu, definitivement.
     */
    public function lookupRecipientName(string $phone, PaymentProvider $operator): ?string;
}
