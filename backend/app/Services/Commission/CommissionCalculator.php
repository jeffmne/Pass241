<?php

namespace App\Services\Commission;

use App\Enums\CommissionType;
use App\Models\Commission;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Calcul et enregistrement de la commission (section 21.2).
 *
 * 5 % sur la billetterie, 2,5 % sur les collectes. Le taux est toujours
 * calcule, jamais saisi (regles 80.1 et 80.2).
 *
 * Tout le calcul est entier. Le taux est exprime en points de base — 500 pour
 * 5 %, 250 pour 2,5 % — et non en pourcentage flottant : 2,5 % ne se
 * represente pas exactement en binaire, et l'erreur finirait par produire un
 * franc d'ecart sur certaines transactions. Sur des milliers de reversements,
 * cet ecart devient une question qu'on ne sait plus expliquer.
 */
class CommissionCalculator
{
    /**
     * Montant de la commission, arrondi au franc le plus proche.
     *
     * `intdiv` sur le numerateur majore de la moitie du denominateur : c'est
     * l'arrondi au plus proche, en arithmetique entiere pure. Aucun flottant
     * n'intervient a aucun moment.
     */
    public function amountFor(int $grossAmount, CommissionType $type): int
    {
        $basisPoints = $type->basisPoints();

        $commission = intdiv($grossAmount * $basisPoints + 5_000, 10_000);

        // Une commission ne peut pas depasser le montant encaisse : ce serait
        // reverser une somme negative a l'organisateur.
        return min($commission, $grossAmount);
    }

    /** Ce qui revient a l'organisateur (section 21.2). */
    public function netFor(int $grossAmount, CommissionType $type): int
    {
        return $grossAmount - $this->amountFor($grossAmount, $type);
    }

    /**
     * Enregistre la commission d'une transaction.
     *
     * Idempotent : une transaction ne peut porter qu'une seule commission,
     * garanti par une contrainte d'unicite en base. Un webhook rejoue retrouve
     * la ligne existante au lieu d'en creer une seconde (section 23).
     */
    public function record(
        Model $commissionable,
        Organization $organization,
        int $grossAmount,
        CommissionType $type,
    ): Commission {
        $existing = Commission::query()
            ->where('commissionable_type', $commissionable->getMorphClass())
            ->where('commissionable_id', $commissionable->getKey())
            // Uniquement la ligne D'ORIGINE : depuis que les ajustements de
            // remboursement partagent la meme transaction, une recherche sans
            // ce filtre pourrait tomber sur un ajustement et faire croire que
            // la commission a deja ete prelevee.
            ->whereNull('parent_commission_id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $commission = $this->amountFor($grossAmount, $type);

        return Commission::create([
            'organization_id' => $organization->getKey(),
            'commissionable_type' => $commissionable->getMorphClass(),
            'commissionable_id' => $commissionable->getKey(),
            'type' => $type->value,
            'gross_amount' => $grossAmount,
            // Instantane du taux : changer la configuration demain ne doit rien
            // changer aux transactions d'hier.
            'rate_basis_points' => $type->basisPoints(),
            'commission_amount' => $commission,
            'net_amount' => $grossAmount - $commission,
        ]);
    }

    /**
     * Enregistre l'ajustement de commission d'un remboursement (section 24.1).
     *
     * Une LIGNE NOUVELLE, liee a la commission d'origine, jamais une
     * modification de celle-ci : la table est en ajout seul, et une correction
     * ne reecrit pas le passe, elle s'ajoute a lui (section 46). C'est aussi ce
     * qui permet, des mois plus tard, de dire ce qui a ete preleve puis ce qui
     * a ete rendu — un simple `UPDATE` effacerait la premiere moitie de
     * l'histoire.
     *
     * Le taux applique est celui de la ligne D'ORIGINE, releve sur elle et non
     * relu dans la configuration. Rembourser au taux d'aujourd'hui une
     * transaction facturee au taux d'hier produirait un ecart que personne ne
     * saurait expliquer.
     *
     * @param  int  $refundedGross  part du brut rendue au client
     */
    public function recordRefund(Commission $original, int $refundedGross): Commission
    {
        if ($original->parent_commission_id !== null) {
            throw new InvalidArgumentException(
                'Un ajustement doit pointer vers une commission d\'origine, pas vers un autre ajustement.'
            );
        }

        $alreadyAdjusted = (int) Commission::query()
            ->acrossOrganizations()
            ->where('parent_commission_id', $original->getKey())
            ->sum('gross_amount');

        $remaining = $original->gross_amount - $alreadyAdjusted;

        if ($refundedGross > $remaining) {
            throw new InvalidArgumentException(sprintf(
                'On ne peut pas rembourser %d FCFA sur une transaction dont il ne reste que %d.',
                $refundedGross,
                $remaining,
            ));
        }

        // Prorata sur le taux fige de la ligne d'origine, en arithmetique
        // entiere pure (decision D-003).
        $commission = intdiv($refundedGross * $original->rate_basis_points + 5_000, 10_000);
        $commission = min($commission, $refundedGross);

        return Commission::create([
            'organization_id' => $original->organization_id,
            'commissionable_type' => $original->commissionable_type,
            'commissionable_id' => $original->commissionable_id,
            'parent_commission_id' => $original->getKey(),
            'type' => $original->type->value,
            'gross_amount' => $refundedGross,
            'rate_basis_points' => $original->rate_basis_points,
            'commission_amount' => $commission,
            'net_amount' => $refundedGross - $commission,
        ]);
    }
}
