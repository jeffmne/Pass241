<?php

namespace App\Services\Platform;

use App\Enums\PayoutState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ce qui demande une intervention humaine (section 72).
 *
 * DISTINCT DE `HealthCheck`, ET LA DISTINCTION DECIDE DE QUI EST REVEILLE LA
 * NUIT. La sonde de sante repond a « le service repond-il ? » et s'adresse a un
 * moniteur externe. Ce rapport-ci repond a « qu'est-ce qui est reste en
 * chemin ? » et s'adresse a quelqu'un qui regarde un ecran le matin.
 *
 * TROIS PANNES SILENCIEUSES, CLASSEES PAR CE QU'ELLES COUTENT.
 *
 * 1. UN CALLBACK DE PAIEMENT RECU MAIS JAMAIS TRAITE. C'est la pire : l'argent
 *    a quitte le telephone de l'acheteur, l'operateur nous a prevenus, et la
 *    commande est restee en attente. L'acheteur n'a pas son billet, il a paye,
 *    et rien dans le produit ne le signale — il faut qu'il ecrive pour qu'on
 *    l'apprenne.
 *
 * 2. UN JOB EN ECHEC. Billet non envoye, recu non genere, invitation non
 *    partie. Rarement grave pris isolement — le billet reste consultable en
 *    ligne (section 15.1) — mais un compteur qui monte revele une panne de
 *    fond.
 *
 * 3. UN REVERSEMENT DANS UN ETAT INCONNU. L'ordre est parti chez l'operateur,
 *    sa reponse ne permet pas de conclure. Personne ne peut decider a la place
 *    d'un humain : rejouer risquerait de payer deux fois, abandonner de ne pas
 *    payer du tout.
 *
 * AUCUN CACHE. Ce rapport est lu par une poignee de personnes, quelques fois
 * par jour, et sa valeur tient a sa fraicheur : un compteur d'incidents en
 * retard de cinq minutes est un compteur en qui on cesse de croire.
 */
class SupervisionReport
{
    /**
     * Delai au-dela duquel un callback non traite devient anormal.
     *
     * Le traitement passe par la file : quelques secondes en marche normale.
     * Cinq minutes laissent de la marge a une reprise, sans laisser dormir un
     * paiement perdu toute une soiree de concert.
     */
    private const WEBHOOK_GRACE_MINUTES = 5;

    /**
     * @return array<string, array{count: int, oldest: ?Carbon, detail: ?string}>
     */
    public function incidents(): array
    {
        return [
            'webhooks' => $this->pendingWebhooks(),
            'jobs' => $this->failedJobs(),
            'settlements' => $this->unresolvedSettlements(),
        ];
    }

    /** Y a-t-il quelque chose a regarder ? */
    public function hasIncidents(): bool
    {
        foreach ($this->incidents() as $incident) {
            if ($incident['count'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Callbacks de paiement recus et non traites.
     *
     * @return array{count: int, oldest: ?Carbon, detail: ?string}
     */
    private function pendingWebhooks(): array
    {
        $rows = DB::table('payment_webhooks')
            ->whereNull('processed_at')
            ->where('created_at', '<', now()->subMinutes(self::WEBHOOK_GRACE_MINUTES))
            ->selectRaw('COUNT(*) AS total, MIN(created_at) AS oldest')
            ->first();

        return [
            'count' => (int) ($rows->total ?? 0),
            'oldest' => $rows?->oldest ? Carbon::parse($rows->oldest) : null,
            'detail' => 'Un paiement encaisse dont la commande est restee en attente.',
        ];
    }

    /**
     * @return array{count: int, oldest: ?Carbon, detail: ?string}
     */
    private function failedJobs(): array
    {
        $rows = DB::table('failed_jobs')
            ->selectRaw('COUNT(*) AS total, MIN(failed_at) AS oldest')
            ->first();

        return [
            'count' => (int) ($rows->total ?? 0),
            'oldest' => $rows?->oldest ? Carbon::parse($rows->oldest) : null,
            'detail' => 'Email, recu ou billet non parti. Le billet reste consultable en ligne.',
        ];
    }

    /**
     * @return array{count: int, oldest: ?Carbon, detail: ?string}
     */
    private function unresolvedSettlements(): array
    {
        $rows = DB::table('settlements')
            ->whereIn('status', [PayoutState::Failed->value, PayoutState::Unknown->value])
            ->selectRaw('COUNT(*) AS total, MIN(created_at) AS oldest')
            ->first();

        return [
            'count' => (int) ($rows->total ?? 0),
            'oldest' => $rows?->oldest ? Carbon::parse($rows->oldest) : null,
            'detail' => 'A trancher a la main : rejouer risque de payer deux fois.',
        ];
    }
}
