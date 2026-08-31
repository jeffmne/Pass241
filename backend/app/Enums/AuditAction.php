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
    /*
     * Destination de reversement.
     *
     * Journalisee a part, et non confondue avec ORGANIZATION_UPDATED : c'est
     * le changement le plus sensible qu'un organisateur puisse faire. Rediriger
     * les fonds vers un autre numero est le vecteur de fraude central une fois
     * qu'une plateforme sait payer, et cette trace est ce qui permet de le
     * reperer (decision D-025).
     */
    case PayoutDestinationSet = 'PAYOUT_DESTINATION_SET';
    case PayoutDestinationChanged = 'PAYOUT_DESTINATION_CHANGED';

    /*
     * Reversement (section 21.2). Chaque transition est journalisee : c'est
     * de l'argent qui sort, et un virement ne se rappelle pas.
     */
    case SettlementCreated = 'SETTLEMENT_CREATED';
    case SettlementAccepted = 'SETTLEMENT_ACCEPTED';
    case SettlementSucceeded = 'SETTLEMENT_SUCCEEDED';
    case SettlementFailed = 'SETTLEMENT_FAILED';

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

    /*
     * Entree accordee sans lecture du QR (section 27.15).
     *
     * Journalisee a part, et non confondue avec TICKET_USED : la verification
     * y est plus faible — une reference lue a l'oeil, pas une signature. C'est
     * la trace qu'on cherche quand on se demande comment quelqu'un est entre,
     * et la seule facon de voir qu'un agent admet a la main ce qu'il devrait
     * scanner.
     */
    case TicketAdmittedManually = 'TICKET_ADMITTED_MANUALLY';

    // Paiements
    case PaymentCreated = 'PAYMENT_CREATED';
    case PaymentConfirmed = 'PAYMENT_CONFIRMED';
    case PaymentFailed = 'PAYMENT_FAILED';
    case PaymentRefunded = 'PAYMENT_REFUNDED';

    /*
     * Remboursements (section 24.1). Chaque etape du processus de decision est
     * journalisee : c'est ce qui permet d'arbitrer un litige entre un client
     * et un organisateur des mois plus tard.
     */
    case RefundRequested = 'REFUND_REQUESTED';
    case RefundApproved = 'REFUND_APPROVED';
    case RefundRejected = 'REFUND_REJECTED';
    case RefundExpired = 'REFUND_EXPIRED';

    // Collectes
    case DonationCreated = 'DON_CREATED';
    case DonationConfirmed = 'DON_CONFIRMED';
    case DonationFailed = 'DON_FAILED';
    case DonationRefunded = 'DON_REFUNDED';

    /*
     * Invitations (section 25).
     *
     * Journalisees a part des commandes : une invitation est une place offerte,
     * pas une vente. C'est ce qui permet de repondre, six mois plus tard, a la
     * question « qui a fait entrer cette personne gratuitement ? ».
     */
    case InvitationCreated = 'INVITATION_CREATED';
    case InvitationSent = 'INVITATION_SENT';
    case InvitationAccepted = 'INVITATION_ACCEPTED';
    case InvitationCancelled = 'INVITATION_CANCELLED';
    case InvitationExpired = 'INVITATION_EXPIRED';

    // Controle d'acces
    case AgentCreated = 'AGENT_CREATED';
    case AgentDisabled = 'AGENT_DISABLED';

    // Campagnes
    case CampaignCreated = 'CAMPAIGN_CREATED';
    case CampaignUpdated = 'CAMPAIGN_UPDATED';
    case CampaignPublished = 'CAMPAIGN_PUBLISHED';
    case CampaignPaused = 'CAMPAIGN_PAUSED';
    case CampaignCompleted = 'CAMPAIGN_COMPLETED';
    case CampaignCancelled = 'CAMPAIGN_CANCELLED';

    /*
     * Votes (sections 86.4, 86.5).
     *
     * Le candidat a ses propres entrees, contrairement aux billets d'un
     * evenement : ajouter ou desactiver un candidat en cours de scrutin CHANGE
     * ce sur quoi le public vote. C'est un geste que l'organisateur doit pouvoir
     * justifier, et donc un geste dont il doit rester une trace.
     */
    case PollCreated = 'POLL_CREATED';
    case PollUpdated = 'POLL_UPDATED';
    case PollPublished = 'POLL_PUBLISHED';
    case PollClosed = 'POLL_CLOSED';
    case PollCancelled = 'POLL_CANCELLED';
    case PollCategorySaved = 'POLL_CATEGORY_SAVED';
    case CandidateSaved = 'CANDIDATE_SAVED';
    case CandidateDisabled = 'CANDIDATE_DISABLED';

    /*
     * Le bulletin paye a ses propres entrees, distinctes de PaymentConfirmed.
     *
     * Un vote paye est un encaissement ET une voix. Le confondre avec un
     * paiement ordinaire ferait perdre, dans le journal, la seule information
     * qui compte en cas de contestation : sur quel candidat cet argent a-t-il
     * pese. Le vote gratuit, lui, n'a rien a journaliser — il n'engage aucun
     * argent, et un journal qui grossit d'une ligne par clic ne se lit plus.
     */
    case BallotCreated = 'BALLOT_CREATED';
    case BallotConfirmed = 'BALLOT_CONFIRMED';
    case BallotFailed = 'BALLOT_FAILED';

    // Exports
    case ExportGenerated = 'EXPORT_GENERATED';

    /*
     * Reglages de la plateforme (section 58).
     *
     * Journalise avec l'ancienne ET la nouvelle valeur : un taux de commission
     * qui bouge sans trace serait indefendable le jour ou un organisateur
     * conteste son reversement.
     */
    case PlatformSettingChanged = 'PLATFORM_SETTING_CHANGED';

    /**
     * Libelle lisible, affiche dans le journal d'audit (section 58).
     *
     * Ecrit a la main plutot que derive de la valeur : « PAYOUT_DESTINATION_
     * CHANGED » se transforme mal en francais, et c'est un ecran ou l'on
     * cherche une anomalie en parcourant vite — la formulation compte.
     */
    public function label(): string
    {
        return match ($this) {
            self::Login => 'Connexion',
            self::Logout => 'Deconnexion',
            self::LoginFailed => 'Echec de connexion',

            self::OrganizationCreated => 'Organisation creee',
            self::OrganizationUpdated => 'Organisation modifiee',
            self::OrganizationSuspended => 'Organisation suspendue',
            self::OrganizationContextSwitched => 'Changement d\'organisation',
            self::MemberAdded => 'Membre ajoute',
            self::MemberRevoked => 'Membre revoque',
            self::RoleAssigned => 'Role attribue',
            self::RoleRevoked => 'Role retire',

            self::PayoutDestinationSet => 'Numero de reversement enregistre',
            self::PayoutDestinationChanged => 'Numero de reversement modifie',

            self::SettlementCreated => 'Reversement cree',
            self::SettlementAccepted => 'Reversement accepte',
            self::SettlementSucceeded => 'Reversement effectue',
            self::SettlementFailed => 'Reversement echoue',

            self::KycSubmitted => 'Pieces transmises',
            self::KycVerified => 'Identite verifiee',
            self::KycRejected => 'Verification refusee',

            self::EventCreated => 'Evenement cree',
            self::EventUpdated => 'Evenement modifie',
            self::EventPublished => 'Evenement publie',
            self::EventCancelled => 'Evenement annule',

            self::TicketCreated => 'Billet emis',
            self::TicketUsed => 'Billet scanne',
            self::TicketRefunded => 'Billet rembourse',
            self::TicketAdmittedManually => 'Entree accordee a la reference',

            self::PaymentCreated => 'Commande creee',
            self::PaymentConfirmed => 'Paiement confirme',
            self::PaymentFailed => 'Paiement echoue',
            self::PaymentRefunded => 'Paiement rembourse',

            self::RefundRequested => 'Remboursement demande',
            self::RefundApproved => 'Remboursement accepte',
            self::RefundRejected => 'Remboursement refuse',
            self::RefundExpired => 'Demande fermee sans reponse',

            self::DonationCreated => 'Don cree',
            self::DonationConfirmed => 'Don confirme',
            self::DonationFailed => 'Don echoue',
            self::DonationRefunded => 'Don rembourse',

            self::InvitationCreated => 'Invitation creee',
            self::InvitationSent => 'Invitation envoyee',
            self::InvitationAccepted => 'Invitation acceptee',
            self::InvitationCancelled => 'Invitation annulee',
            self::InvitationExpired => 'Invitation expiree',

            self::AgentCreated => 'Agent cree',
            self::AgentDisabled => 'Agent desactive',

            self::CampaignCreated => 'Campagne creee',
            self::CampaignUpdated => 'Campagne modifiee',
            self::CampaignPublished => 'Campagne publiee',
            self::CampaignPaused => 'Campagne suspendue',
            self::CampaignCompleted => 'Campagne terminee',
            self::CampaignCancelled => 'Campagne annulee',
            self::PollCreated => 'Vote cree',
            self::PollUpdated => 'Vote modifie',
            self::PollPublished => 'Vote publie',
            self::PollClosed => 'Vote cloture',
            self::PollCancelled => 'Vote annule',
            self::PollCategorySaved => 'Categorie de vote enregistree',
            self::CandidateSaved => 'Candidat enregistre',
            self::CandidateDisabled => 'Candidat desactive',
            self::BallotCreated => 'Vote paye engage',
            self::BallotConfirmed => 'Vote paye confirme',
            self::BallotFailed => 'Vote paye echoue',

            self::ExportGenerated => 'Export genere',
            self::PlatformSettingChanged => 'Reglage de la plateforme modifie',
        };
    }

    /**
     * Famille de l'action, pour teinter la ligne du journal.
     *
     * Les actions FINANCIERES se distinguent des autres : ce sont celles qu'on
     * cherche quand on remonte un litige.
     */
    public function isFinancial(): bool
    {
        return in_array($this, [
            self::PayoutDestinationSet, self::PayoutDestinationChanged,
            self::SettlementCreated, self::SettlementAccepted,
            self::SettlementSucceeded, self::SettlementFailed,
            self::PaymentCreated, self::PaymentConfirmed,
            self::PaymentFailed, self::PaymentRefunded,
            self::RefundRequested, self::RefundApproved,
            self::RefundRejected, self::RefundExpired,
            self::DonationCreated, self::DonationConfirmed,
            self::DonationFailed, self::DonationRefunded,
            // Un vote paye engage de l'argent : il appartient au journal
            // financier au meme titre qu'un don.
            self::BallotCreated, self::BallotConfirmed, self::BallotFailed,
        ], true);
    }
}
