<?php

namespace App\Services\Voting;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Identifie un votant, aussi bien qu'on le peut sans compte (section 86.5).
 *
 * CE QUE CE SERVICE NE FAIT PAS, ET NE PEUT PAS FAIRE.
 *
 * Il ne garantit pas « une personne, un vote ». Sans compte utilisateur et sans
 * OTP SMS (decision D-004), rien n'empeche quelqu'un de saisir cinq numeros
 * differents. La decision D-047 assume cette limite pour les votes gratuits, a
 * condition que l'interface le DISE — c'est la contrepartie, et elle n'est pas
 * negociable.
 *
 * Ce qu'il fait : rendre la fraude fastidieuse plutot qu'immediate. Le numero
 * saisi est l'element principal ; l'adresse IP et l'empreinte du navigateur
 * complètent pour le cas ou aucun numero n'est demande.
 *
 * LE NUMERO N'EST JAMAIS STOCKE EN CLAIR pour un vote gratuit. La page de
 * resultats est publique : la liste des numeros ayant vote pour tel candidat
 * serait une donnee personnelle exposee, sur un sujet — un choix — qui peut
 * valoir des ennuis a quelqu'un. Le hachage permet de COMPTER, pas d'identifier.
 */
class VoterIdentity
{
    /**
     * Empreinte du votant pour une categorie donnee.
     *
     * SALEE PAR CATEGORIE. Deux categories du meme scrutin produisent deux
     * empreintes differentes pour le meme numero : sans cela, recouper les
     * bulletins permettrait de reconstituer le bulletin complet d'une personne
     * a travers toutes les categories — « le votant 4f2a… a choisi ce candidat,
     * puis celui-la, puis celui-la ». Le plafond n'a besoin de compter qu'a
     * l'interieur d'une categorie ; il n'y a donc rien a perdre a les separer.
     *
     * `APP_KEY` entre dans le sel : une base volee sans la cle ne permet pas de
     * tester des numeros un par un pour retrouver qui a vote.
     */
    public function hash(int $categoryId, ?string $phone, Request $request): string
    {
        $subject = $phone !== null && trim($phone) !== ''
            ? 'phone:'.$this->normalizePhone($phone)
            : 'device:'.$this->deviceFingerprint($request);

        return hash_hmac(
            'sha256',
            $subject,
            config('app.key').'|vote|'.$categoryId,
        );
    }

    /**
     * Ramene un numero gabonais a une forme unique.
     *
     * « 077 12 34 56 », « +241 77 12 34 56 » et « 24177123456 » sont le meme
     * numero. Sans normalisation, la meme personne obtiendrait trois empreintes
     * differentes et voterait trois fois sans meme le chercher.
     */
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        // Indicatif du Gabon, avec ou sans le zero national qui le suit.
        $digits = preg_replace('/^241/', '', $digits) ?? $digits;
        $digits = preg_replace('/^0+/', '', $digits) ?? $digits;

        return $digits;
    }

    /**
     * Empreinte d'appareil. C'EST DESORMAIS LE CHEMIN PRINCIPAL DU VOTE
     * GRATUIT, et non plus un repli (decision D-053).
     *
     * Adresse IP et agent utilisateur. L'IP seule ne suffirait pas : au Gabon,
     * une famille ou un bureau passe souvent par le meme partage de connexion,
     * et tous ces gens auraient une seule et meme empreinte. L'agent
     * utilisateur les redistingue des qu'ils n'ont pas le meme telephone, ce
     * qui est le cas courant.
     *
     * CE QU'IL FAUT SAVOIR, ET QUI EST ASSUME : deux personnes avec le meme
     * modele de telephone, le meme navigateur et la meme connexion partagent
     * une empreinte. La seconde se verra refuser son vote — le plafond la
     * comptera comme la premiere. C'est le prix de ne plus demander de numero,
     * et il est paye dans le bon sens : un concours gratuit dont le resultat
     * est de toute facon indicatif (decision D-047) doit d'abord etre facile a
     * voter. L'interface annonce la limite en ces termes — « un vote par
     * appareil » — plutot qu'en « un vote par personne », qui serait faux.
     */
    private function deviceFingerprint(Request $request): string
    {
        return $request->ip().'|'.Str::limit((string) $request->userAgent(), 200, '');
    }

    /** Hachage de l'agent utilisateur, pour l'analyse d'abus (jamais en clair). */
    public function userAgentHash(Request $request): ?string
    {
        $agent = $request->userAgent();

        return $agent === null ? null : hash('sha256', $agent);
    }
}
