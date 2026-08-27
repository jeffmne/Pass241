<?php

use App\Actions\Invitations\CreateInvitations;
use App\Enums\InvitationStatus;
use App\Enums\RoleName;
use App\Livewire\Dashboard\Events\Invitations as InvitationsScreen;
use App\Livewire\Public\InvitationPage;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Ecrans d'invitation (section 25).
 *
 * Deux surfaces, deux publics : l'organisateur qui colle une liste de noms, et
 * l'invite qui recoit un lien dans un message et decide en une seconde.
 */

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    [$this->event, $this->ticketType] = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create([
            'name' => 'Concert Afro 2026',
            'starts_at' => now()->addWeeks(2),
        ]);

        return [$event, TicketType::factory()->for($event)->create([
            'name' => 'Standard', 'price' => 10_000, 'quantity' => 20,
        ])];
    });

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $this->screen = fn () => Livewire::test(InvitationsScreen::class, ['event' => $this->event]);

    $this->invite = fn (array $guests) => forOrganization(
        $this->organization,
        fn () => app(CreateInvitations::class)->handle(
            event: $this->event,
            ticketType: $this->ticketType,
            guests: $guests,
            expiresAt: now()->addDays(3),
            actor: $this->owner,
        )
    );
});

/*
 * ---------------------------------------------------------------------------
 * L'organisateur
 * ---------------------------------------------------------------------------
 */

it('cree des invitations a partir d une liste collee', function () {
    ($this->screen)()
        ->set('ticketTypeId', $this->ticketType->id)
        ->set('guests', "Marie Nzeng\nPaul Obame, paul@exemple.ga\n\nYves Bekale <yves@exemple.ga>")
        ->set('expiresAt', now()->addDays(2)->format('Y-m-d\TH:i'))
        ->call('create')
        ->assertHasNoErrors();

    $invitations = Invitation::query()->get();

    /*
     * Les trois formes qu'on rencontre en copiant depuis un carnet d'adresses
     * ou un message. Les lignes vides sont ignorees plutot que refusees — une
     * liste collee en contient toujours.
     */
    expect($invitations)->toHaveCount(3)
        ->and($invitations->pluck('guest_name')->all())
        ->toEqual(['Marie Nzeng', 'Paul Obame', 'Yves Bekale'])
        ->and($invitations->firstWhere('guest_name', 'Paul Obame')->guest_email)
        ->toBe('paul@exemple.ga')
        ->and($invitations->firstWhere('guest_name', 'Marie Nzeng')->guest_email)
        ->toBeNull();
});

it('affiche les liens a transmettre une fois crees', function () {
    $component = ($this->screen)()
        ->set('ticketTypeId', $this->ticketType->id)
        ->set('guests', 'Marie Nzeng')
        ->set('expiresAt', now()->addDays(2)->format('Y-m-d\TH:i'))
        ->call('create');

    $created = $component->get('lastCreated');

    // C'est par un message que circule une invitation ici : le lien doit etre
    // sous les yeux de l'organisateur au moment ou il vient de le creer.
    expect($created)->toHaveCount(1)
        ->and($created[0]['url'])->toContain('/invitation/');
});

it('refuse une liste vide', function () {
    ($this->screen)()
        ->set('ticketTypeId', $this->ticketType->id)
        ->set('guests', "\n \n")
        ->set('expiresAt', now()->addDays(2)->format('Y-m-d\TH:i'))
        ->call('create')
        ->assertHasErrors('guests');

    expect(Invitation::query()->count())->toBe(0);
});

it('refuse une date limite deja passee', function () {
    ($this->screen)()
        ->set('ticketTypeId', $this->ticketType->id)
        ->set('guests', 'Marie Nzeng')
        ->set('expiresAt', now()->subDay()->format('Y-m-d\TH:i'))
        ->call('create')
        ->assertHasErrors('expiresAt');
});

it('refuse un type de billet appartenant a un autre evenement', function () {
    $other = forOrganization($this->organization, function () {
        $event = Event::factory()->published()->create();

        return TicketType::factory()->for($event)->create(['quantity' => 5, 'price' => 1000]);
    });

    // Regle 80.4 : un identifiant venu du navigateur ne doit pas puiser dans
    // le stock d'un autre evenement.
    expect(fn () => ($this->screen)()
        ->set('ticketTypeId', $other->id)
        ->set('guests', 'Marie Nzeng')
        ->set('expiresAt', now()->addDay()->format('Y-m-d\TH:i'))
        ->call('create'))
        ->toThrow(ModelNotFoundException::class);
});

