<?php

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\OrganizationMember;

/*
 * Roles et permissions (section 10).
 *
 * Le point sensible est la delegation : un CO_ORGANIZER gere l'operationnel
 * mais ne voit pas les finances par defaut. Cette limite fait partie des tests
 * obligatoires listes en section 74.
 */

// `addMemberTo()` vit dans tests/Pest.php : plusieurs suites en ont besoin, et
// une fonction definie dans un fichier de test ne serait chargee que si ce
// fichier l'est — donc absente quand on execute une suite seule.

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization();
    actAsOrganization($this->organization);
});

it('donne toutes les permissions a l organisateur', function () {
    $owner = $this->owner->fresh();

    expect($owner->hasRole(RoleName::Organizer->value))->toBeTrue();

    foreach (PermissionName::cases() as $permission) {
        expect($owner->can($permission->value))->toBeTrue();
    }
});

it('refuse l acces aux finances a un co-organisateur par defaut', function () {
    $delegate = addMemberTo($this->organization, RoleName::CoOrganizer);

    // Section 10 : la delegation porte sur l'operationnel, pas sur l'argent.
    expect($delegate->can(PermissionName::FinancesView->value))->toBeFalse()
        ->and($delegate->can(PermissionName::FinancesRefund->value))->toBeFalse();
});

it('laisse le co-organisateur gerer l operationnel', function () {
    $delegate = addMemberTo($this->organization, RoleName::CoOrganizer);

    expect($delegate->can(PermissionName::EventsManage->value))->toBeTrue()
        ->and($delegate->can(PermissionName::TicketingManage->value))->toBeTrue()
        ->and($delegate->can(PermissionName::InvitationsManage->value))->toBeTrue()
        ->and($delegate->can(PermissionName::AccessControlManage->value))->toBeTrue();
});

it('empeche le co-organisateur de gerer les membres et l organisation', function () {
    $delegate = addMemberTo($this->organization, RoleName::CoOrganizer);

    expect($delegate->can(PermissionName::MembersManage->value))->toBeFalse()
        ->and($delegate->can(PermissionName::OrganizationManage->value))->toBeFalse();
});

it('limite l agent de controle au seul scan', function () {
    $agent = addMemberTo($this->organization, RoleName::AccessAgent);

    expect($agent->can(PermissionName::AccessControlScan->value))->toBeTrue();

    // Section 10 : un agent ne consulte ni revenus ni participants, et ne
    // modifie ni evenement ni billet.
    foreach ([
        PermissionName::FinancesView,
        PermissionName::EventsManage,
        PermissionName::TicketingManage,
        PermissionName::AttendeesView,
        PermissionName::CampaignsManage,
    ] as $forbidden) {
        expect($agent->can($forbidden->value))->toBeFalse();
    }
});

it('affiche les parametres en lecture seule a un co-organisateur', function () {
    $delegate = addMemberTo($this->organization, RoleName::CoOrganizer);

    $this->actingAs($delegate)
        ->get('/parametres')
        ->assertOk()
        ->assertSee('Consultation seule', escape: false);
});

it('ne fait pas fuiter un role d une organisation vers une autre', function () {
    $delegate = addMemberTo($this->organization, RoleName::CoOrganizer);

    [, $otherOrganization] = organizerWithOrganization('Autre organisation');
    OrganizationMember::create([
        'organization_id' => $otherOrganization->id,
        'user_id' => $delegate->id,
        'joined_at' => now(),
    ]);

    actAsOrganization($otherOrganization);

    expect($delegate->fresh()->can(PermissionName::EventsManage->value))->toBeFalse();
});
