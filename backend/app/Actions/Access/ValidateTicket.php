<?php

namespace App\Actions\Access;

use App\Enums\AuditAction;
use App\Enums\ScanResult;
use App\Enums\TicketStatus;
use App\Models\AccessScan;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Ticketing\TicketTokenService;
use App\Support\Tenancy\OrganizationContext;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Controle d'un billet a l'entree (sections 28, 31.1).
 *
 * C'est le point ou l'on decide si quelqu'un entre. Trois garanties tiennent
 * ensemble, et aucune ne peut sauter :
 *
 * 1. UN SEUL PASSAGE PAR BILLET. La lecture du billet se fait SOUS VERROU :
 *    deux agents qui scannent le meme QR au meme instant a deux portes
 *    differentes ne peuvent pas le faire passer deux fois. Sans le verrou, les
 *    deux liraient « actif » avant que l'un n'ecrive.
 *
 * 2. LE SCAN LE PLUS ANCIEN GAGNE, quel que soit l'ordre d'arrivee sur le
 *    reseau (section 31.1). Un agent hors connexion qui synchronise une heure
 *    plus tard ne perd pas son anteriorite. C'est ce qui rend le mode hors
 *    ligne utilisable a une vraie entree.
 *
 * 3. TOUT EST JOURNALISE, refus compris. C'est le refus qui a le plus de
 *    valeur le lendemain, quand quelqu'un affirme s'etre vu refuser l'entree
 *    avec un billet valide.
 *
 * L'action ne fait jamais confiance a l'application : le jeton est verifie par
 * signature, l'evenement est celui de la session de l'agent, et le billet est
 * relu en base. Un telephone d'agent est un appareil qu'on peut perdre.
 */
