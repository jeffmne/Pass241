<?php

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Livewire\Dashboard\Events\AccessControl;
use App\Models\AccessScan;
use App\Models\EntryPoint;
use App\Models\Event;
use App\Models\EventAgent;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Ecran de gestion du controle d'acces (sections 29, 30).
 *
 * Ce qui est verifie ici : qu'un organisateur peut equiper son entree en
 * quelques gestes, que rien n'impose une correspondance d'un agent par porte,
 * et qu'aucun identifiant venu du navigateur n'atteint un autre evenement.
 */

beforeEach(function () {
    Mail::fake();
    Notification::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->event = forOrganization($this->organization, fn () => Event::factory()
        ->published()
        ->create(['name' => 'Concert Afro 2026']));

    actAsOrganization($this->organization);
    $this->actingAs($this->owner);

    $this->screen = fn () => Livewire::test(AccessControl::class, ['event' => $this->event]);
});

/*
 * ---------------------------------------------------------------------------
 * Points d'entree
 * ---------------------------------------------------------------------------
 */

it('cree un point d entree', function () {
    ($this->screen)()
        ->set('entryName', 'Entree VIP')
        ->set('entryDescription', 'Cote boulevard')
        ->call('saveEntry')
        ->assertHasNoErrors();

    expect(EntryPoint::query()->where('name', 'Entree VIP')->exists())->toBeTrue();
});

it('refuse deux entrees du meme nom sur un evenement', function () {
    ($this->screen)()->set('entryName', 'Entree principale')->call('saveEntry');

    /*
     * Deux entrees du meme nom rendraient l'historique inexploitable :
     * « qui est passe par l'entree VIP ? » n'aurait plus de reponse unique.
     */
    ($this->screen)()
        ->set('entryName', 'entree PRINCIPALE')
        ->call('saveEntry')
        ->assertHasErrors(['entryName']);

    expect(EntryPoint::query()->count())->toBe(1);
});

it('ferme une entree sans effacer son historique', function () {
    $entry = EntryPoint::create(['event_id' => $this->event->id, 'name' => 'Entree laterale']);

    ($this->screen)()->call('toggleEntry', $entry->id);

    // Fermer plutot que supprimer : les controles deja effectues a cette porte
    // restent lisibles.
    expect($entry->fresh()->is_active)->toBeFalse()
        ->and(EntryPoint::query()->count())->toBe(1);
});

/*
 * ---------------------------------------------------------------------------
 * Agents
 * ---------------------------------------------------------------------------
 */

it('cree un compte agent et affiche ses identifiants une seule fois', function () {
    $component = ($this->screen)()
        ->set('agentName', 'Paul Agent')
        ->set('agentEmail', 'paul.agent@example.test')
        ->call('saveAgent')
        ->assertHasNoErrors();

    $credentials = $component->get('newAgentCredentials');

    /*
     * Le mot de passe est genere et transmis de vive voix : sur ce marche, un
     * agent recrute pour une soiree n'a souvent pas d'adresse qu'il consulte,
     * et attendre qu'il clique sur un lien d'invitation le laisserait dehors.
     */
    expect($credentials['email'])->toBe('paul.agent@example.test')
        ->and($credentials['password'])->not->toBeEmpty();

    // Jamais stocke en clair.
    $agent = User::query()->where('email', 'paul.agent@example.test')->firstOrFail();
    expect($agent->password)->not->toBe($credentials['password']);

    $component->call('dismissCredentials');
    expect($component->get('newAgentCredentials'))->toBeNull();
});

it('reutilise un compte existant sans creer de mot de passe', function () {
    $existing = User::factory()->create(['email' => 'deja@example.test']);

    $component = ($this->screen)()
        ->set('agentName', 'Deja Inscrit')
        ->set('agentEmail', 'deja@example.test')
        ->call('saveAgent')
        ->assertHasNoErrors();

    /*
     * Un agent qui travaille pour deux organisateurs n'a pas deux comptes, et
     * un organisateur qui tient lui-meme la porte s'affecte avec le sien.
     */
    expect($component->get('newAgentCredentials'))->toBeNull()
        ->and(User::query()->where('email', 'deja@example.test')->count())->toBe(1)
        ->and(EventAgent::query()->where('user_id', $existing->id)->exists())->toBeTrue();
});

it('donne a l agent le role de controle dans cette organisation seulement', function () {
    ($this->screen)()
        ->set('agentName', 'Paul Agent')
        ->set('agentEmail', 'paul.agent@example.test')
        ->call('saveAgent');

    $agent = User::query()->where('email', 'paul.agent@example.test')->firstOrFail();

    setPermissionsTeamId($this->organization->getKey());

    expect($agent->hasRole(RoleName::AccessAgent->value))->toBeTrue()
        // Un agent n'accede jamais a l'administration (section 27.2).
        ->and($agent->can(PermissionName::FinancesView->value))->toBeFalse()
        ->and($agent->can(PermissionName::OrganizationManage->value))->toBeFalse();
});

