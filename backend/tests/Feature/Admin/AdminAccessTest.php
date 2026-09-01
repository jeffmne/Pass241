<?php

use App\Livewire\Admin\AuditTrail;
use App\Livewire\Admin\Organizations;
use App\Livewire\Admin\Overview;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\Settlements;
use App\Livewire\Admin\Transactions;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Livewire\Livewire;

/*
 * Acces a la console d'administration (section 58).
 *
 * CE FICHIER DEFEND UNE SEULE IDEE : la console se refuse a qui n'est pas Super
 * Admin, A CHAQUE REQUETE — pas seulement a l'ouverture de la page.
 *
 * La distinction n'est pas theorique. Un ecran Livewire ne rejoue pas sa route
 * quand on clique dedans : il poste sur `/livewire/update`, ou Livewire ne
 * rejoue qu'une LISTE BLANCHE de middlewares. `EnsureSuperAdmin` n'y etait pas,
 * et aucun des sept ecrans de la console ne se gardait lui-meme — la protection
 * tenait donc entierement au premier chargement de la page.
 *
 * Consequence : quelqu'un dont le statut de Super Admin venait d'etre retire
 * gardait la main sur la console tant que son onglet restait ouvert, y compris
 * sur l'ecran qui fixe les taux de commission de la plateforme.
 */

beforeEach(function () {
    $this->intrus = User::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

/*
 * ---------------------------------------------------------------------------
 * Chaque ecran, un par un
 * ---------------------------------------------------------------------------
 */

/**
 * Les sept ecrans de la console.
 *
 * `OrganizationDetail` est absent de cette liste : il exige une organisation en
 * parametre, et son cas est traite a part plus bas.
 */
dataset('ecrans de la console', [
    'vue generale' => Overview::class,
    'organisations' => Organizations::class,
    'transactions' => Transactions::class,
    'reversements' => Settlements::class,
    'journal' => AuditTrail::class,
    'parametres' => Settings::class,
]);

it('refuse chaque ecran de la console a qui n\'est pas Super Admin', function (string $screen) {
    $this->actingAs($this->intrus);

    /*
     * 404 et non 403 : l'existence meme de la console n'a pas a etre confirmee
     * a qui n'y a pas acces (EnsureSuperAdmin applique deja cette regle).
     */
    Livewire::test($screen)->assertNotFound();
})->with('ecrans de la console');

it('ouvre chaque ecran de la console au Super Admin', function (string $screen) {
    // Le pendant du test precedent : une garde qui refuserait tout le monde
    // passerait le premier sans rien protéger.
    $this->actingAs($this->admin);

    Livewire::test($screen)->assertOk();
})->with('ecrans de la console');

/*
 * ---------------------------------------------------------------------------
 * Ce que la garde a l'ouverture ne suffisait pas a proteger
 * ---------------------------------------------------------------------------
 */

it('refuse une action de la console, et pas seulement son ouverture', function () {
    /*
     * LE TEST QUI JUSTIFIE LA GARDE SUR CHAQUE REQUETE.
     *
     * Une garde posee dans `mount()` ne s'execute qu'au premier chargement :
     * Livewire ne rappelle pas `mount` quand on clique. Un composant deja monte
     * resterait donc pilotable par quelqu'un qui vient de perdre son acces.
     *
     * L'ecran choisi n'est pas innocent : c'est celui qui fixe les taux de
     * commission de la plateforme (section 58).
     */
    $this->actingAs($this->admin);

    // Une valeur VALIDE : si la validation refusait la saisie, le test
    // passerait sans rien prouver de la garde.
    $screen = Livewire::test(Settings::class)->set('ticketingRate', '12');

    // Le statut est retire APRES le montage, onglet toujours ouvert.
    $this->admin->forceFill(['is_super_admin' => false])->save();

    $screen->call('save')->assertNotFound();

    // Le taux n'a pas bouge : la commission de la billetterie reste a 5 %
    // (section 21.2).
    expect(app(PlatformSettings::class)->commissionBasisPoints(App\Enums\CommissionType::Ticketing))
        ->toBe(500);
});
