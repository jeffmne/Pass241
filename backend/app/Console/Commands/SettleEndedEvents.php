<?php

namespace App\Console\Commands;

use App\Actions\Settlements\SettleFunds;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Event;
use App\Services\Settlement\Settlable;
use App\Services\Settlement\SettlementLedger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reverse le net des activites terminees (section 21.2, decisions D-026, D-041).
 *
 * Les evenements passes ET les collectes closes : depuis que les dons
 * s'encaissent, ils doivent aussi se reverser, et par le meme chemin.
 *
 * Balayage planifie plutot que job differe pose a la creation, et ce choix
 * compte : une date d'evenement se modifie, un worker redemarre, une file se
 * vide. Un job programme des semaines a l'avance survit mal a tout cela, et un
 * reversement oublie ne se remarque que le jour ou l'organisateur telephone. Un
 * balayage regulier rattrape tout ce qui a ete manque, sans jamais payer deux
 * fois — l'unicite est garantie en base.
 */
class SettleEndedEvents extends Command
{
    protected $signature = 'pass241:settle-events
                            {--limit=200 : Nombre maximal d\'objets traites}
                            {--dry-run : Affiche sans rien envoyer}';

    protected $description = 'Reverse aux organisateurs le net des evenements termines et des collectes closes';

    public function handle(SettleFunds $settle, SettlementLedger $ledger): int
    {
        // Zero quand le prestataire sait programmer un virement : l'ordre part
        // des la fin de l'activite, avec une date d'execution future, et les
        // fonds restent dans SES livres jusque-la (section 21.2).
        $delay = $ledger->instructionDelayHours();
        $limit = (int) $this->option('limit');

        $settlables = $this->endedEvents($delay, $limit)
            ->merge($this->closedCampaigns($delay, $limit));

        if ($settlables->isEmpty()) {
            $this->info('Rien a reverser.');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;

        foreach ($settlables as $settlable) {
            if ($this->option('dry-run')) {
                $this->line(sprintf('  %s — %s', $settlable->slug, $settlable->name));
                $sent++;

                continue;
            }

            try {
                $settlement = $settle->handle($settlable);
            } catch (Throwable $e) {
                // Un objet en echec ne doit pas arreter les autres : le suivant
                // attend son argent lui aussi.
                report($e);
                $this->error(sprintf('  %s : %s', $settlable->slug, $e->getMessage()));
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

    /** @return Collection<int, Settlable&Model> */
    private function endedEvents(int $delay, int $limit): Collection
    {
        return Event::query()
            // Hors portee d'organisation : une commande console n'appartient a
            // aucune organisation, et doit toutes les traiter.
            ->acrossOrganizations()
            ->with(['organization'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now()->subHours($delay))
            ->whereNotExists($this->alreadySettled('events'))
            ->orderBy('ends_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Collectes closes : terminees, annulees ou echues.
     *
     * Une collecte ENCORE OUVERTE n'est jamais reversee, meme si elle a atteint
     * son objectif : elle peut recevoir un don a la minute suivante, et le
     * produit ne prevoit qu'un seul virement par objet (decision D-045).
     *
     * @return Collection<int, Settlable&Model>
     */
    private function closedCampaigns(int $delay, int $limit): Collection
    {
        return Campaign::query()
            ->acrossOrganizations()
            ->with(['organization'])
            ->whereIn('status', [
                CampaignStatus::Completed->value,
                CampaignStatus::Cancelled->value,
                CampaignStatus::Expired->value,
            ])
            // La date de cloture depend du statut : la comparaison la plus sure
            // est celle du plus recent des trois marqueurs.
            ->whereRaw(
                'COALESCE(completed_at, cancelled_at, ends_at) <= ?',
                [now()->subHours($delay)]
            )
            ->whereNotExists($this->alreadySettled('campaigns'))
            ->orderByRaw('COALESCE(completed_at, cancelled_at, ends_at)')
            ->limit($limit)
            ->get();
    }

    /**
     * Rien a faire pour un objet deja reverse.
     *
     * La sous-requete evite de recharger chaque jour tout l'historique de la
     * plateforme. Les etats retenus sont ceux qui interdisent une nouvelle
     * tentative : seul un echec explicite en autorise une.
     */
    private function alreadySettled(string $table): callable
    {
        $morph = $table === 'events' ? Event::class : Campaign::class;

        return fn ($query) => $query
            ->selectRaw('1')
            ->from('settlements')
            ->whereColumn('settlements.settlable_id', $table.'.id')
            ->where('settlements.settlable_type', (new $morph)->getMorphClass())
            ->whereIn('settlements.status', ['SCHEDULED', 'SUCCEEDED', 'PENDING', 'UNKNOWN']);
    }
}