it('accepte plusieurs agents sur une meme entree', function () {
    $entry = EntryPoint::create(['event_id' => $this->event->id, 'name' => 'Entree principale']);

    foreach (['a@example.test', 'b@example.test', 'c@example.test'] as $email) {
        ($this->screen)()
            ->set('agentName', 'Agent '.$email)
            ->set('agentEmail', $email)
            ->set('agentEntryPointId', $entry->id)
            ->call('saveAgent')
            ->assertHasNoErrors();
    }

    /*
     * Rien n'impose une correspondance d'un agent par porte : une entree a
     * fort debit en demande plusieurs.
     */
    expect(EventAgent::query()->where('entry_point_id', $entry->id)->count())->toBe(3);
});

it('affecte un agent sans aucun point d entree', function () {
    ($this->screen)()
        ->set('agentName', 'Agent unique')
        ->set('agentEmail', 'unique@example.test')
        ->call('saveAgent')
        ->assertHasNoErrors();

    /*
     * La plupart des lieux n'ont qu'une porte : l'organisateur n'en declare
     * aucune et affecte simplement ses agents a l'evenement.
     */
    $assignment = EventAgent::query()->firstOrFail();

    expect($assignment->entry_point_id)->toBeNull()
        ->and(EntryPoint::query()->count())->toBe(0);
});

it('reprend l affectation existante au lieu d en creer une seconde', function () {
    $entry = EntryPoint::create(['event_id' => $this->event->id, 'name' => 'Entree VIP']);

    ($this->screen)()
        ->set('agentName', 'Paul')->set('agentEmail', 'paul@example.test')->call('saveAgent');

    ($this->screen)()
        ->set('agentName', 'Paul')->set('agentEmail', 'paul@example.test')
        ->set('agentEntryPointId', $entry->id)->call('saveAgent');

    // La ligne explique pourquoi tel agent a scanne tel billet il y a six
    // mois : on la reprend, on ne la remplace jamais (section 67).
    expect(EventAgent::query()->count())->toBe(1)
        ->and(EventAgent::query()->firstOrFail()->entry_point_id)->toBe($entry->id);
});

it('retire un agent sans effacer ses controles', function () {
    ($this->screen)()
        ->set('agentName', 'Paul')->set('agentEmail', 'paul@example.test')->call('saveAgent');

    $assignment = EventAgent::query()->firstOrFail();

    AccessScan::create([
        'event_id' => $this->event->id,
        'agent_id' => $assignment->user_id,
        'result' => 'ACCEPTED',
        'scanned_at' => now(),
    ]);

    ($this->screen)()->call('revokeAgent', $assignment->id);

    expect($assignment->fresh()->is_active)->toBeFalse()
        ->and($assignment->fresh()->revoked_at)->not->toBeNull()
        ->and(AccessScan::query()->count())->toBe(1);
});

/*
 * ---------------------------------------------------------------------------
 * Isolation et permissions
 * ---------------------------------------------------------------------------
 */

it('refuse une entree appartenant a un autre evenement', function () {
    $other = forOrganization($this->organization, fn () => Event::factory()->published()->create());
    $foreign = EntryPoint::create(['event_id' => $other->id, 'name' => 'Porte etrangere']);

    // Regle 80.4 : un identifiant venu du navigateur ne doit pas affecter un
    // agent a la porte d'un autre evenement.
    expect(fn () => ($this->screen)()
        ->set('agentName', 'Paul')
        ->set('agentEmail', 'paul@example.test')
        ->set('agentEntryPointId', $foreign->id)
        ->call('saveAgent'))
        ->toThrow(ModelNotFoundException::class);

    expect(EventAgent::query()->count())->toBe(0);
});

it('ferme l ecran a qui n a pas la permission de controle', function () {
    $viewer = User::factory()->create();

    forOrganization($this->organization, fn () => $this->organization->members()->create([
        'user_id' => $viewer->id, 'joined_at' => now(),
    ]));

    actAsOrganization($this->organization);
    $viewer->assignRole(RoleName::AccessAgent->value);

    $this->actingAs($viewer);

    // Un agent gere l'entree, il ne gere pas les agents (section 27.2).
    $this->get(route('events.access-control', $this->event))->assertForbidden();
});

it('agrege l activite des agents en une requete', function () {
    ($this->screen)()
        ->set('agentName', 'Paul')->set('agentEmail', 'paul@example.test')->call('saveAgent');

    $assignment = EventAgent::query()->firstOrFail();

    foreach ([['ACCEPTED', 1], ['ACCEPTED', 2], ['ALREADY_USED', 3]] as [$result, $minute]) {
        AccessScan::create([
            'event_id' => $this->event->id,
            'agent_id' => $assignment->user_id,
            'result' => $result,
            'scanned_at' => now()->addMinutes($minute),
        ]);
    }

    $activity = ($this->screen)()->viewData('activity');
    $stat = $activity[$assignment->user_id];

    /*
     * « Consulter l'activite de l'agent » (section 27.2). Les refus comptent
     * autant que les entrees : un agent qui refuse beaucoup signale un
     * probleme a sa porte.
     */
    expect((int) $stat->total)->toBe(3)
        ->and((int) $stat->accepted)->toBe(2);
});
