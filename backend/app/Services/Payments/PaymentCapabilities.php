<?php

namespace App\Services\Payments;

/**
 * Ce qu'un prestataire de paiement sait faire (decision D-024).
 *
 * Declare par le driver lui-meme, et non lu dans la configuration : un
 * prestataire ne sait pas eclater un encaissement parce qu'un fichier de
 * config l'affirme. Le code du driver est la seule source honnete, et il ne
 * peut pas diverger de la realite sans que quelqu'un le modifie.
 *
 * Ces capacites decident de la strategie de reversement retenue au jalon D :
 * eclatement natif si le prestataire sait le faire, virement programme sinon,
 * et si aucun des deux, les fonds transitent par le compte marchand Pass241 —
 * ce qu'il faut savoir AVANT de signer, pas apres (section 21.2).
 */
readonly class PaymentCapabilities
{
    public function __construct(
        /**
         * Le prestataire eclate l'encaissement entre plusieurs beneficiaires.
         *
         * C'est la cible : les fonds ne transitent jamais par Pass241, qui
         * n'est alors jamais depositaire, meme une seconde.
         */
        public bool $nativeSplit = false,

        /** Le prestataire expose une API de virement sortant. */
        public bool $payout = false,

        /**
         * Le virement sortant peut etre programme a une date future.
         *
         * Determinant : la spec reverse APRES l'evenement. Si le prestataire
         * sait tenir les fonds jusque-la dans ses propres livres, Pass241 ne
         * fait que donner l'instruction et ne detient rien.
         */
        public bool $scheduledPayout = false,

        /** Remboursement d'une fraction seulement de la transaction. */
        public bool $partialRefund = false,

        /**
         * Le prestataire renvoie le nom du titulaire d'un numero.
         *
         * Permet d'afficher « vous allez recevoir sur le 077XXXXXX au nom
         * de … » sans rien demander a l'organisateur. Un virement mobile money
         * ne se rappelle pas : une faute de frappe est definitive.
         */
        public bool $recipientNameLookup = false,
    ) {}

    /**
     * Le prestataire peut-il faire arriver l'argent chez l'organisateur ?
     *
     * Faux signifie qu'aucun reversement automatique n'est possible : le seul
     * recours serait un virement fait a la main, que la section 21.2 exclut.
     */
    public function canSettle(): bool
    {
        return $this->nativeSplit || $this->payout;
    }

    /**
     * Pass241 detient-il les fonds entre l'encaissement et le reversement ?
     *
     * Vrai sans eclatement natif ni virement programme : l'argent atterrit
     * alors sur le compte marchand de la plateforme et y reste jusqu'a
     * l'evenement. C'est exactement ce que le produit veut eviter
     * (section 21.2) — cette methode existe pour que ce soit visible plutot
     * que subi.
     */
    public function holdsFunds(): bool
    {
        return ! $this->nativeSplit && ! $this->scheduledPayout;
    }
}
