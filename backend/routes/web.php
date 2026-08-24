<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Dashboard\Events\Form as EventForm;
use App\Livewire\Dashboard\Events\Index as EventIndex;
use App\Livewire\Dashboard\Events\TicketTypes as EventTicketTypes;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Organizations\Create as CreateOrganization;
use App\Livewire\Organizations\Settings as OrganizationSettings;
use App\Livewire\Public\EventPage;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

/*
 * Page publique d'un evenement (section 6).
 *
 * Volontairement hors de tout groupe d'authentification et hors du middleware
 * d'organisation : c'est un lien partage sur WhatsApp, ouvert par des gens qui
 * n'ont aucun compte. L'isolation multi-tenant y est assuree par le slug
 * lui-meme, unique sur la plateforme, et par `Event::publicBySlug()` qui
 * n'expose que les evenements ayant une page publique.
 *
 * L'adresse reste courte et lisible pour tenir sur une affiche imprimee.
 */
Route::get('/e/{slug}', EventPage::class)->name('events.public');

Route::middleware('guest')->group(function () {
    Route::get('/connexion', Login::class)->name('login');
    Route::get('/inscription', Register::class)->name('register');
});

Route::middleware('auth')->group(function () {
    Route::post('/deconnexion', LogoutController::class)->name('logout');

    /*
     * Volontairement hors du middleware `organization` : un utilisateur dont
     * l'appartenance vient d'etre revoquee n'a plus aucune organisation, et
     * doit malgre tout pouvoir en creer une. Placer cette route derriere le
     * middleware l'enfermerait dehors (voir EnsureOrganizationContext, qui
     * redirige justement ici).
     */
    Route::get('/organisations/nouvelle', CreateOrganization::class)->name('organizations.create');

    /*
     * Tout ce qui touche aux donnees d'une organisation passe par
     * EnsureOrganizationContext (section 9). Une route metier ajoutee en dehors
     * de ce groupe serait une faille d'isolation : la portee globale renverrait
     * alors une liste vide, ce qui doit alerter en revue.
     */
    Route::middleware('organization')->group(function () {
        Route::get('/dashboard', Overview::class)->name('dashboard');
        Route::get('/parametres', OrganizationSettings::class)->name('organization.settings');

        Route::get('/evenements', EventIndex::class)->name('events.index');
        Route::get('/evenements/nouveau', EventForm::class)->name('events.create');
        Route::get('/evenements/{event}/modifier', EventForm::class)->name('events.edit');
        Route::get('/evenements/{event}/billets', EventTicketTypes::class)->name('events.ticket-types');
    });
});
