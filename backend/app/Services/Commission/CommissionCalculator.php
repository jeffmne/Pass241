<?php

namespace App\Services\Commission;

use App\Enums\CommissionType;
use App\Models\Commission;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;

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
}
