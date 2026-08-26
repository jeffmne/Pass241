<?php

namespace App\Console\Commands;

use App\Actions\Settlements\SettleEvent;
use App\Models\Event;
use App\Services\Settlement\SettlementLedger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reverse le net des evenements termines (section 21.2, decision D-026).
 *
 * Balayage planifie plutot que job differe pose a la creation de l'evenement,
 * et ce choix compte : une date d'evenement se modifie, un worker redemarre,
 * une file se vide. Un job programme des semaines a l'avance survit mal a tout
 * cela, et un reversement oublie ne se remarque que le jour ou l'organisateur
 * telephone. Un balayage quotidien rattrape tout ce qui a ete manque, sans
 * jamais payer deux fois — l'unicite est garantie en base.
 */
class SettleEndedEvents extends Command
{
    protected $signature = 'pass241:settle-events
                            {--limit=200 : Nombre maximal d\'evenements traites}
                            {--dry-run : Affiche sans rien envoyer}';

    protected $description = 'Reverse aux organisateurs le net des evenements termines';

    public function handle(SettleEvent $settle, SettlementLedger $ledger): int
    {
        // Zero quand le prestataire sait programmer un virement : l'ordre part
        // des la fin de l'evenement, avec une date d'execution future, et les
        // fonds restent dans SES livres jusque-la (section 21.2).
        $delay = $ledger->instructionDelayHours();

        $events = Event::query()
            // Hors portee d'organisation : une commande console n'appartient a
            // aucune organisation, et doit toutes les traiter.
            ->acrossOrganizations()
            ->with(['organization'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now()->subHours($delay))
            // Rien a faire pour un evenement deja reverse : la jointure evite
            // de recharger chaque jour tout l'historique de la plateforme.
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('settlements')
                ->whereColumn('settlements.event_id', 'events.id')
                ->whereIn('settlements.status', ['SCHEDULED', 'SUCCEEDED', 'PENDING', 'UNKNOWN'])
            )
            ->orderBy('ends_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($events->isEmpty()) {
            $this->info('Aucun evenement a reverser.');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;

        foreach ($events as $event) {
            if ($this->option('dry-run')) {
                $this->line(sprintf('  %s — %s', $event->reference ?? $event->slug, $event->name));
                $sent++;

                continue;
            }

            try {
                $settlement = $settle->handle($event);
            } catch (Throwable $e) {
                // Un evenement en echec ne doit pas arreter les autres : le
                // suivant attend son argent lui aussi.
                report($e);
                $this->error(sprintf('  %s : %s', $event->slug, $e->getMessage()));
                $skipped++;

                continue;
            }

            if ($settlement === null) {
                $skipped++;

                continue;
            }

            $sent++;
            $this->line(sprintf(
                '  %s — %s (%s)',
                $settlement->reference,
                $settlement->formattedNet(),
                $settlement->status->label(),
            ));
        }

        $this->info(sprintf('%d reversement(s) traite(s), %d ignore(s).', $sent, $skipped));

        return self::SUCCESS;
    }
}
