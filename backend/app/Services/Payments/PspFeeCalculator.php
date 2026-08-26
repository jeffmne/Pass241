<?php

namespace App\Services\Payments;

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
    public function feeFor(int $subtotal, ?string $driver = null): PspFee
    {
        if ($subtotal <= 0) {
            return PspFee::none();
        }

        $schedule = $this->schedule($driver);

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
    public function totalFor(int $subtotal, ?string $driver = null): int
    {
        return $subtotal + $this->feeFor($subtotal, $driver)->amount;
    }

    /**
     * Bareme du prestataire.
     *
     * Echoue si le prestataire n'en a pas. C'est volontaire et cela doit le
     * rester : sans cette regle, brancher un vrai prestataire sans renseigner
     * ses frais les ferait absorber en silence par Pass241, transaction apres
     * transaction, sans que rien ne le signale.
     *
     * @return array{basis_points: int|string, fixed: int|string}
     */
    private function schedule(?string $driver): array
    {
        $driver ??= (string) config('payments.default');

        $schedule = config('payments.fees.'.$driver);

        if (! is_array($schedule)
            || ! array_key_exists('basis_points', $schedule)
            || ! array_key_exists('fixed', $schedule)) {
            throw new RuntimeException(sprintf(
                'Aucun bareme de frais configure pour le prestataire « %s ». '
                .'Renseignez payments.fees.%s avant d\'encaisser avec ce prestataire.',
                $driver,
                $driver,
            ));
        }

        return $schedule;
    }
}
