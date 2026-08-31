<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use RuntimeException;

/**
 * Frais du prestataire de paiement, a la charge de l'acheteur (decision D-023).
 *
 * Ces frais sont ajoutes au prix des billets et affiches separement avant le
 * paiement. Ils ne touchent ni la commission Pass241 ni le net de
 * l'organisateur : un billet a 10 000 FCFA rapporte toujours 500 a la
 * plateforme et 9 500 a l'organisateur, quel que soit le prestataire.
 *
 * C'est le SEUL endroit du produit ou un frais est calcule. Le frontend ne
 * fait que l'afficher (regle 80.5) : le montant qui sera debite est toujours
 * celui que ce service produit, jamais celui que le navigateur croit.
 *
 * Tout le calcul est entier, comme la commission (decision D-003). Un taux en
 * points de base plutot qu'en pourcentage flottant, et un arrondi au SUPERIEUR
 * — voir `amountFor`.
 */
class PspFeeCalculator
{
    /**
     * Frais dus sur un montant de billets, pour un prestataire donne.
     *
     * L'arrondi se fait au franc SUPERIEUR, contrairement a la commission qui
     * arrondit au plus proche. La raison est asymetrique : un franc de
     * commission en moins est une recette manquee, un franc de frais en moins
     * est un decouvert sur le compte marchand, que quelqu'un devra combler
     * transaction apres transaction.
     *
     * Un montant nul ne produit aucun frais, part fixe comprise : une commande
     * gratuite ne passe pas par le prestataire, il n'y a rien a lui payer.
     */
    public function feeFor(int $subtotal, ?string $driver = null, ?PaymentProvider $operator = null): PspFee
    {
        if ($subtotal <= 0) {
            return PspFee::none();
        }

        $schedule = $this->schedule($driver, $operator);

        $basisPoints = (int) $schedule['basis_points'];
        $fixed = (int) $schedule['fixed'];

        // Division entiere arrondie au superieur : (a + b - 1) / b.
        $percentage = intdiv($subtotal * $basisPoints + 9_999, 10_000);

        return new PspFee(
            amount: $percentage + $fixed,
            basisPoints: $basisPoints,
            fixed: $fixed,
        );
    }

    /** Montant total debite a l'acheteur. */
    public function totalFor(int $subtotal, ?string $driver = null, ?PaymentProvider $operator = null): int
    {
        return $subtotal + $this->feeFor($subtotal, $driver, $operator)->amount;
    }

    /**
     * Bareme du prestataire.
     *
     * Echoue si le prestataire n'en a pas. C'est volontaire et cela doit le
     * rester : sans cette regle, brancher un vrai prestataire sans renseigner
     * ses frais les ferait absorber en silence par Pass241, transaction apres
     * transaction, sans que rien ne le signale.
     *
     * UN BAREME PEUT DEPENDRE DE L'OPERATEUR, et pas seulement du prestataire.
     * C'est le cas de PVit, qui prend 3,5 % sur Airtel Money et 2,5 % sur Moov
     * Money — releve sur leur API `get-fees`, pas suppose. Un bareme unique par
     * prestataire ferait donc surfacturer les uns ou sous-facturer les autres,
     * et la sous-facturation se paie en decouvert sur le compte marchand.
     *
     * L'operateur n'est pas toujours connu : un ecran affiche le total avant
     * que l'acheteur n'ait choisi. Dans ce cas, et seulement si le prestataire
     * a des baremes distincts, LE PLUS ELEVE est retenu — annoncer moins que ce
     * qui sera debite est la seule erreur qui se voit sur le releve du client.
     *
     * @return array{basis_points: int|string, fixed: int|string}
     */
    private function schedule(?string $driver, ?PaymentProvider $operator = null): array
    {
        $driver ??= (string) config('payments.default');

        $configured = config('payments.fees.'.$driver);

        // Bareme unique, sans distinction d'operateur : le cas courant.
        if (is_array($configured)
            && array_key_exists('basis_points', $configured)
            && array_key_exists('fixed', $configured)) {
            return $configured;
        }

        $perOperator = is_array($configured) ? ($configured['operators'] ?? null) : null;

        if (is_array($perOperator) && $perOperator !== []) {
            if ($operator !== null) {
                $schedule = $perOperator[$operator->value] ?? null;

                if (is_array($schedule)
                    && array_key_exists('basis_points', $schedule)
                    && array_key_exists('fixed', $schedule)) {
                    return $schedule;
                }

                throw new RuntimeException(sprintf(
                    'Aucun bareme de frais configure pour l\'operateur « %s » chez « %s ».',
                    $operator->value,
                    $driver,
                ));
            }

            return $this->mostExpensive($perOperator, $driver);
        }

        throw new RuntimeException(sprintf(
            'Aucun bareme de frais configure pour le prestataire « %s ». '
            .'Renseignez payments.fees.%s avant d\'encaisser avec ce prestataire.',
            $driver,
            $driver,
        ));
    }

    /**
     * Le bareme le plus cher parmi ceux d'un prestataire.
     *
     * Employe quand l'operateur n'est pas encore choisi. La comparaison porte
     * sur les points de base ET la part fixe : un bareme peut etre plus cher
     * sur les petits montants et moins cher sur les gros. A defaut de pouvoir
     * trancher sans montant, on retient le plus penalisant sur chacun des deux
     * axes — ce qui ne sous-estime jamais.
     *
     * @param  array<string, mixed>  $schedules
     * @return array{basis_points: int, fixed: int}
     */
    private function mostExpensive(array $schedules, string $driver): array
    {
        $basisPoints = null;
        $fixed = null;

        foreach ($schedules as $schedule) {
            if (! is_array($schedule)
                || ! array_key_exists('basis_points', $schedule)
                || ! array_key_exists('fixed', $schedule)) {
                continue;
            }

            $basisPoints = max($basisPoints ?? 0, (int) $schedule['basis_points']);
            $fixed = max($fixed ?? 0, (int) $schedule['fixed']);
        }

        if ($basisPoints === null) {
            throw new RuntimeException(sprintf(
                'Les baremes par operateur de « %s » sont mal formes.',
                $driver,
            ));
        }

        return ['basis_points' => $basisPoints, 'fixed' => $fixed ?? 0];
    }
}
