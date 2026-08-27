<?php

namespace App\Services\Settlement;

use App\Enums\CommissionType;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;

/**
 * Ce dont le net peut etre reverse a l'organisateur (section 21.2, D-041).
 *
 * Symetrique du contrat `Payable` : celui-la decrit ce qui ENTRE, celui-ci ce
 * qui SORT. Un evenement et une collecte encaissent differemment mais se
 * reversent de la meme facon — un virement unique, au net des commissions
 * figees, une fois l'activite terminee.
 *
 * CE QUI EST PARTAGE vit dans `SettlementLedger` et `SettleFunds` : la somme
 * signee des commissions, le seuil minimal, l'unicite du virement, l'ordre au
 * prestataire et l'interdiction de conclure a l'echec sur un etat inconnu. Ce
 * sont les regles ou une erreur coute de l'argent reel, et elles ne doivent
 * exister qu'en un seul exemplaire.
 *
 * CE QUI EST PROPRE au module tient ici : quelles commissions sont les
 * siennes, et quand son activite est terminee.
 */
interface Settlable
{
    public function settlementOrganization(): ?Organization;

    /**
     * Restreint une requete sur `commissions` a ce qui appartient a cet objet.
     *
     * Le calcul lui-meme — somme signee des lignes figees, ajustements de
     * remboursement soustraits — reste dans le grand livre. Une implementation
     * ne decide que du perimetre, jamais du montant.
     */
    public function constrainCommissions(Builder $query): void;

    public function settlementCommissionType(): CommissionType;

    /**
     * Fin de l'activite, ou null tant qu'elle continue.
     *
     * Un evenement : sa date de fin. Une collecte : le moment ou elle a ete
     * cloturee, annulee ou echue — tant qu'elle est ouverte, elle peut encore
     * recevoir des dons, et reverser reviendrait a couper la collecte en
     * tranches arbitraires.
     */
    public function settlementEndsAt(): ?CarbonInterface;

    /** Libelle court, imprime sur le releve de l'organisateur. */
    public function settlementLabel(): string;
}
