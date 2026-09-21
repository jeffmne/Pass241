<?php

namespace App\Http\Controllers\Api\Agent;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\Access\AgentAccess;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telechargement des donnees de controle (sections 27.5, 27.10).
 *
 * L'application constitue sa base locale avec cette route, puis valide hors
 * connexion. Trois decisions structurent la reponse :
 *
 * 1. ON ENVOIE DES EMPREINTES, JAMAIS LES JETONS. Un telephone d'agent se perd
 *    et se vole a l'entree d'un concert. Avec les jetons, le voleur repart avec
 *    de quoi fabriquer tous les QR de l'evenement ; avec les empreintes, il ne
 *    peut que reconnaitre un billet, pas en creer un.
 *
 * 2. PAGINATION PAR CURSEUR, jamais par numero de page. Sur 100 000 billets,
 *    un `OFFSET 90000` fait relire quatre-vingt-dix mille lignes a chaque
 *    appel. Le curseur lit toujours la meme quantite, quelle que soit la
 *    profondeur.
 *
 * 3. SYNCHRONISATION INCREMENTALE via `since`. Une porte qui se reconnecte au
 *    milieu de la soiree ne retelecharge pas tout : elle demande ce qui a
 *    change. Sans cela, chaque reconnexion couterait plusieurs mega-octets sur
 *    une connexion mobile.
 */
class SyncController extends Controller
{
    /**
     * Taille de page.
     *
     * 500 billets font environ 80 ko de JSON : assez pour que la
     * synchronisation avance vite, assez peu pour qu'une coupure ne fasse
     * perdre qu'un aller-retour.
     */
    private const PAGE_SIZE = 500;

    public function __invoke(Request $request, int $eventId, AgentAccess $access): JsonResponse
    {
        // Resolution et autorisation en un seul geste : du point de vue de
        // l'agent, un evenement qui ne lui est pas affecte n'existe pas.
        $event = $access->eventFor($request->user(), $eventId);

        $data = $request->validate([
            'since' => ['nullable', 'date'],
            'cursor' => ['nullable', 'integer', 'min:0'],
        ]);

        // Borne prise AVANT la lecture. Le mobile garde celle de la premiere
        // page : une modification d'un billet deja parcouru sera relue au
        // prochain tour, meme si le telechargement dure plusieurs minutes.
        $syncedAt = now()->toIso8601String();

        // Portee levee pour le bloc : la ligne de commande est chargee par
        // relation, et une fermeture imbriquee n'en herite pas.
        $query = app(OrganizationContext::class)->withoutScope(fn () => Ticket::query()
            ->where('event_id', $event->getKey())
            ->with(['orderItem'])
            ->when(isset($data['since']), fn ($q) => $q->where('updated_at', '>=', $data['since']))
            ->when(isset($data['cursor']), fn ($q) => $q->where('id', '>', $data['cursor']))
            ->orderBy('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get());

        $hasMore = $query->count() > self::PAGE_SIZE;
        $tickets = $query->take(self::PAGE_SIZE);

        return response()->json([
            'tickets' => $tickets->map(fn (Ticket $ticket) => [
                'id' => $ticket->getKey(),
                'reference' => $ticket->reference,
                // Empreinte, jamais le jeton (voir l'en-tete).
                'token_hash' => $ticket->tokenHash(),
                'holder_name' => $ticket->holder_name,
                'ticket_type' => $ticket->orderItem?->ticket_type_name,
                'status' => $ticket->status->value,
                // L'application refuse localement tout ce qui n'est pas
                // ACTIVE, et remonte le scan pour arbitrage.
                'allows_entry' => $ticket->status === TicketStatus::Active,
                'updated_at' => $ticket->updated_at?->toIso8601String(),
            ])->values()->all(),
            'next_cursor' => $hasMore ? $tickets->last()?->getKey() : null,
            'has_more' => $hasMore,
            // Horodatage a renvoyer en `since` a la prochaine synchronisation.
            'synced_at' => $syncedAt,
        ]);
    }
}
