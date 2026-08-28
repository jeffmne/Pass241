<?php

namespace App\Enums;

/**
 * Permissions applicables a l'interieur d'une organisation.
 *
 * Deux permissions sont volontairement separees du reste :
 *
 * - `finances.view` — le co-organisateur ne l'a pas par defaut (section 10),
 *   l'organisateur doit l'accorder explicitement.
 * - `finances.refund` — decider d'un remboursement est un acte financier
 *   distinct de la simple consultation (section 24.1).
 *
 * Aucune permission ne permet de modifier une donnee financiere confirmee :
 * cela reste impossible quel que soit le role (section 67).
 */
enum PermissionName: string
{
    case EventsView = 'events.view';
    case EventsManage = 'events.manage';

    case TicketingManage = 'ticketing.manage';
    case AttendeesView = 'attendees.view';

    case InvitationsManage = 'invitations.manage';
    case PromoCodesManage = 'promo_codes.manage';

    case AccessControlManage = 'access_control.manage';
    case AccessControlScan = 'access_control.scan';

    case CampaignsView = 'campaigns.view';
    case CampaignsManage = 'campaigns.manage';

    case VotesView = 'votes.view';
    case VotesManage = 'votes.manage';

    case FinancesView = 'finances.view';
    case FinancesRefund = 'finances.refund';

    case MembersManage = 'members.manage';
    case OrganizationManage = 'organization.manage';
    case ExportsGenerate = 'exports.generate';

    /** @return list<string> */
    public static function all(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::EventsView => 'Consulter les evenements',
            self::EventsManage => 'Creer et modifier les evenements',
            self::TicketingManage => 'Gerer la billetterie',
            self::AttendeesView => 'Consulter les participants',
            self::InvitationsManage => 'Gerer les invitations',
            self::PromoCodesManage => 'Gerer les codes promo',
            self::AccessControlManage => 'Gerer les agents et points d\'entree',
            self::AccessControlScan => 'Scanner les billets',
            self::CampaignsView => 'Consulter les collectes',
            self::CampaignsManage => 'Gerer les collectes',
            self::VotesView => 'Consulter les votes',
            self::VotesManage => 'Gerer les votes',
            self::FinancesView => 'Consulter les finances',
            self::FinancesRefund => 'Decider des remboursements',
            self::MembersManage => 'Gerer les membres de l\'organisation',
            self::OrganizationManage => 'Modifier l\'organisation',
            self::ExportsGenerate => 'Generer des exports',
        };
    }
}
