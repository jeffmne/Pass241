<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\CommissionType;
use App\Enums\ExportFormat;
use App\Enums\PermissionName;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Poll;
use App\Services\Audit\AuditLogger;
use App\Services\Export\Exportable;
use App\Services\Export\Exports\CampaignsExport;
use App\Services\Export\Exports\DonationsExport;
use App\Services\Export\Exports\OrdersExport;
use App\Services\Export\Exports\ParticipantsExport;
use App\Services\Export\Exports\PollResultsExport;
use App\Services\Export\Exports\ScansExport;
use App\Services\Export\Exports\TicketingStatisticsExport;
use App\Services\Export\Exports\TransactionsExport;
use App\Services\Export\ExportWriter;
use App\Services\Finance\TransactionLedger;
use App\Services\Fundraising\CampaignProgress;
use App\Services\Statistics\TicketingStatistics;
use App\Services\Voting\VotingStatistics;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Telechargement des exports (section 59).
 *
 * DEUX PERMISSIONS SONT EXIGEES, et ce n'est pas une precaution redondante :
 *
 * 1. `exports.generate` ouvre la fonctionnalite. Sortir des donnees de la
 *    plateforme est un geste a part — un fichier ne se reprend pas, ne
 *    s'expire pas, et survit a la revocation de l'acces de celui qui l'a
 *    telecharge ;
 * 2. la permission propre a l'export ouvre LES DONNEES. Un export contourne
 *    les ecrans : il ne doit jamais sortir ce qu'un ecran refuserait
 *    d'afficher.
 *
 * Le co-organisateur, par exemple, gere l'operationnel sans avoir
 * `exports.generate` (section 10) : il voit la liste des participants a
 * l'ecran, il n'en emporte pas le fichier.
 *
 * CHAQUE TELECHARGEMENT EST JOURNALISE (`EXPORT_GENERATED`, section 68). C'est
 * la seule trace qui restera le jour ou l'on cherchera d'ou vient un fichier
 * de coordonnees.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly ExportWriter $writer,
        private readonly AuditLogger $audit,
    ) {}

    public function participants(Event $event, string $format): StreamedResponse
    {
        return $this->download(new ParticipantsExport($event), $format);
    }

    public function orders(Event $event, string $format): StreamedResponse
    {
        return $this->download(new OrdersExport($event), $format);
    }

    public function scans(Event $event, string $format): StreamedResponse
    {
        return $this->download(new ScansExport($event), $format);
    }

    public function donations(Campaign $campaign, string $format): StreamedResponse
    {
        return $this->download(new DonationsExport($campaign), $format);
    }

    public function campaigns(string $format, CampaignProgress $progress): StreamedResponse
    {
        return $this->download(new CampaignsExport($progress), $format);
    }

    /**
     * Rapport final d'un scrutin (section 86.4).
     *
     * `VotingStatistics` est injecte plutot que resolu dans l'export : celui-ci
     * ne s'en sert que pour les categories PAS ENCORE figees, et le laisser le
     * chercher lui-meme cacherait cette dependance.
     */
    public function pollResults(Poll $poll, string $format, VotingStatistics $statistics): StreamedResponse
    {
        return $this->download(new PollResultsExport($poll, $statistics), $format);
    }

    /**
     * Grand livre des transactions (sections 56, 59).
     *
     * Les deux filtres viennent de l'URL, donc de n'importe ou : un module
     * inconnu retombe sur « tous » et une periode inconnue sur celle par
     * defaut. Aucun des deux ne peut elargir la portee — l'isolation vient du
     * modele, pas de ces parametres (regle 80.4).
     */
    public function transactions(
        Request $request,
        string $format,
        TransactionLedger $ledger,
    ): StreamedResponse {
        return $this->download(
            new TransactionsExport(
                $ledger,
                CommissionType::tryFrom((string) $request->query('module')),
                $ledger->normalizePeriod($request->query('period')),
            ),
            $format,
        );
    }

    /**
     * Statistiques de billetterie (sections 56, 59).
     *
     * L'organisation vient du contexte, jamais d'un parametre : c'est la meme
     * regle que partout ailleurs, et la seule qui tienne (regle 80.4).
     */
    public function ticketingStatistics(
        string $format,
        TicketingStatistics $statistics,
        OrganizationContext $context,
    ): StreamedResponse {
        // Le groupe de routes garantit deja le contexte ; le `abort` couvre le
        // jour ou cette route en sortirait par megarde, plutot que de laisser
        // passer un export sans portee.
        $organization = $context->current() ?? abort(403);

        return $this->download(
            new TicketingStatisticsExport($statistics, $organization),
            $format,
        );
    }

    private function download(Exportable $export, string $format): StreamedResponse
    {
        $format = ExportFormat::tryFrom($format) ?? abort(404);

        $user = auth()->user();

        abort_unless(
            $user?->can(PermissionName::ExportsGenerate->value),
            403,
            'Vous n\'avez pas le droit d\'exporter des donnees.'
        );

        abort_unless(
            $user->can($export->permission()->value),
            403,
            'Vous n\'avez pas acces a ces donnees.'
        );

        /*
         * Journalise AVANT d'envoyer : la reponse est un flux, et le code qui
         * suivrait son ecriture ne s'executerait qu'une fois le fichier parti.
         * Un export interrompu en cours de route doit malgre tout laisser sa
         * trace — c'est meme le cas le plus interessant a retrouver.
         */
        $this->audit->log(
            AuditAction::ExportGenerated,
            null,
            array_merge($export->auditContext(), [
                'export' => $export->label(),
                'format' => $format->value,
                'filename' => $export->filename(),
            ]),
        );

        return $this->writer->stream($export, $format);
    }
}
