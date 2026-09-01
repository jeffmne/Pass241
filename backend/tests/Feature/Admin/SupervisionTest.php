<?php

use App\Enums\PayoutState;
use App\Livewire\Admin\Supervision;
use App\Models\Event;
use App\Models\User;
use App\Services\Platform\HealthCheck;
use App\Services\Platform\SupervisionReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Supervision et sonde de disponibilite (sections 58, 72).
 *
 * CE FICHIER DEFEND UNE SEPARATION, et cette separation decide de qui est
 * reveille la nuit.
 *
 * `/sante` repond a « le service peut-il travailler ? ». Elle s'adresse a un
 * moniteur externe, et une panne y est une urgence.
 *
 * L'ecran de supervision repond a « qu'est-ce qui est reste en chemin ? ». Un
 * email non parti y figure ; il ne doit surtout pas faire sonner la premiere.
 * Une alerte qui se declenche pour rien finit par ne plus etre lue, et c'est
 * alors la vraie panne qu'on rate.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();

    $this->failJob = fn () => DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException: le serveur de messagerie a refuse',
        'failed_at' => now()->subHours(2),
    ]);
});

/*
 * ---------------------------------------------------------------------------
 * La sonde
 * ---------------------------------------------------------------------------
 */

it('repond que le service peut travailler', function () {
    $response = $this->getJson('/sante');

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        // Une vraie requete sur chacun : lire une variable de configuration ne
        // prouverait rien, elle est renseignee que le service reponde ou non.
        ->assertJsonPath('services.database.ok', true)
        ->assertJsonPath('services.cache.ok', true)
        ->assertJsonPath('services.queue.ok', true);
});

it('reste ouverte sans authentification', function () {
    // Un moniteur externe n'a pas de session. La sonde ne rend en echange que
    // des libelles et des etats — jamais un identifiant ni une adresse.
    $this->getJson('/sante')->assertOk();
});

it('repond 503 et non 500 quand un service est tombe', function () {
    /*
     * Le code doit dire « indisponible, revenez » et non « cette page a un
     * bug » : les deux se traitent differemment, en supervision comme derriere
     * un repartiteur de charge.
     */
    $this->mock(HealthCheck::class)
        ->shouldReceive('services')
        ->andReturn([
            'database' => ['ok' => false, 'label' => 'Base de donnees', 'detail' => 'connexion refusee'],
        ]);

    $this->getJson('/sante')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded');
});

it('ne fait pas sonner la sonde pour un traitement en echec', function () {
    /*
     * LE TEST QUI TIENT LA SEPARATION.
     *
     * Un email d'invitation qui ne part pas n'empeche personne d'acheter un
     * billet. Le faire remonter en panne de service reveillerait quelqu'un pour
     * rien — et le jour ou la sonde criera vraiment, plus personne ne
     * l'ecoutera.
     */
    ($this->failJob)();

    $this->getJson('/sante')->assertOk()->assertJsonPath('status', 'ok');

    // Il figure en revanche bien la ou un humain viendra le chercher.
    expect(app(SupervisionReport::class)->incidents()['jobs']['count'])->toBe(1);
});

/*
 * ---------------------------------------------------------------------------
 * Le rapport
 * ---------------------------------------------------------------------------
 */

it('signale un callback de paiement recu et jamais traite', function () {
    /*
     * LA PANNE SILENCIEUSE LA PLUS COUTEUSE : l'argent a quitte le telephone de
     * l'acheteur, l'operateur nous a prevenus, et la commande est restee en
     * attente. Rien dans le produit ne le signale — il faut que l'acheteur
     * ecrive pour qu'on l'apprenne.
     */
    DB::table('payment_webhooks')->insert([
        'provider' => 'PVIT',
        'event_id' => 'EVT-PERDU',
        'signature_valid' => true,
        'payload' => json_encode(['status' => 'SUCCESS']),
        'processed_at' => null,
        'created_at' => now()->subHour(),
    ]);

    $incident = app(SupervisionReport::class)->incidents()['webhooks'];

    expect($incident['count'])->toBe(1)
        ->and($incident['oldest'])->not->toBeNull();
});

