<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/*
 * Deconnexion apres inactivite (section 66).
 *
 * Ce fichier verifie surtout les deux points que l'expiration de session de
 * Laravel ne couvre pas a elle seule : le fait que l'utilisateur SOIT PREVENU,
 * et le fait que « se souvenir de moi » ne permette pas de contourner le delai.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    config()->set('auth.idle_timeout', 30);
});

it('laisse passer une requete dans le delai', function () {
    $this->actingAs($this->owner);

    $this->get(route('dashboard'))->assertOk();

    // Vingt-neuf minutes plus tard : encore dans les clous.
    $this->travel(29)->minutes();

    $this->get(route('dashboard'))->assertOk();

    expect(Auth::check())->toBeTrue();
});

it('deconnecte au-dela du delai, et le dit', function () {
    /*
     * Le message est la moitie de la fonctionnalite. Sans lui, l'utilisateur
     * revient sur l'ecran de connexion sans savoir pourquoi, et croit a une
     * panne plutot qu'a une mesure de securite.
     */
    $this->actingAs($this->owner);
    $this->get(route('dashboard'))->assertOk();

    $this->travel(31)->minutes();

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('notice');

    expect(Auth::check())->toBeFalse();
});

it('nomme le delai reel dans le message', function () {
    config()->set('auth.idle_timeout', 15);

    $this->actingAs($this->owner);
    $this->get(route('dashboard'));

    $this->travel(16)->minutes();

    $this->get(route('dashboard'));

    expect(session('notice'))->toContain('15 minutes');
});

it('repousse le delai a chaque requete', function () {
    // C'est une coupure sur l'INACTIVITE, pas un plafond de duree : quelqu'un
    // qui travaille sans interruption ne doit jamais etre coupe.
    $this->actingAs($this->owner);

    foreach (range(1, 4) as $step) {
        $this->travel(20)->minutes();
        $this->get(route('dashboard'))->assertOk();
    }

    expect(Auth::check())->toBeTrue();
});

it('invalide le jeton de rappel en deconnectant', function () {
    /*
     * LE POINT CENTRAL. Sans `Auth::logout()`, le cookie « se souvenir de moi »
     * reconnecterait silencieusement des la requete suivante, et l'inactivite
     * ne deconnecterait rien du tout pour qui a coche la case.
     *
     * Le jeton de rappel tourne : celui qui etait en base ne vaut plus rien.
     */
    $this->post(route('login'), []); // amorce une session web

    Auth::login($this->owner, remember: true);

    $before = $this->owner->fresh()->getRememberToken();
    expect($before)->not->toBeEmpty();

    $this->get(route('dashboard'));
    $this->travel(31)->minutes();
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    expect($this->owner->fresh()->getRememberToken())->not->toBe($before);
});

it('renvoie un 419 a une requete Livewire plutot qu\'une redirection', function () {
    /*
     * Livewire ne sait pas suivre une redirection au milieu d'une requete de
     * composant : il recevrait du HTML la ou il attend du JSON, et l'ecran
     * resterait fige sur un etat qui n'existe plus cote serveur. Le 419 est le
     * code qu'il sait deja traiter — il recharge la page.
     */
    $this->actingAs($this->owner);
    $this->get(route('dashboard'));

    $this->travel(31)->minutes();

    $this->withHeader('X-Livewire', 'true')
        ->get(route('dashboard'))
        ->assertStatus(419);
});

it('ne coupe rien quand le delai est desactive', function () {
    // Reglage explicite plutot que middleware retire du groupe et oublie.
    config()->set('auth.idle_timeout', 0);

    $this->actingAs($this->owner);
    $this->get(route('dashboard'))->assertOk();

    $this->travel(10)->hours();

    $this->get(route('dashboard'))->assertOk();
});

it('laisse l\'API agent hors de portee', function () {
    /*
     * L'application agent s'authentifie par jeton Sanctum, sans session. Un
     * agent au controle des entrees ne doit surtout pas etre deconnecte parce
     * qu'il n'a rien scanne pendant trente minutes — c'est le creux entre deux
     * vagues d'arrivees.
     */
    $agent = User::factory()->create();
    $token = $agent->createToken('telephone-agent', ['access-control:scan'])->plainTextToken;

    $this->travel(2)->hours();

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/agent/evenements');

    // Le contenu de la reponse n'est pas le sujet : ce qui compte est que la
    // coupure de session ne s'en soit pas melee.
    expect($response->status())->not->toBe(419);
});

it('n\'ecrit aucun horodatage pour un visiteur non connecte', function () {
    // Rien a suivre : une page publique ne doit pas faire grossir la session
    // d'un visiteur qui ne se connectera peut-etre jamais.
    $this->get('/')->assertOk();

    expect(session()->has('auth.last_activity_at'))->toBeFalse();
});
