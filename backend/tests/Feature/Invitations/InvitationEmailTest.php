<?php

use App\Actions\Invitations\CancelInvitation;
use App\Actions\Invitations\CreateInvitations;
use App\Enums\AuditAction;
use App\Enums\InvitationStatus;
use App\Jobs\SendInvitationEmail;
use App\Livewire\Dashboard\Events\Invitations as InvitationsScreen;
use App\Mail\GuestInvited;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\TicketType;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * Envoi du lien d'invitation par email (sections 25, 69).
 *
 * L'email ne remplace pas le canal de l'organisateur — ici, une invitation
 * circule le plus souvent dans un message, et beaucoup d'invites n'ont aucune
 * adresse. Il epargne le copier-coller a ceux qui en ont une, rien de plus.
 *
 * Ce qui se verifie ici : que le lien part, qu'il ne part pas quand la place
 * n'existe plus, que le statut ne ment pas sur ce qui s'est reellement produit,
 * et que le message tient debout depuis un worker qui n'a aucun contexte
 * d'organisation.
 */

beforeEach(function () {
    Mail::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->organization->forceFill(['contact_email' => 'contact@association.test'])->save();

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

    $this->invite = fn (array $guests, bool $sendEmails = true) => forOrganization(
        $this->organization,
        fn () => app(CreateInvitations::class)->handle(
            event: $this->event,
            ticketType: $this->ticketType,
            guests: $guests,
            expiresAt: now()->addDays(3),
            actor: $this->owner,
            sendEmails: $sendEmails,
        )
    );
});

it('envoie le lien aux invites qui ont une adresse, et a eux seuls', function () {
    ($this->invite)([
        ['name' => 'Marie Nzeng'],
        ['name' => 'Paul Obame', 'email' => 'paul@exemple.ga'],
    ]);

    // Une adresse absente n'est pas un probleme a signaler : c'est le cas
    // courant. L'organisateur transmet le lien lui-meme.
    Mail::assertSentCount(1);
    Mail::assertSent(GuestInvited::class, fn ($mail) => $mail->hasTo('paul@exemple.ga'));

    $invitations = Invitation::query()->get();

    expect($invitations->firstWhere('guest_name', 'Paul Obame')->status)
        ->toBe(InvitationStatus::Sent)
        ->and($invitations->firstWhere('guest_name', 'Marie Nzeng')->status)
        ->toBe(InvitationStatus::Created);
});

it('n envoie rien quand l organisateur decoche la case', function () {
    ($this->screen)()
        ->set('ticketTypeId', $this->ticketType->id)
        ->set('guests', 'Paul Obame, paul@exemple.ga')
        ->set('expiresAt', now()->addDays(2)->format('Y-m-d\TH:i'))
        ->set('notifyByEmail', false)
        ->call('create')
        ->assertHasNoErrors();

    /*
     * Preparer ses invitations a l'avance et choisir son moment est un usage
     * reel : la place est retenue, le lien existe, mais rien n'est parti.
     */
    Mail::assertNothingSent();

    expect(Invitation::query()->first()->status)->toBe(InvitationStatus::Created);
});

it('renvoie le lien a la demande de l organisateur', function () {
    $invitation = ($this->invite)([['name' => 'Paul Obame', 'email' => 'paul@exemple.ga']])->first();

    // « Je n'ai rien recu » est la phrase que tout organisateur entend.
    $this->travel(2)->minutes();

    ($this->screen)()->call('sendEmail', $invitation->id);

    Mail::assertSentCount(2);

    expect($invitation->fresh()->sent_at->isAfter(now()->subMinute()))->toBeTrue();
});

it('refuse un renvoi dans la minute qui suit', function () {
    $invitation = ($this->invite)([['name' => 'Paul Obame', 'email' => 'paul@exemple.ga']])->first();

    /*
     * L'organisateur qui ne voit rien arriver reclique. Sans garde-fou, l'invite
     * recoit cinq fois le meme message et l'adresse de la plateforme finit en
     * indesirable.
     */
    ($this->screen)()->call('sendEmail', $invitation->id);

    Mail::assertSentCount(1);
});

it('n envoie rien a un invite sans adresse', function () {
    $invitation = ($this->invite)([['name' => 'Marie Nzeng']])->first();

    ($this->screen)()->call('sendEmail', $invitation->id);

    Mail::assertNothingSent();

    expect($invitation->fresh()->status)->toBe(InvitationStatus::Created);
});

it('n envoie pas le lien d une invitation annulee', function () {
    $invitation = ($this->invite)(
        [['name' => 'Paul Obame', 'email' => 'paul@exemple.ga']],
        sendEmails: false
    )->first();

    app(CancelInvitation::class)->cancel($invitation, $this->owner);

    // Le job peut avoir ete mis en file avant l'annulation : c'est au moment de
    // l'envoi que la place est reverifiee. Un lien vers une place qui n'existe
    // plus se retournerait contre l'organisateur — l'invite se presenterait a
    // une porte qui le refuserait.
    SendInvitationEmail::dispatch($invitation->getKey());

    Mail::assertNothingSent();
});

it('n envoie pas le lien d une invitation echue', function () {
    $invitation = ($this->invite)(
        [['name' => 'Paul Obame', 'email' => 'paul@exemple.ga']],
        sendEmails: false
    )->first();

    $this->travel(4)->days();

    SendInvitationEmail::dispatch($invitation->getKey());

    Mail::assertNothingSent();
});

it('compose le message depuis un worker sans contexte d organisation', function () {
    $invitation = ($this->invite)(
        [['name' => 'Paul Obame', 'email' => 'paul@exemple.ga']],
        sendEmails: false
    )->first();

    /*
     * LE PIEGE QUI S'EST DEJA REFERME PLUSIEURS FOIS : hors contexte, la portee
     * globale masque l'evenement, la relation revient nulle, et le message part
     * sans son nom ni sa date — quand il ne plante pas.
     */
    app(OrganizationContext::class)->forget();
    auth()->logout();

    SendInvitationEmail::dispatch($invitation->getKey());

    Mail::assertSent(GuestInvited::class, function ($mail) use ($invitation) {
        $mail->assertHasSubject('Vous etes invite a Concert Afro 2026');

        // La reponse revient a l'organisateur, seul capable d'y repondre.
        $mail->assertHasReplyTo('contact@association.test');

        $mail->assertSeeInHtml('Paul Obame', false);
        $mail->assertSeeInHtml('Association test', false);
        $mail->assertSeeInHtml($invitation->publicUrl(), false);

        return true;
    });
});

it('journalise l envoi et son canal', function () {
    ($this->invite)([['name' => 'Paul Obame', 'email' => 'paul@exemple.ga']]);

    $log = AuditLog::action(AuditAction::InvitationSent)->firstOrFail();

    /*
     * Le canal est ce qui permet de savoir, six mois plus tard, si un invite
     * qui affirme n'avoir rien recu peut etre cru : « envoyee » par la
     * plateforme et « marquee envoyee » par l'organisateur ne s'opposent pas de
     * la meme facon.
     */
    expect($log->organization_id)->toBe($this->organization->id)
        ->and($log->metadata['channel'])->toBe('email')
        ->and($log->metadata['to'])->toBe('paul@exemple.ga');
});