it('laisse au traitement le temps de passer avant de crier', function () {
    // Le traitement passe par la file : quelques secondes en marche normale. Un
    // callback arrive a l'instant n'est pas un incident, c'est un callback.
    DB::table('payment_webhooks')->insert([
        'provider' => 'PVIT',
        'event_id' => 'EVT-FRAIS',
        'signature_valid' => true,
        'payload' => json_encode(['status' => 'SUCCESS']),
        'processed_at' => null,
        'created_at' => now(),
    ]);

    expect(app(SupervisionReport::class)->incidents()['webhooks']['count'])->toBe(0);
});

it('ne signale pas un callback deja traite', function () {
    DB::table('payment_webhooks')->insert([
        'provider' => 'PVIT',
        'event_id' => 'EVT-OK',
        'signature_valid' => true,
        'payload' => json_encode(['status' => 'SUCCESS']),
        'processed_at' => now()->subHour(),
        'created_at' => now()->subHours(2),
    ]);

    expect(app(SupervisionReport::class)->incidents()['webhooks']['count'])->toBe(0);
});

it('signale un reversement que personne ne peut trancher a notre place', function () {
    // Rejouer risquerait de payer deux fois, abandonner de ne pas payer du
    // tout : seul un humain decide (section 21.2).
    [$owner, $organization] = organizerWithOrganization('Association test');

    $event = forOrganization($organization, fn () => Event::factory()->published()->create());

    DB::table('settlements')->insert([
        'organization_id' => $organization->getKey(),
        'reference' => 'RV-INCONNU',
        'gross_amount' => 100_000,
        'commission_amount' => 5_000,
        'net_amount' => 95_000,
        'recipient_phone' => '074000000',
        'recipient_operator' => 'AIRTEL',
        'idempotency_key' => 'RV-INCONNU-1',
        'settlable_type' => $event->getMorphClass(),
        'settlable_id' => $event->getKey(),
        'status' => PayoutState::Unknown->value,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    expect(app(SupervisionReport::class)->incidents()['settlements']['count'])->toBe(1);
});

/*
 * ---------------------------------------------------------------------------
 * L'ecran
 * ---------------------------------------------------------------------------
 */

it('annonce qu\'il n\'y a rien a signaler quand tout est calme', function () {
    $this->actingAs($this->admin);

    Livewire::test(Supervision::class)
        ->assertOk()
        ->assertSee('Rien a signaler')
        ->assertSee('Etat des services');
});

it('affiche l\'incident et sa date la plus ancienne', function () {
    ($this->failJob)();

    $this->actingAs($this->admin);

    Livewire::test(Supervision::class)
        ->assertSee('Traitements en echec')
        ->assertDontSee('Rien a signaler');
});

it('repond sur son adresse, mise en page comprise', function () {
    /*
     * Une vraie requete HTTP en plus du test Livewire, et ce n'est pas
     * redondant : `Livewire::test` monte le composant seul, sans la mise en
     * page ni la navigation. Une erreur dans l'une des deux ne se verrait
     * qu'ici — c'est deja arrive sur l'ecran des resultats d'un scrutin, ou les
     * tests passaient pendant que la page rendait 500.
     */
    ($this->failJob)();

    $this->actingAs($this->admin)
        ->get(route('admin.supervision'))
        ->assertOk()
        ->assertSee('Supervision')
        ->assertSee('Traitements en echec');
});

it('n\'offre aucun chemin d\'ecriture', function () {
    /*
     * Rejouer un callback de paiement risque d'encaisser deux fois, et marquer
     * un incident regle sans l'avoir regle ne fait que le rendre invisible. Le
     * test porte sur la classe : un bouton se retire d'une vue, une methode
     * publique reste appelable depuis le navigateur.
     */
    $screen = new ReflectionClass(Supervision::class);

    $declared = collect($screen->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->getDeclaringClass()->getName() === Supervision::class)
        ->map(fn (ReflectionMethod $m) => $m->getName())
        // La garde du trait `SuperAdminOnly`, aplatie dans la classe par PHP.
        ->reject(fn (string $name) => $name === 'bootedSuperAdminOnly');

    expect($declared->all())->toEqualCanonicalizing(['render']);
});

it('refuse l\'ecran a qui n\'est pas Super Admin', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Supervision::class)->assertNotFound();
});