class ValidateTicket
{
    public function __construct(
        private readonly TicketTokenService $tokens,
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * @param  string  $token  contenu brut du QR Code
     * @param  CarbonInterface|null  $scannedAt  horodatage LOCAL de l'appareil
     */
    public function handle(
        Event $event,
        string $token,
        ?User $agent = null,
        ?int $entryPointId = null,
        ?string $deviceId = null,
        ?CarbonInterface $scannedAt = null,
        ?int $clockOffsetSeconds = null,
    ): AccessScan {
        $scannedAt ??= now();

        return $this->context->runFor($event->organization, function () use (
            $event, $token, $agent, $entryPointId, $deviceId, $scannedAt, $clockOffsetSeconds
        ) {
            $verified = $this->tokens->verify($token);

            // Signature invalide ou jeton mal forme : on ne cherche meme pas en
            // base. Le scan est journalise sans billet — c'est le cas le plus
            // interessant, il signale une contrefacon.
            $ticket = $verified === null
                ? null
                : Ticket::query()->acrossOrganizations()->find($verified->ticketId);

            return $this->decide(
                $event, $ticket, false, $agent, $entryPointId, $deviceId, $scannedAt, $clockOffsetSeconds
            );
        });
    }

    /**
     * Admission a la reference, QR illisible (section 27.15).
     *
     * Ecran casse, papier trempe, impression trop claire : le billet existe, le
     * QR ne se lit pas. L'agent retrouve la reference et laisse entrer.
     *
     * TOUTES LES GARANTIES DU SCAN RESTENT EN PLACE — verrou, arbitrage du
     * double scan, journalisation, idempotence. Une seule chose change, et elle
     * est enregistree : la preuve. Un scan prouve la signature, une admission
     * manuelle ne prouve qu'une reference lue a l'oeil. C'est pourquoi le scan
     * porte `manual`, et pourquoi l'audit le dit.
     *
     * La reference n'ouvre RIEN de plus qu'un jeton : billet d'un autre
     * evenement, rembourse, annule ou deja passe se refusent exactement pareil.
     * Elle evite seulement d'avoir a lire un QR abime.
     */
    public function handleByReference(
        Event $event,
        string $reference,
        ?User $agent = null,
        ?int $entryPointId = null,
        ?string $deviceId = null,
        ?CarbonInterface $scannedAt = null,
        ?int $clockOffsetSeconds = null,
    ): AccessScan {
        $scannedAt ??= now();

        return $this->context->runFor($event->organization, function () use (
            $event, $reference, $agent, $entryPointId, $deviceId, $scannedAt, $clockOffsetSeconds
        ) {
            /*
             * Correspondance EXACTE, a la casse et aux espaces pres.
             *
             * Pas de recherche approchante ici : c'est la recherche
             * (`TicketSearchController`) qui tolere une saisie partielle et
             * propose des candidats. Le geste qui fait entrer quelqu'un, lui,
             * designe un billet precis — une approximation y ferait entrer le
             * voisin de reference.
             */
            $ticket = Ticket::query()
                ->acrossOrganizations()
                ->where('reference', mb_strtoupper(trim($reference)))
                ->first();

            return $this->decide(
                $event, $ticket, true, $agent, $entryPointId, $deviceId, $scannedAt, $clockOffsetSeconds
            );
        });
    }

    /**
     * Tronc commun aux deux chemins d'entree.
     *
     * Volontairement partage : un billet rembourse doit se refuser de la meme
     * facon qu'il ait ete scanne ou saisi. Deux implementations finiraient par
     * diverger, et la divergence tomberait du cote de la saisie manuelle — le
     * chemin le moins verifie, donc celui qu'on ne veut surtout pas plus
     * permissif.
     */
    private function decide(
        Event $event,
        ?Ticket $ticket,
        bool $manual,
        ?User $agent,
        ?int $entryPointId,
        ?string $deviceId,
        CarbonInterface $scannedAt,
        ?int $clockOffsetSeconds,
    ): AccessScan {
        if ($ticket === null) {
            return $this->record($event, null, ScanResult::Invalid, $agent, $entryPointId, $deviceId, $manual, $scannedAt, $clockOffsetSeconds);
        }

        /*
         * Le billet appartient-il a CET evenement ?
         *
         * Un billet authentique d'un autre evenement a une signature valide :
         * sans cette verification, il ouvrirait n'importe quelle porte de la
         * plateforme.
         */
        if ($ticket->event_id !== $event->getKey()) {
            return $this->record($event, $ticket, ScanResult::WrongEvent, $agent, $entryPointId, $deviceId, $manual, $scannedAt, $clockOffsetSeconds);
        }

        return DB::transaction(function () use (
            $event, $ticket, $manual, $agent, $entryPointId, $deviceId, $scannedAt, $clockOffsetSeconds
        ) {
            // Relu SOUS VERROU : c'est ce qui empeche deux portes de
            // laisser passer le meme billet au meme instant.
            $locked = Ticket::query()
                ->acrossOrganizations()
                ->whereKey($ticket->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $refused = match ($locked->status) {
                TicketStatus::Refunded => ScanResult::Refunded,
                TicketStatus::Cancelled, TicketStatus::Expired => ScanResult::Cancelled,
                default => null,
            };

            if ($refused !== null) {
                return $this->record($event, $locked, $refused, $agent, $entryPointId, $deviceId, $manual, $scannedAt, $clockOffsetSeconds);
            }

            if ($locked->status === TicketStatus::Used) {
                return $this->handleAlreadyUsed(
                    $event, $locked, $agent, $entryPointId, $deviceId, $manual, $scannedAt, $clockOffsetSeconds
                );
            }

            $locked->forceFill([
                'status' => TicketStatus::Used->value,
                // L'heure retenue est celle du SCAN, pas celle de la
                // synchronisation : c'est l'heure a laquelle la personne
                // est reellement passee.
                'used_at' => $scannedAt,
            ])->save();

            $scan = $this->record($event, $locked, ScanResult::Accepted, $agent, $entryPointId, $deviceId, $manual, $scannedAt, $clockOffsetSeconds);

            $this->audit->log(
                $manual ? AuditAction::TicketAdmittedManually : AuditAction::TicketUsed,
                $locked,
                [
                    'reference' => $locked->reference,
                    'scanned_at' => $scannedAt->toIso8601String(),
                    'device' => $deviceId,
                ],
                actor: $agent,
                organizationId: $event->organization_id,
            );

            return $scan;
        });
    }

    /**
     * Le billet est deja marque utilise : arbitrage du double scan
     * (section 31.1).
     *
     * Si le scan qui arrive est PLUS ANCIEN que celui qui a fait passer le
     * billet, c'est lui qui gagne — l'ordre d'arrivee sur le reseau ne compte
     * pas. Le scan precedemment accepte est declasse et lie a son vainqueur,
     * pour que l'historique dise ce qui s'est reellement passe plutot que de
     * faire disparaitre l'un des deux passages.
     */
    private function handleAlreadyUsed(
        Event $event,
        Ticket $ticket,
        ?User $agent,
        ?int $entryPointId,
        ?string $deviceId,
        bool $manual,
        CarbonInterface $scannedAt,
        ?int $clockOffsetSeconds,
    ): AccessScan {
        $winner = AccessScan::query()
            ->where('ticket_id', $ticket->getKey())
            ->accepted()
            ->orderBy('scanned_at')
            ->first();

        $arrivingIsOlder = $winner !== null && $scannedAt->lt($winner->scanned_at);

        if (! $arrivingIsOlder) {
            $scan = $this->record($event, $ticket, ScanResult::AlreadyUsed, $agent, $entryPointId, $deviceId, $manual, $scannedAt, $clockOffsetSeconds);

            /*
             * Ne jamais lier un scan a lui-meme.
             *
             * Une file locale retransmise renvoie le scan qui a FAIT PASSER le
             * billet : `record` rend alors la ligne d'origine, deja acceptee.
             * La marquer en conflit avec elle-meme corromprait l'historique du
             * passage le plus important — celui qui a reellement eu lieu.
             */
            if ($winner && $scan->getKey() !== $winner->getKey() && ! $scan->allowedEntry()) {
                $scan->forceFill(['conflict_with' => $winner->getKey()])->save();
            }

            return $scan;
        }

        // Le scan qui arrive est anterieur : il devient le passage officiel.
        $scan = $this->record($event, $ticket, ScanResult::Accepted, $agent, $entryPointId, $deviceId, $manual, $scannedAt, $clockOffsetSeconds);

        $winner->forceFill([
            'result' => ScanResult::ConflictLost->value,
            'conflict_with' => $scan->getKey(),
        ])->save();

        $ticket->forceFill(['used_at' => $scannedAt])->save();

        $this->audit->log(
            AuditAction::TicketUsed,
            $ticket,
            [
                'reference' => $ticket->reference,
                'scanned_at' => $scannedAt->toIso8601String(),
                'conflict' => 'scan anterieur arrive apres synchronisation',
                'superseded_scan' => $winner->reference ?? $winner->getKey(),
            ],
            actor: $agent,
            organizationId: $event->organization_id,
        );

        return $scan;
    }

    /**
     * Ecrit la ligne de journal.
     *
     * Idempotent sur (appareil, billet, horodatage local) : une file locale
     * retransmise apres une coupure ne cree pas de doublons, et l'application
     * peut renvoyer sans risque plutot que de deviner ce qui est passe.
     */
    private function record(
        Event $event,
        ?Ticket $ticket,
        ScanResult $result,
        ?User $agent,
        ?int $entryPointId,
        ?string $deviceId,
        bool $manual,
        CarbonInterface $scannedAt,
        ?int $clockOffsetSeconds,
    ): AccessScan {
        try {
            /*
             * L'insertion vit dans sa propre transaction imbriquee, donc dans
             * un POINT DE SAUVEGARDE.
             *
             * PostgreSQL avorte la transaction entiere des qu'une contrainte
             * est violee : sans ce point, le doublon empoisonnerait la
             * transaction de scan et la lecture de secours juste en dessous
             * echouerait a son tour. C'est exactement ce qui se produirait a
             * une vraie entree, quand une file locale est retransmise.
             */
            return DB::transaction(fn () => AccessScan::create([
                'event_id' => $event->getKey(),
                'ticket_id' => $ticket?->getKey(),
                'entry_point_id' => $entryPointId,
                'agent_id' => $agent?->getKey(),
                'device_id' => $deviceId,
                'manual' => $manual,
                'result' => $result->value,
                'scanned_at' => $scannedAt,
                'clock_offset_seconds' => $clockOffsetSeconds,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Deja recu : on rend la ligne d'origine plutot que d'en creer une
            // seconde. C'est ce qui rend la synchronisation rejouable.
            return AccessScan::query()
                ->where('device_id', $deviceId)
                ->where('ticket_id', $ticket?->getKey())
                ->where('scanned_at', $scannedAt)
                ->firstOrFail();
        }
    }
}
