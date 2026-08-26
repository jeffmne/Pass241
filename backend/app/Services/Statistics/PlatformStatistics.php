<?php

namespace App\Services\Statistics;

use App\Enums\OrderStatus;
use App\Enums\PayoutState;
use App\Enums\TicketStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Chiffres consolides de la plateforme, pour le Super Admin (section 58).
 *
 * SEUL SERVICE DU PRODUIT QUI COMPTE TOUTES ORGANISATIONS CONFONDUES. Les
 * requetes attaquent les tables directement, sans passer par Eloquent : la
 * portee globale n'a rien a filtrer ici, et l'ecrire explicitement en SQL rend
 * l'intention lisible plutot que dependante d'un `acrossOrganizations()`
 * qu'on pourrait oublier ailleurs.
 *
 * Tout est agrege. Une plateforme qui marche aura des millions de billets :
 * ces chiffres doivent couter la meme chose a calculer qu'aujourd'hui.
 *
 * LA COMMISSION EST LE SEUL REVENU DE PASS241. Le volume brut transite par la
 * plateforme sans lui appartenir (section 21.2) — les deux sont affiches
 * separement, et les confondre donnerait un chiffre d'affaires imaginaire.
 */
class PlatformStatistics
{
    /**
     * Duree de mise en cache.
     *
     * Cinq minutes plutot qu'une : ces chiffres se regardent, ils ne se
     * surveillent pas a la seconde. Ce qui doit remonter vite — un reversement
     * en echec — a son propre compteur, non cache.
     */
    private const CACHE_SECONDS = 300;

    /**
     * @return array{
     *     organizations: int, activeOrganizations: int, users: int,
     *     events: int, publishedEvents: int, ticketsSold: int,
     *     grossVolume: int, commission: int, settledNet: int,
     *     ordersPaid: int, refunded: int
     * }
     */
    public function totals(): array
    {
        return Cache::remember('stats:platform', self::CACHE_SECONDS, function () {
            $tickets = DB::table('tickets')
                ->join('order_items', 'order_items.id', '=', 'tickets.order_item_id')
                ->whereIn('tickets.status', [TicketStatus::Active->value, TicketStatus::Used->value])
                ->selectRaw('COUNT(*) AS sold, COALESCE(SUM(order_items.unit_price), 0) AS gross')
                ->first();

            /*
             * Commission nette : les lignes d'origine moins les ajustements de
             * remboursement (decision D-027). Les additionner sans distinguer
             * les deux doublerait le revenu de la plateforme.
             */
            $commission = DB::table('commissions')
                ->selectRaw('COALESCE(SUM(
                    CASE WHEN parent_commission_id IS NULL
                         THEN commission_amount
                         ELSE -commission_amount END
                ), 0) AS total')
                ->value('total');

            return [
                'organizations' => (int) DB::table('organizations')->count(),
                'activeOrganizations' => (int) DB::table('organizations')->whereNull('suspended_at')->count(),
                'users' => (int) DB::table('users')->count(),
                'events' => (int) DB::table('events')->count(),
                'publishedEvents' => (int) DB::table('events')->where('status', 'PUBLISHED')->count(),
                'ticketsSold' => (int) ($tickets->sold ?? 0),
                // Ce qui a transite, pas ce que Pass241 a gagne.
                'grossVolume' => (int) ($tickets->gross ?? 0),
                'commission' => (int) $commission,
                'settledNet' => (int) DB::table('settlements')
                    ->where('status', PayoutState::Succeeded->value)
                    ->sum('net_amount'),
                'ordersPaid' => (int) DB::table('orders')
                    ->whereIn('status', [
                        OrderStatus::Paid->value,
                        OrderStatus::PartiallyRefunded->value,
                        OrderStatus::Refunded->value,
                    ])->count(),
                'refunded' => (int) DB::table('refund_requests')
                    ->where('status', 'APPROVED')
                    ->sum('amount'),
            ];
        });
    }

    /**
     * Ce qui demande une intervention humaine, maintenant.
     *
     * JAMAIS CACHE, contrairement au reste : un reversement en echec doit
     * apparaitre a l'instant ou il echoue, pas cinq minutes plus tard. C'est
     * le seul chiffre de cet ecran sur lequel quelqu'un doit agir.
     *
     * @return array{settlements: int, refunds: int}
     */
    public function attention(): array
    {
        return [
            'settlements' => (int) DB::table('settlements')
                ->whereIn('status', [PayoutState::Failed->value, PayoutState::Unknown->value])
                ->count(),
            'refunds' => (int) DB::table('refund_requests')
                ->where('status', 'REQUESTED')
                ->count(),
        ];
    }

    public function forget(): void
    {
        Cache::forget('stats:platform');
    }
}
