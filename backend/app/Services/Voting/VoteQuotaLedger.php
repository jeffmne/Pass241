<?php

namespace App\Services\Voting;

use App\Models\PollCategory;
use Illuminate\Support\Facades\DB;

/**
 * Fait respecter le plafond de votes par personne (sections 86.3, 86.5).
 *
 * TOUT L'INTERET DE CETTE CLASSE TIENT DANS UNE REQUETE :
 *
 *     UPDATE voter_quotas SET used = used + :n
 *     WHERE poll_category_id = :c AND voter_hash = :h AND used + :n <= :max
 *
 * Un `SELECT` suivi d'un `INSERT` laisserait passer deux envois simultanes : les
 * deux liraient « deux voix utilisees sur trois », les deux concluraient qu'il
 * reste de la place, et le plafond serait depasse. La fenetre est etroite, mais
 * un concours ou l'on peut voter deux fois en cliquant vite est un concours
 * truque — et c'est exactement ce qu'un candidat motive essaiera.
 *
 * PostgreSQL serialise les deux ecritures sur la ligne : la seconde lit la
 * valeur deja incrementee, et son `WHERE` echoue. Le nombre de lignes affectees
 * est la reponse — 1 si le vote passe, 0 si le plafond est atteint. Aucun verrou
 * explicite, aucune transaction longue.
 *
 * C'est le mecanisme de `StockLedger::commit`, applique a un compteur au lieu
 * d'un stock.
 */
class VoteQuotaLedger
{
    /**
     * Reserve `$quantity` voix pour ce votant, ou echoue.
     *
     * @return bool vrai si la reservation est acquise
     */
    public function reserve(PollCategory $category, string $voterHash, int $quantity): bool
    {
        $limit = $category->voteLimit();

        // Aucun plafond : rien a reserver, rien a compter. C'est le cas normal
        // d'une categorie payante, ou le paiement fait lui-meme la limite.
        if ($limit === null) {
            return true;
        }

        if ($quantity > $limit) {
            return false;
        }

        /*
         * La ligne doit exister avant de pouvoir etre incrementee.
         *
         * `insertOrIgnore` plutot que `firstOrCreate` : deux premieres
         * soumissions simultanees tenteraient toutes deux l'insertion, et la
         * seconde violerait l'unicite. Ici, elle est simplement ignoree — puis
         * les deux passent a l'`UPDATE`, ou la serialisation fait son travail.
         */
        DB::table('voter_quotas')->insertOrIgnore([
            'poll_category_id' => $category->getKey(),
            'voter_hash' => $voterHash,
            'used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $affected = DB::update(
            'UPDATE voter_quotas
                SET used = used + ?, updated_at = ?
              WHERE poll_category_id = ?
                AND voter_hash = ?
                AND used + ? <= ?',
            [$quantity, now(), $category->getKey(), $voterHash, $quantity, $limit],
        );

        return $affected === 1;
    }

    /**
     * Rend des voix reservees.
     *
     * Appelee quand un paiement echoue : les voix avaient ete reservees a la
     * creation du bulletin en attente, et l'echec doit les rendre — sinon
     * quelqu'un dont le paiement a echoue resterait bloque sur son plafond sans
     * avoir vote.
     *
     * `GREATEST(used - n, 0)` plutot qu'une simple soustraction : la contrainte
     * `used >= 0` refuserait une valeur negative, et une liberation appelee deux
     * fois — un webhook rejoue — ferait echouer la requete au lieu de ne rien
     * faire.
     */
    public function release(PollCategory $category, string $voterHash, int $quantity): void
    {
        if ($category->voteLimit() === null) {
            return;
        }

        DB::update(
            'UPDATE voter_quotas
                SET used = GREATEST(used - ?, 0), updated_at = ?
              WHERE poll_category_id = ?
                AND voter_hash = ?',
            [$quantity, now(), $category->getKey(), $voterHash],
        );
    }

    /** Voix deja utilisees par ce votant. */
    public function used(PollCategory $category, string $voterHash): int
    {
        return (int) DB::table('voter_quotas')
            ->where('poll_category_id', $category->getKey())
            ->where('voter_hash', $voterHash)
            ->value('used');
    }

    /** Voix restantes, ou null si la categorie ne pose aucun plafond. */
    public function remaining(PollCategory $category, string $voterHash): ?int
    {
        $limit = $category->voteLimit();

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->used($category, $voterHash));
    }
}
