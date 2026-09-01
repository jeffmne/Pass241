<?php

namespace App\Http\Controllers;

use App\Services\Platform\HealthCheck;
use Illuminate\Http\JsonResponse;

/**
 * Sonde de disponibilite, pour un moniteur externe (section 72).
 *
 * COMPLETE `/up` PLUTOT QUE DE LA REMPLACER, parce que les deux repondent a
 * deux questions differentes :
 *
 * - `/up`, livree par Laravel, dit que le PROCESSUS est vivant. C'est ce qu'un
 *   orchestrateur interroge pour decider de redemarrer un conteneur, et elle
 *   doit rester aussi legere que possible ;
 * - `/sante` dit que le SERVICE est en mesure de travailler. Une application
 *   qui demarre parfaitement mais ne joint plus PostgreSQL repond 200 sur la
 *   premiere et 503 ici — et c'est ici qu'est la verite utile.
 *
 * 503 ET NON 500 quand un service est tombe : le code doit dire « indisponible,
 * revenez » et non « cette page a un bug ». Les deux se traitent
 * differemment, en supervision comme derriere un repartiteur de charge.
 *
 * PUBLIQUE ET SANS AUTHENTIFICATION, parce qu'un moniteur externe n'a pas de
 * session — mais elle ne rend que des libelles et des etats, jamais un
 * identifiant, une adresse de serveur ou un secret. Le detail d'une panne est
 * tronque par `HealthCheck` pour la meme raison.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $services = $health->services();

        $healthy = collect($services)->every(fn (array $service) => $service['ok']);

        return response()->json(
            [
                'status' => $healthy ? 'ok' : 'degraded',
                'services' => $services,
                'time' => now()->toIso8601String(),
            ],
            $healthy ? 200 : 503,
        );
    }
}
