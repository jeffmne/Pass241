<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\AccessScan;
use App\Services\Access\AgentAccess;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Historique des controles (section 27.15).
 *
 * L'agent lit ses derniers controles dans sa base locale, y compris hors
 * connexion — cette route ne remplace pas cet ecran, elle le CORRIGE.
 *
 * Deux situations la rendent necessaire, et elles arrivent toutes les deux a
 * une vraie entree :
 *
 * 1. UN VERDICT A CHANGE. Un scan affiche « entree autorisee » sur le telephone
 *    peut perdre l'arbitrage du double scan quand un scan plus ancien arrive
 *    d'une autre porte (section 31.1). L'application ne peut pas le deviner :
 *    seul le serveur arbitre. Relire l'historique est ce qui remet le telephone
 *    d'accord avec la verite.
 *
 * 2. LE TELEPHONE A ETE REINITIALISE, remplace, ou l'application reinstallee en
 *    pleine soiree. Sans cette route, l'agent repart d'un historique vide.
 *
 * PERIMETRE : SES PROPRES CONTROLES, pas ceux de l'entree entiere. Un telephone
 * d'agent se perd ; il n'a pas a contenir la liste nominative de tous ceux qui
 * sont entres par les autres portes (sections 27.5, 27.16). Les compteurs
 * globaux, eux, passent par le tableau de bord — un nombre ne nomme personne.
 */
class HistoryController extends Controller
{
    private const PAGE_SIZE = 50;

    public function __invoke(Request $request, int $eventId, AgentAccess $access): JsonResponse
    {
        $agent = $request->user();

        // Resolution et autorisation en un seul geste : du point de vue de
        // l'agent, un evenement qui ne lui est pas affecte n'existe pas.
        $event = $access->eventFor($agent, $eventId);

        $data = $request->validate([
            // Curseur DECROISSANT : l'historique se lit du plus recent au plus
            // ancien, et c'est le debut de la liste qui interesse l'agent.
            'before' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'between:1,'.self::PAGE_SIZE],
        ]);

        $limit = $data['limit'] ?? self::PAGE_SIZE;

        // Portee levee pour le bloc : le billet et sa ligne de commande sont
        // charges par relation, et une fermeture imbriquee n'en herite pas.
        $scans = app(OrganizationContext::class)->withoutScope(fn () => AccessScan::query()
            ->acrossOrganizations()
            ->where('event_id', $event->getKey())
            ->where('agent_id', $agent->getKey())
            ->with(['ticket.orderItem', 'entryPoint'])
            ->when(isset($data['before']), fn ($query) => $query->where('id', '<', $data['before']))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get());

        $hasMore = $scans->count() > $limit;
        $page = $scans->take($limit);

        return response()->json([
            'scans' => $page->map(fn (AccessScan $scan) => [
                'scan_id' => $scan->getKey(),
                'accepted' => $scan->allowedEntry(),
                'result' => $scan->result->value,
                'label' => $scan->result->label(),
                'instruction' => $scan->result->instruction(),
                // Le nom du porteur reste la seule donnee personnelle
                // transmise : c'est ce que l'agent doit relire pour retrouver
                // un controle, et rien de plus (section 27.5).
                'holder_name' => $scan->ticket?->holder_name,
                'ticket_type' => $scan->ticket?->orderItem?->ticket_type_name,
                'reference' => $scan->ticket?->reference,
                'entry_point' => $scan->entryPoint?->name,
                // Heure du CONTROLE, pas de la synchronisation : c'est celle
                // que l'agent reconnait dans sa soiree.
                'scanned_at' => $scan->scanned_at?->toIso8601String(),
                'manual' => $scan->manual,
                'conflict_with' => $scan->conflict_with,
            ])->values()->all(),
            'next_before' => $hasMore ? $page->last()?->getKey() : null,
            'has_more' => $hasMore,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
