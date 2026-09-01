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
        $reference = trim($reference);

        if ($reference === '') {
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

        return $this->byCompactedReference($reference);
    }

    /**
     * Rattrapage : la reference nous revient SANS SES TIRETS.
     *
     * Certains prestataires refusent tout ce qui n'est pas alphanumerique —
     * PVit repond « Field 'reference' must be an alphanumeric value ». Le driver
     * envoie donc `VT9DNKFPD` la ou nous stockons `VT-9DNK-FPD`, et c'est cette
     * forme compactee que le callback nous renvoie.
     *
     * Sans ce rattrapage, la recherche exacte echouait, le message etait marque
     * « transaction introuvable », et LE PAIEMENT N'ETAIT JAMAIS CONFIRME alors
     * que l'argent etait bien parti. L'echec etait silencieux — c'est le pire
     * cas possible sur le chemin de l'argent.
     *
     * La comparaison se fait en base, sur la reference debarrassee de ses
     * tirets. Elle n'est tentee QU'APRES l'echec de la recherche exacte : le cas
     * courant reste indexe, et ce repli ne coute que sur un chemin qui, sans
     * lui, ne trouvait rien du tout.
     *
     * Aucune ambiguite possible : nos formats placent les tirets a des positions
     * fixes, et les prefixes distinguent les modules. Deux references
     * differentes ne peuvent pas donner la meme chaine compactee.
     */
    private function byCompactedReference(string $reference): (Payable&Model)|null
    {
        $compacted = preg_replace('/[^A-Za-z0-9]/', '', $reference);

        // Rien a rattraper si la reference recue portait deja des separateurs :
        // la recherche exacte les aurait trouves.
        if (! is_string($compacted) || $compacted === '' || $compacted !== $reference) {
            return null;
        }

        foreach ($this->payables as $class) {
            $found = $class::query()
                ->acrossOrganizations()
                ->whereRaw("REPLACE(reference, '-', '') = ?", [$compacted])
                ->first();

            if ($found instanceof Payable) {
                return $found;
            }
        }

        return null;
    }
}
