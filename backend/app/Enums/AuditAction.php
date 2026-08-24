<?php

namespace App\Enums;

/**
 * Actions journalisees (section 68).
 *
 * La liste couvre l'ensemble du produit, y compris des actions dont le module
 * n'est pas encore developpe : la nomenclature est fixee une fois pour toutes
 * afin que les journaux restent comparables dans le temps, et qu'un module
 * ajoute plus tard n'invente pas son propre vocabulaire.
 */
enum AuditAction: string
{
    // Authentification et acces
    case Login = 'LOGIN';
    case Logout = 'LOGOUT';
    case LoginFailed = 'LOGIN_FAILED';

    // Organisation
    case OrganizationCreated = 'ORGANIZATION_CREATED';
    case OrganizationUpdated = 'ORGANIZATION_UPDATED';
    case OrganizationSuspended = 'ORGANIZATION_SUSPENDED';
    case OrganizationContextSwitched = 'ORGANIZATION_CONTEXT_SWITCHED';
    case MemberAdded = 'MEMBER_ADDED';
    case MemberRevoked = 'MEMBER_REVOKED';
    case RoleAssigned = 'ROLE_ASSIGNED';
    case RoleRevoked = 'ROLE_REVOKED';

    // Verification d'identite (section 10.1)
    case KycSubmitted = 'KYC_SUBMITTED';
    case KycVerified = 'KYC_VERIFIED';
    case KycRejected = 'KYC_REJECTED';

    // Evenements
    case EventCreated = 'EVENT_CREATED';
    case EventUpdated = 'EVENT_UPDATED';
    case EventPublished = 'EVENT_PUBLISHED';
    case EventCancelled = 'EVENT_CANCELLED';

    // Billetterie
    case TicketCreated = 'TICKET_CREATED';
    case TicketUsed = 'TICKET_USED';
    case TicketRefunded = 'TICKET_REFUNDED';

    // Paiements
    case PaymentCreated = 'PAYMENT_CREATED';
    case PaymentConfirmed = 'PAYMENT_CONFIRMED';
    case PaymentFailed = 'PAYMENT_FAILED';
    case PaymentRefunded = 'PAYMENT_REFUNDED';

    // Collectes
    case DonationCreated = 'DON_CREATED';
    case DonationConfirmed = 'DON_CONFIRMED';
    case DonationFailed = 'DON_FAILED';
    case DonationRefunded = 'DON_REFUNDED';

    // Controle d'acces
    case AgentCreated = 'AGENT_CREATED';
    case AgentDisabled = 'AGENT_DISABLED';

    // Campagnes
    case CampaignCreated = 'CAMPAIGN_CREATED';
    case CampaignUpdated = 'CAMPAIGN_UPDATED';
    case CampaignPublished = 'CAMPAIGN_PUBLISHED';
    case CampaignPaused = 'CAMPAIGN_PAUSED';
    case CampaignCompleted = 'CAMPAIGN_COMPLETED';

    // Exports
    case ExportGenerated = 'EXPORT_GENERATED';
}
