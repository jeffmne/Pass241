<?php

namespace App\Enums;

/**
 * Roles portes par une organisation (section 10).
 *
 * SUPER_ADMIN n'apparait pas ici : c'est un fait de plateforme porte par
 * `users.is_super_admin`, volontairement hors du systeme de roles scopes, pour
 * qu'aucune attribution de role dans une organisation ne puisse accorder un
 * acces global (section 58).
 *
 * CUSTOMER n'apparait pas non plus : l'achat d'un billet se fait sans
 * inscription (section 15), l'acheteur retrouve ses billets par lien signe
 * (decision D-004). Il n'est donc pas membre d'une organisation.
 */
enum RoleName: string
{
    case Organizer = 'ORGANIZER';
    case CoOrganizer = 'CO_ORGANIZER';
    case Manager = 'MANAGER';
    case AccessAgent = 'ACCESS_AGENT';

    public function label(): string
    {
        return match ($this) {
            self::Organizer => 'Organisateur',
            self::CoOrganizer => 'Co-organisateur',
            self::Manager => 'Gestionnaire',
            self::AccessAgent => 'Agent de controle',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Organizer => 'Proprietaire du compte. Acces complet, y compris aux finances.',
            self::CoOrganizer => 'Gestion operationnelle deleguee. Sans acces aux finances par defaut.',
            self::Manager => 'Utilisateur secondaire aux permissions definies par l\'organisateur.',
            self::AccessAgent => 'Controle d\'acces sur l\'application mobile uniquement.',
        };
    }

    /** @return list<string> */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Organizer => PermissionName::all(),

            // Section 10 : le co-organisateur gere l'operationnel, mais ne voit
            // pas les finances, ne gere pas les acces des autres utilisateurs
            // et ne supprime rien.
            self::CoOrganizer => [
                PermissionName::EventsView->value,
                PermissionName::EventsManage->value,
                PermissionName::TicketingManage->value,
                PermissionName::AttendeesView->value,
                PermissionName::InvitationsManage->value,
                PermissionName::PromoCodesManage->value,
                PermissionName::AccessControlManage->value,
                PermissionName::CampaignsView->value,
                // En lecture seule, comme les collectes : ouvrir ou cloturer un
                // scrutin decide de qui gagne un concours, et engage
                // l'organisateur devant son public.
                PermissionName::VotesView->value,
            ],

            self::Manager => [
                PermissionName::EventsView->value,
                PermissionName::EventsManage->value,
                PermissionName::TicketingManage->value,
                PermissionName::AttendeesView->value,
                PermissionName::InvitationsManage->value,
                PermissionName::PromoCodesManage->value,
                PermissionName::AccessControlManage->value,
                PermissionName::CampaignsView->value,
                PermissionName::CampaignsManage->value,
                PermissionName::VotesView->value,
                PermissionName::VotesManage->value,
                PermissionName::FinancesView->value,
                PermissionName::MembersManage->value,
                PermissionName::ExportsGenerate->value,
            ],

            self::AccessAgent => [
                PermissionName::AccessControlScan->value,
            ],
        };
    }
}