it('annule une invitation depuis l ecran', function () {
    $invitation = ($this->invite)([['name' => 'Marie Nzeng']])->first();

    ($this->screen)()->call('cancel', $invitation->id);

    expect($invitation->fresh()->status)->toBe(InvitationStatus::Cancelled);
});

it('marque une invitation comme transmise', function () {
    $invitation = ($this->invite)([['name' => 'Marie Nzeng']])->first();

    ($this->screen)()->call('markSent', $invitation->id);

    /*
     * La plateforme ne pretend pas avoir envoye quoi que ce soit : elle
     * enregistre que l'organisateur dit l'avoir fait. C'est ce que le statut
     * SENT signifie ici.
     */
    expect($invitation->fresh()->status)->toBe(InvitationStatus::Sent)
        ->and($invitation->fresh()->sent_at)->not->toBeNull();
});

it('ferme l ecran a qui ne peut pas gerer les invitations', function () {
    $agent = User::factory()->create();

    forOrganization($this->organization, fn () => $this->organization->members()->create([
        'user_id' => $agent->id, 'joined_at' => now(),
    ]));

    actAsOrganization($this->organization);
    $agent->assignRole(RoleName::AccessAgent->value);

    $this->actingAs($agent);

    $this->get(route('events.invitations', $this->event))->assertForbidden();
});

/*
 * ---------------------------------------------------------------------------
 * L'invite
 * ---------------------------------------------------------------------------
 */

it('ouvre l invitation par son jeton, sans compte', function () {
    $invitation = ($this->invite)([['name' => 'Marie Nzeng']])->first();

    app(OrganizationContext::class)->forget();
    auth()->logout();

    Livewire::test(InvitationPage::class, ['token' => $invitation->token])
        ->assertOk()
        // Rien ne lui est demande : son nom est deja la, c'est l'organisateur
        // qui l'a invite.
        ->assertSee('Marie Nzeng')
        ->assertSee('Concert Afro 2026');
});

it('emet le billet quand l invite accepte', function () {
    $invitation = ($this->invite)([['name' => 'Marie Nzeng']])->first();

    app(OrganizationContext::class)->forget();
    auth()->logout();

    Livewire::test(InvitationPage::class, ['token' => $invitation->token])
        ->call('accept')
        ->assertRedirect();

    $ticket = Ticket::query()->acrossOrganizations()->firstOrFail();

    expect($ticket->holder_name)->toBe('Marie Nzeng')
        ->and($invitation->fresh()->status)->toBe(InvitationStatus::Accepted);
});

it('ramene au billet quand le lien est rouvert', function () {
    $invitation = ($this->invite)([['name' => 'Marie Nzeng']])->first();

    app(OrganizationContext::class)->forget();
    auth()->logout();

    Livewire::test(InvitationPage::class, ['token' => $invitation->token])->call('accept');

    /*
     * On rouvre son lien le jour de l'evenement pour retrouver son QR :
     * annoncer une erreur serait le pire moment pour le faire.
     */
    Livewire::test(InvitationPage::class, ['token' => $invitation->fresh()->token])
        ->assertSee('Voir mon billet');
});

it('explique une invitation expiree sans emettre de billet', function () {
    $invitation = forOrganization($this->organization, fn () => app(CreateInvitations::class)->handle(
        event: $this->event,
        ticketType: $this->ticketType,
        guests: [['name' => 'Marie Nzeng']],
        expiresAt: now()->addMinutes(5),
        actor: $this->owner,
    )->first());

    $this->travel(6)->minutes();

    app(OrganizationContext::class)->forget();
    auth()->logout();

    Livewire::test(InvitationPage::class, ['token' => $invitation->token])
        ->assertSee('expire')
        ->call('accept');

    expect(Ticket::query()->acrossOrganizations()->count())->toBe(0);
});

it('ne confirme pas l existence d un jeton inconnu', function () {
    app(OrganizationContext::class)->forget();
    auth()->logout();

    // 404 plutot qu'un message : un jeton inconnu ne doit pas apprendre qu'il
    // existe des invitations a deviner.
    $this->get(route('invitations.show', ['token' => 'jeton-invente']))->assertNotFound();
});
