<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Dev\SimulatePaymentController;
use App\Http\Controllers\ReceiptController;
use App\Livewire\Admin\AuditTrail as AdminAuditTrail;
use App\Livewire\Admin\Organizations as AdminOrganizations;
use App\Livewire\Admin\Overview as AdminOverview;
use App\Livewire\Admin\Settlements as AdminSettlements;
use App\Livewire\Admin\Transactions as AdminTransactions;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Dashboard\Events\AccessControl as EventAccessControl;
use App\Livewire\Dashboard\Events\Entries as EventEntries;
use App\Livewire\Dashboard\Events\Form as EventForm;
use App\Livewire\Dashboard\Events\Index as EventIndex;
use App\Livewire\Dashboard\Events\TicketTypes as EventTicketTypes;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Dashboard\Refunds as DashboardRefunds;
use App\Livewire\Organizations\Create as CreateOrganization;
use App\Livewire\Organizations\Settings as OrganizationSettings;
use App\Livewire\Public\EventPage;
use App\Livewire\Public\MyTickets;
use App\Livewire\Public\OrderStatus;
use App\Livewire\Public\TicketPage;
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

/*
 * Suivi d'une commande (sections 15.1, 21.1).
 *
 * Lien signe sans expiration (decision D-004) : l'acheteur n'a pas de compte,
 * il conserve ce lien pour revenir a ses billets. La signature empeche de
 * parcourir les commandes en devinant des references.
 */
Route::get('/commande/{reference}', OrderStatus::class)
    ->middleware('signed')
    ->name('orders.show');

/*
 * Recu de paiement (section 53 adaptee, decision D-029).
 *
 * Contrairement au billet, le recu SE TELECHARGE en PDF : c'est une piece
 * comptable qu'on classe, qu'on transmet a un tresorier ou qu'on joint a une
 * note de frais. Le billet, lui, doit rester le meme objet a l'ecran et dans
 * la galerie du telephone, d'ou l'image.
 */
Route::get('/commande/{reference}/recu', ReceiptController::class)
    ->middleware('signed')
    ->name('orders.receipt');

/*
 * Le billet (sections 15.1, 15.2).
 *
 * Liens signes : le porteur n'a pas de compte, et la signature empeche de
 * parcourir les billets en devinant des references.
 */
Route::get('/billet/{reference}', TicketPage::class)
    ->middleware('signed')
    ->name('tickets.show');

/*
 * Aucune route de telechargement PDF : le bouton de la page billet produit une
 * image, generee par le navigateur a partir du billet affiche. Le PDF ne
 * circule plus que par email, en piece jointe (section 69).
 */

/*
 * Espace « Mes billets ».
 *
 * Sans signature : c'est le cookie de longue duree qui reconnait l'appareil
 * (decision D-004). Un lien signe ici n'aurait aucun sens — l'acheteur arrive
 * de sa page d'accueil, pas d'un lien recu.
 */
Route::get('/mes-billets', MyTickets::class)->name('tickets.mine');

/*
 * Simulation d'une reponse d'operateur — developpement uniquement.
 * Le controleur refuse lui-meme de repondre hors de ce cadre.
 */
Route::post('/dev/paiement/{reference}/simuler', SimulatePaymentController::class)
    ->name('dev.payments.simulate');

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
     * Console d'administration de la plateforme (section 58).
     *
     * Hors du middleware `organization`, et c'est le point essentiel : le
     * Super Admin n'appartient a aucune organisation et les voit toutes. Le
     * placer derriere ce middleware le renverrait creer une organisation.
     *
     * C'est le SEUL endroit du produit ou la portee multi-tenant est levee de
     * facon assumee (regle 80.4). `EnsureSuperAdmin` repond 404 : l'existence
     * de cette console n'a pas a etre confirmee a qui n'y a pas acces.
     */
    Route::middleware('super-admin')->prefix('administration')->group(function () {
        Route::get('/', AdminOverview::class)->name('admin.overview');
        Route::get('/reversements', AdminSettlements::class)->name('admin.settlements');
        Route::get('/organisations', AdminOrganizations::class)->name('admin.organizations');
        Route::get('/transactions', AdminTransactions::class)->name('admin.transactions');
        Route::get('/journal', AdminAuditTrail::class)->name('admin.audit');
    });

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

        // Sections 29, 30 : points d'entree et agents de controle.
        Route::get('/evenements/{event}/controle-acces', EventAccessControl::class)
            ->name('events.access-control');

        // Section 27.2 : consulter l'activite. Lecture seule — le journal des
        // scans est en ajout seul, et aucun ecran ne le corrigera.
        Route::get('/evenements/{event}/entrees', EventEntries::class)
            ->name('events.entries');

        // Section 24.1 : le client demande, l'organisateur decide.
        Route::get('/remboursements', DashboardRefunds::class)->name('refunds.index');
    });
});
