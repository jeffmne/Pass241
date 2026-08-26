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
 * Recherche manuelle a l'entree (section 27.15).
 *
 * Le QR ne se lit pas : ecran casse, papier trempe, impression trop pale,
 * capteur qui refuse dans le noir. La personne a pourtant un billet. L'agent
 * cherche par reference ou par nom, retrouve le billet, et l'admet.
 *
 * Hors connexion, cette recherche se fait dans la base locale de l'application
 * — c'est la regle, et c'est ce qui la rend utilisable a une porte de concert
 * sans reseau. Cette route sert les deux cas que le local ne couvre pas : une
 * application fraichement installee dont la base n'est pas encore constituee,
 * et un billet achete APRES la derniere synchronisation, ce qui arrive
 * continuellement pendant les deux heures qui precedent un concert.
 *
 * CE QU'ELLE NE FAIT PAS : elle ne fait entrer personne. Elle propose des
 * candidats. L'admission passe par la remontee de scan, avec les memes verrous
 * et le meme arbitrage que le QR — et elle est marquee comme manuelle.
 *
 * Aucun jeton ne descend ici, pas plus que dans la synchronisation : un
 * telephone perdu ne doit jamais donner de quoi fabriquer un QR.
 */
class TicketSearchController extends Controller
{
    /**
     * Assez pour retrouver quelqu'un dans une file, trop peu pour recopier une
     * liste d'invites. La recherche n'est pas un export (section 59).
     */
    private const MAX_RESULTS = 20;

    public function __invoke(Request $request, int $eventId, AgentAccess $access): JsonResponse
    {
        // Resolution et autorisation en un seul geste : du point de vue de
        // l'agent, un evenement qui ne lui est pas affecte n'existe pas.
        $event = $access->eventFor($request->user(), $eventId);

        $data = $request->validate([
            /*
             * Trois caracteres au minimum.
             *
             * En dessous, la recherche ne distingue plus rien et se transforme
             * en liste des participants — ce n'est pas ce qu'on met sur un
             * telephone qui se perd (section 27.16).
             */
            'q' => ['required', 'string', 'min:3', 'max:120'],
        ], [
            'q.min' => 'Saisissez au moins trois caracteres.',
        ]);

        $term = $this->escapeLike(trim($data['q']));

        // Portee levee pour le bloc : la ligne de commande est chargee par
        // relation, et une fermeture imbriquee n'en herite pas.
        $tickets = app(OrganizationContext::class)->withoutScope(fn () => Ticket::query()
            ->acrossOrganizations()
            ->where('event_id', $event->getKey())
            ->with('orderItem')
            ->where(function ($query) use ($term) {
                // La reference se cherche par le debut : l'agent lit ce qui
                // reste lisible sur un billet abime, souvent les premiers
                // caracteres.
                $query->where('reference', 'ilike', $term.'%')
                    ->orWhere('holder_name', 'ilike', '%'.$term.'%');
            })
            // Les billets encore attendus d'abord : a la porte, un billet deja
            // passe ou rembourse est une information, pas une reponse.
            ->orderByRaw('case when status = ? then 0 else 1 end', [TicketStatus::Active->value])
            ->orderBy('reference')
            ->limit(self::MAX_RESULTS + 1)
            ->get());

        $truncated = $tickets->count() > self::MAX_RESULTS;

        return response()->json([
            'tickets' => $tickets->take(self::MAX_RESULTS)->map(fn (Ticket $ticket) => [
                'id' => $ticket->getKey(),
                // C'est cette valeur que l'application renvoie pour admettre :
                // le jeton, lui, ne quitte jamais le serveur.
                'reference' => $ticket->reference,
                'holder_name' => $ticket->holder_name,
                'ticket_type' => $ticket->orderItem?->ticket_type_name,
                'status' => $ticket->status->value,
                'allows_entry' => $ticket->status === TicketStatus::Active,
                'used_at' => $ticket->used_at?->toIso8601String(),
            ])->values()->all(),
            // Signale a l'agent qu'il doit preciser sa recherche plutot que de
            // croire que le billet cherche n'existe pas.
            'truncated' => $truncated,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Neutralise les jokers de LIKE.
     *
     * Sans cela, une recherche sur « % » renverrait la liste entiere des
     * participants — exactement ce que la limite de trois caracteres cherche a
     * empecher.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
