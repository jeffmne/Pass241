<?php

namespace App\Http\Controllers\Api\Agent;

use App\Enums\ScanResult;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\AccessScan;
use App\Models\Ticket;
use App\Services\Access\AgentAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Tableau de bord agent (section 27.14).
 *
 * L'application tient ses propres compteurs dans sa base locale, et ce sont eux
 * qu'elle affiche hors connexion. Cette route donne autre chose, que le
 * telephone ne peut pas savoir : CE QUE FONT LES AUTRES PORTES.
 *
 * A une entree tenue par quatre agents, la question qui compte n'est pas
 * « combien ai-je scanne » mais « combien de personnes sont entrees » — et la
 * reponse est repartie sur quatre telephones. Le compte local ne la donne
 * jamais.
 *
 * La reponse porte donc les deux : `counters` pour l'entree entiere, `mine`
 * pour l'agent. Le second sert aussi a reconcilier les compteurs locaux apres
 * une synchronisation : un scan accepte sur le telephone a pu perdre un
 * arbitrage entre-temps (section 31.1), et seul le serveur le sait.
 *
 * Tout est agrege en SQL. Un evenement de 100 000 billets se compte en trois
 * requetes, jamais en chargeant des lignes en memoire (section 72).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, int $eventId, AgentAccess $access): JsonResponse
    {
        $agent = $request->user();

        // Resolution et autorisation en un seul geste : du point de vue de
        // l'agent, un evenement qui ne lui est pas affecte n'existe pas.
        $event = $access->eventFor($agent, $eventId);

        $byResult = AccessScan::query()
            ->acrossOrganizations()
            ->where('event_id', $event->getKey())
            ->selectRaw('result, count(*) as total')
            ->groupBy('result')
            ->pluck('total', 'result');

        $mine = AccessScan::query()
            ->acrossOrganizations()
            ->where('event_id', $event->getKey())
            ->where('agent_id', $agent->getKey())
            ->selectRaw('result, count(*) as total')
            ->groupBy('result')
            ->pluck('total', 'result');

        /*
         * Billets attendus a la porte.
         *
         * Rembourses et annules sont exclus : ils ne se presenteront pas, et
         * les compter ferait afficher un taux de presence qui ne peut jamais
         * atteindre la totalite.
         */
        $expected = Ticket::query()
            ->acrossOrganizations()
            ->where('event_id', $event->getKey())
            ->whereIn('status', [TicketStatus::Active->value, TicketStatus::Used->value])
            ->count();

        $admitted = (int) ($byResult[ScanResult::Accepted->value] ?? 0);

        return response()->json([
            'event' => [
                'id' => $event->getKey(),
                'name' => $event->name,
            ],
            'counters' => $this->counters($byResult),
            'mine' => $this->counters($mine),
            'tickets' => [
                'expected' => $expected,
                'admitted' => $admitted,
                'remaining' => max(0, $expected - $admitted),
            ],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Les quatre nombres de la section 27.14, plus le detail.
     *
     * Le detail par code est conserve tel quel : « billet rembourse » et « QR
     * non reconnu » comptent tous deux comme un refus, mais ne racontent pas la
     * meme soiree. L'application affiche les quatre, qui veut le detail l'a.
     *
     * @param  Collection<string, int>  $byResult
     * @return array<string, mixed>
     */
    private function counters(Collection $byResult): array
    {
        $count = fn (ScanResult $result) => (int) ($byResult[$result->value] ?? 0);

        return [
            'scanned' => (int) $byResult->sum(),
            'valid' => $count(ScanResult::Accepted),
            // Un scan declasse par arbitrage est, pour l'agent qui l'a fait, un
            // billet deja passe : c'est ce qu'il a lu a l'ecran.
            'already_used' => $count(ScanResult::AlreadyUsed) + $count(ScanResult::ConflictLost),
            'invalid' => $count(ScanResult::Invalid)
                + $count(ScanResult::Refunded)
                + $count(ScanResult::Cancelled)
                + $count(ScanResult::WrongEvent),
            'by_result' => collect(ScanResult::cases())
                ->mapWithKeys(fn (ScanResult $result) => [$result->value => $count($result)])
                ->all(),
        ];
    }
}
