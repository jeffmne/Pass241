<?php

namespace App\Http\Controllers\Api\Agent;

use App\Actions\Access\ValidateTicket;
use App\Http\Controllers\Controller;
use App\Models\AccessScan;
use App\Services\Access\AgentAccess;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Remontee de la file de scans (sections 27.11, 31).
 *
 * Recoit un LOT, jamais un scan isole : une porte hors connexion accumule des
 * dizaines de controles et les envoie d'un bloc quand le reseau revient. Un
 * appel par scan multiplierait les allers-retours au moment ou le reseau est
 * le plus mauvais.
 *
 * TROIS PROPRIETES, chacune indispensable a une vraie entree :
 *
 * 1. REJOUABLE. Une synchronisation interrompue est retransmise en entier.
 *    L'unicite (appareil, billet, horodatage local) fait qu'un scan deja recu
 *    rend son resultat d'origine au lieu de creer un doublon.
 *
 * 2. TOLERANTE A L'ECHEC PARTIEL. Un scan qui echoue n'annule pas les autres :
 *    l'application recevrait sinon une erreur globale et retransmettrait
 *    indefiniment un lot dont un seul element pose probleme.
 *
 * 3. C'EST LE SERVEUR QUI TRANCHE. L'application affiche un resultat local
 *    immediat, mais seul le resultat renvoye ici fait foi — c'est lui qui
 *    arbitre les double scans (section 31.1). L'application met a jour son
 *    historique avec la reponse.
 */
class ScanController extends Controller
{
    /** Au-dela, le lot est refuse : une file saine n'atteint jamais ce volume. */
    private const MAX_BATCH = 200;

    public function __invoke(
        Request $request,
        int $eventId,
        AgentAccess $access,
        ValidateTicket $validate,
    ): JsonResponse {
        $agent = $request->user();

        // Du point de vue de l'agent, un evenement qui ne lui est pas affecte
        // n'existe pas.
        $event = $access->eventFor($agent, $eventId);
        $assignment = $access->assignmentFor($agent, $event);

        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:120'],
            'clock_offset_seconds' => ['nullable', 'integer', 'between:-86400,86400'],
            'scans' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            /*
             * Un jeton OU une reference, jamais rien.
             *
             * La reference est le chemin de l'admission manuelle (section
             * 27.15) : quand le QR ne se lit pas, l'agent retrouve le billet
             * autrement, et ce controle doit remonter comme les autres. Sans
             * cette variante, la recherche manuelle ne serait qu'un ecran de
             * consultation et l'entree accordee n'existerait nulle part.
             */
            'scans.*.token' => ['required_without:scans.*.reference', 'nullable', 'string', 'max:512'],
            'scans.*.reference' => ['required_without:scans.*.token', 'nullable', 'string', 'max:64'],
            'scans.*.scanned_at' => ['required', 'date'],
        ], [
            'scans.max' => 'Lot trop volumineux : envoyez-le en plusieurs fois.',
        ]);

        $results = [];

        foreach ($data['scans'] as $index => $entry) {
            try {
                $scannedAt = Carbon::parse($entry['scanned_at']);

                /*
                 * Le jeton prime quand les deux sont presents : c'est la preuve
                 * la plus forte, et l'accepter en priorite evite qu'une
                 * application puisse degrader volontairement la verification en
                 * joignant une reference a un QR parfaitement lisible.
                 *
                 * Le point d'entree vient de l'AFFECTATION, jamais de la
                 * requete : l'application ne doit pas pouvoir se declarer a une
                 * autre porte que celle ou l'organisateur l'a mise.
                 */
                $scan = isset($entry['token'])
                    ? $validate->handle(
                        event: $event,
                        token: $entry['token'],
                        agent: $agent,
                        entryPointId: $assignment->entry_point_id,
                        deviceId: $data['device_id'],
                        scannedAt: $scannedAt,
                        clockOffsetSeconds: $data['clock_offset_seconds'] ?? null,
                    )
                    : $validate->handleByReference(
                        event: $event,
                        reference: $entry['reference'],
                        agent: $agent,
                        entryPointId: $assignment->entry_point_id,
                        deviceId: $data['device_id'],
                        scannedAt: $scannedAt,
                        clockOffsetSeconds: $data['clock_offset_seconds'] ?? null,
                    );

                $results[] = $this->present($index, $scan);
            } catch (Throwable $e) {
                report($e);

                // L'application retentera ce scan seul ; les autres du lot sont
                // deja enregistres et ne seront pas rejoues en double.
                $results[] = [
                    'index' => $index,
                    'accepted' => false,
                    'result' => null,
                    'error' => 'Ce controle n\'a pas pu etre enregistre.',
                ];
            }
        }

        return response()->json([
            'results' => $results,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(int $index, AccessScan $scan): array
    {
        // Portee levee pour le chargement : sans cela le billet revient null
        // et l'agent lit « entree autorisee » sans savoir pour qui.
        app(OrganizationContext::class)->withoutScope(
            fn () => $scan->loadMissing(['ticket.orderItem'])
        );

        return [
            'index' => $index,
            'scan_id' => $scan->getKey(),
            'accepted' => $scan->allowedEntry(),
            'result' => $scan->result->value,
            // Les deux lignes que l'agent lit sur son telephone (section 28).
            'label' => $scan->result->label(),
            'instruction' => $scan->result->instruction(),
            'holder_name' => $scan->ticket?->holder_name,
            'ticket_type' => $scan->ticket?->orderItem?->ticket_type_name,
            'reference' => $scan->ticket?->reference,
            // Le controle a-t-il ete accorde sans lecture du QR ? L'agent doit
            // le voir sur son ecran : c'est la trace de ce qu'il vient de faire.
            'manual' => $scan->manual,
            // Renseigne quand ce scan a perdu un arbitrage de double scan :
            // l'application peut expliquer a l'agent pourquoi son « entree
            // autorisee » est devenue un refus.
            'conflict_with' => $scan->conflict_with,
        ];
    }
}
