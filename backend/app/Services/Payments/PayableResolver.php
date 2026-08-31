<?php

namespace App\Services\Payments;

use App\Models\Ballot;
use App\Models\Donation;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;

/**
 * Retrouve ce qu'un webhook paie, a partir de sa reference (decision D-041).
 *
 * Un operateur mobile money ne renvoie qu'une chaine — la reference que nous
 * lui avons donnee au moment de pousser le paiement. C'est ici qu'on decide ce
 * qu'elle designe.
 *
 * Les references portent des prefixes distincts (`PS241-` pour une commande,
 * `DON-` pour un don), mais la recherche ne s'y fie pas : elle interroge chaque
 * type. Un prefixe est une convention d'affichage, et faire dependre le
 * rattachement de l'argent d'une convention d'affichage se paierait le jour ou
 * l'une des deux changerait.
 *
 * La portee par organisation est levee partout : un worker de file d'attente
 * n'a aucun contexte, et le webhook arrive avant qu'on sache a qui il se
 * rattache.
 */
class PayableResolver
{
    /**
     * Types encaissables, dans l'ordre de recherche.
     *
     * Le module Votes a ajoute `Ballot::class` ici, et NULLE PART AILLEURS :
     * c'est tout ce qu'il a fallu pour que les bulletins payants empruntent le
     * chemin de l'argent existant, contrainte tenue (decision D-041).
     *
     * @var list<class-string<Model>>
     */
    private array $payables = [
        Order::class,
        Donation::class,
        Ballot::class,
    ];

    public function byReference(string $reference): (Payable&Model)|null
    {
        if (trim($reference) === '') {
            return null;
        }

        foreach ($this->payables as $class) {
            $found = $class::query()
                ->acrossOrganizations()
                ->where('reference', $reference)
                ->first();

            if ($found instanceof Payable) {
                return $found;
            }
        }

        return null;
    }
}
