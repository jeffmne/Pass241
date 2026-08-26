<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\EventAgent;
use App\Services\Access\AgentAccess;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Evenements qu'un agent peut controler (sections 27.4, 30).
 *
 * Premier appel apres la connexion. La reponse tient volontairement en peu de
 * champs : c'est un ecran de choix, pas une fiche evenement. Tout ce qui n'y
 * figure pas est du poids transfere sur une connexion mobile gabonaise pour
 * rien (section 72).
 */
class EventController extends Controller
{
    public function __invoke(Request $request, AgentAccess $access): JsonResponse
    {
        $assignments = $access->assignments($request->user());

        // Le comptage des billets passe par une relation : la portee est levee
        // pour le bloc entier, jamais relation par relation.
        return app(OrganizationContext::class)->withoutScope(fn () => response()->json([
            'events' => $assignments->map(fn (EventAgent $assignment) => [
                'id' => $assignment->event->getKey(),
                'name' => $assignment->event->name,
                'starts_at' => $assignment->event->starts_at?->toIso8601String(),
                'ends_at' => $assignment->event->ends_at?->toIso8601String(),
                'venue' => $assignment->event->location?->name,
                'organization' => $assignment->event->organization?->name,
                // Le point d'entree vient de l'AFFECTATION, pas d'un choix
                // dans l'application : c'est l'organisateur qui repartit ses
                // agents (section 30).
                'entry_point' => $assignment->entryPoint ? [
                    'id' => $assignment->entryPoint->getKey(),
                    'name' => $assignment->entryPoint->name,
                ] : null,
                'tickets_count' => $assignment->event->tickets()->count(),
            ])->all(),
            'server_time' => now()->toIso8601String(),
        ]));
    }
}
