<?php

namespace App\Support;

use Illuminate\Support\Facades\Cookie;

/**
 * Memoire des commandes d'un acheteur, cote navigateur (decision D-004).
 *
 * L'acheteur n'a pas de compte (section 15). La section 15.1 veut malgre tout
 * qu'il retrouve ses billets sans dependre d'un email, et la section 69
 * precise que le canal SMS n'est pas active au lancement — un code de
 * verification par SMS n'etait donc pas disponible.
 *
 * La solution retenue : un cookie de longue duree, chiffre par le framework,
 * qui retient les references de commande passees depuis cet appareil. Combine
 * au lien signe recu a l'achat, cela couvre les deux facons reelles de revenir
 * a ses billets — rouvrir le telephone, ou rouvrir le lien.
 *
 * Limite assumee, ecrite ici pour qu'elle ne se decouvre pas en production :
 * changer de telephone sans avoir garde le lien fait perdre l'acces. C'est le
 * prix de l'absence de compte et de SMS. Le jour ou un fournisseur SMS ou
 * WhatsApp sera retenu, un code de verification par numero prendra le relais
 * sans rien changer au modele de donnees.
 */
class BuyerOrders
{
    private const COOKIE = 'pass241_orders';

    /** Deux ans : un billet s'achete parfois des mois avant l'evenement. */
    private const LIFETIME_MINUTES = 60 * 24 * 365 * 2;

    /** Au-dela, le cookie devient inutilement lourd sur chaque requete. */
    private const MAX_ORDERS = 40;

    /**
     * Retient une commande sur cet appareil.
     *
     * La file est mise a jour en tete : les commandes les plus recentes sont
     * les dernieres a etre oubliees.
     *
     * @return list<string> les references retenues apres ajout
     */
    public function remember(string $reference): array
    {
        $references = array_values(array_unique(
            array_merge([$reference], $this->all())
        ));

        $references = array_slice($references, 0, self::MAX_ORDERS);

        // `queue` : le cookie part avec la reponse en cours. Laravel le chiffre
        // automatiquement, son contenu n'est donc pas lisible cote client.
        Cookie::queue(
            Cookie::make(self::COOKIE, json_encode($references), self::LIFETIME_MINUTES)
        );

        return $references;
    }

    /** @return list<string> */
    public function all(): array
    {
        $raw = request()->cookie(self::COOKIE);

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        // Filtrage strict : un cookie altere ne doit pas injecter n'importe
        // quoi dans une requete, meme si Laravel le chiffre deja.
        return array_values(array_filter(
            $decoded,
            fn ($value) => is_string($value) && preg_match('/^PS241-[A-Z0-9]{4}-[A-Z0-9]{3}$/', $value) === 1,
        ));
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }
}
