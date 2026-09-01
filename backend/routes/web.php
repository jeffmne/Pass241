<?php

use App\Enums\ExportFormat;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Dev\SimulatePaymentController;
use App\Http\Controllers\DonationReceiptController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ReceiptController;
use App\Livewire\Admin\AuditTrail as AdminAuditTrail;
use App\Livewire\Admin\OrganizationDetail as AdminOrganizationDetail;
use App\Livewire\Admin\Organizations as AdminOrganizations;
use App\Livewire\Admin\Overview as AdminOverview;
use App\Livewire\Admin\Settings as AdminSettings;
use App\Livewire\Admin\Settlements as AdminSettlements;
use App\Livewire\Admin\Transactions as AdminTransactions;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Dashboard\Campaigns\Donations as CampaignDonations;
use App\Livewire\Dashboard\Campaigns\Form as CampaignForm;
use App\Livewire\Dashboard\Campaigns\Index as CampaignIndex;
use App\Livewire\Dashboard\Polls\Categories as PollCategories;
use App\Livewire\Dashboard\Polls\Form as PollForm;
use App\Livewire\Dashboard\Polls\Index as PollIndex;
use App\Livewire\Dashboard\Polls\Results as PollResults;
use App\Livewire\Dashboard\Events\AccessControl as EventAccessControl;
use App\Livewire\Dashboard\Events\Entries as EventEntries;
use App\Livewire\Dashboard\Events\Form as EventForm;
use App\Livewire\Dashboard\Events\Index as EventIndex;
use App\Livewire\Dashboard\Events\Invitations as EventInvitations;
use App\Livewire\Dashboard\Events\TicketTypes as EventTicketTypes;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Dashboard\Refunds as DashboardRefunds;
use App\Livewire\Organizations\Create as CreateOrganization;
use App\Livewire\Organizations\Settings as OrganizationSettings;
use App\Livewire\Public\CampaignPage;
use App\Livewire\Public\DonationStatus;
use App\Livewire\Public\EventPage;
use App\Livewire\Public\BallotStatus;
use App\Livewire\Public\InvitationPage;
use App\Livewire\Public\MyTickets;
use App\Livewire\Public\OrderStatus;
use App\Livewire\Public\PollPage;
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
 * Page publique d'une collecte (sections 36, 54).
 *
 * Meme parti pris que l'evenement : hors authentification, hors contexte
 * d'organisation, isolee par un slug unique sur la plateforme. Le prefixe
 * `/collecte/` est distinct de `/e/` et le restera — deux espaces de nommage
 * separes evitent qu'une campagne et un evenement se disputent un slug, et
 * laissent la place au prefixe `/vote/` du module a venir (decision D-041).
 */
Route::get('/collecte/{slug}', CampaignPage::class)->name('campaigns.public');

/*
 * Page publique d'un scrutin (section 86.4).
 *
 * Le prefixe `/vote/` que la decision D-041 avait reserve. Meme parti pris que
 * l'evenement et la collecte : hors authentification, hors contexte
 * d'organisation, isolee par un slug unique sur la plateforme.
 *
 * La limitation de debit du VOTE lui-meme vit dans le composant, pas ici : elle
 * doit porter sur la categorie, pas sur la page — quelqu'un qui vote dans trois
 * categories du meme concours ne doit pas etre bloque pour autant.
 */
Route::get('/vote/{slug}', PollPage::class)->name('polls.public');

/*
 * Suivi d'un vote paye (sections 21.1, 86.4).
 *
 * Meme parti pris que le suivi de commande et de don : lien signe sans
 * expiration, parce que le votant n'a pas de compte et conserve ce lien. La
 * signature empeche de parcourir les bulletins en devinant des references — on
 * y lirait le numero du payeur, et sur quel candidat il a mise.
 */
Route::get('/bulletin/{reference}', BallotStatus::class)
    ->middleware('signed')
    ->name('ballots.show');

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
 * Suivi d'un don (sections 21.1, 39).
 *
 * Meme parti pris que le suivi de commande : lien signe sans expiration, parce
 * que le donateur n'a pas de compte et conserve ce lien. La signature empeche
 * de parcourir les dons en devinant des references — un don anonyme y perdrait
 * son anonymat (section 41).
 */
Route::get('/don/{reference}', DonationStatus::class)
    ->middleware('signed')
    ->name('donations.show');

/*
 * Recu de don (section 53).
 *
 * Piece comptable qu'on classe ou qu'on joint a une note de frais : elle se
 * telecharge, contrairement au billet qui reste un objet a l'ecran.
 */
Route::get('/don/{reference}/recu', DonationReceiptController::class)
    ->middleware('signed')
    ->name('donations.receipt');

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
 * Invitation (section 25).
 *
 * Pas de signature ici, contrairement au billet : c'est le JETON qui fait
 * l'acces, et il est deja imprevisible. Une signature en plus rendrait le lien
 * intransmissible par copier-coller — or une invitation circule dans un
 * message, c'est meme sa raison d'etre.
 *
 * Limitation de debit : le jeton est long, mais rien n'empeche d'essayer. Elle
 * ferme la porte a qui voudrait en deviner un.
 */
Route::get('/invitation/{token}', InvitationPage::class)
    ->middleware('throttle:30,1')
    ->name('invitations.show');

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

        /*
         * Fiche d'une organisation (section 58).
         *
         * Hors du middleware `organization`, comme toute la console : le Super
         * Admin n'appartient a aucune organisation et les voit toutes. La
         * liaison se fait donc sur le slug sans portee — `Organization` ne
         * porte pas le trait multi-tenant, c'est elle qui le definit.
         */
        Route::get('/organisations/{organization}', AdminOrganizationDetail::class)
            ->name('admin.organization');
        Route::get('/transactions', AdminTransactions::class)->name('admin.transactions');
        Route::get('/journal', AdminAuditTrail::class)->name('admin.audit');

        // Section 58 : reglages metier de la plateforme. Aucun secret n'y passe.
        Route::get('/parametres', AdminSettings::class)->name('admin.settings');
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

        // Section 25 : places offertes, qui retiennent du stock des leur
        // creation.
        Route::get('/evenements/{event}/invitations', EventInvitations::class)
            ->name('events.invitations');

        // Sections 34, 35 : collectes de fonds.
        Route::get('/collectes', CampaignIndex::class)->name('campaigns.index');
        Route::get('/collectes/nouvelle', CampaignForm::class)->name('campaigns.create');
        Route::get('/collectes/{campaign}/modifier', CampaignForm::class)->name('campaigns.edit');

        // Sections 44 a 48 : les dons recus, en lecture seule definitive. Aucun
        // chemin d'ecriture n'existe sur cet ecran, et un test le verifie.
        Route::get('/collectes/{campaign}/dons', CampaignDonations::class)
            ->name('campaigns.donations');

        // Section 86 : votes. « Vote » en interface, `Poll` en code — en
        // francais, le mot designe a la fois le scrutin et le bulletin.
        Route::get('/votes', PollIndex::class)->name('polls.index');
        Route::get('/votes/nouveau', PollForm::class)->name('polls.create');
        Route::get('/votes/{poll}/modifier', PollForm::class)->name('polls.edit');

        // Categories ET candidats sur un seul ecran : une categorie sans
        // candidat n'existe pas vraiment, et les separer obligerait a naviguer
        // entre deux pages pour monter un concours (section 86.5).
        Route::get('/votes/{poll}/categories', PollCategories::class)
            ->name('polls.categories');

        /*
         * Resultats d'un scrutin (sections 86.4, 87).
         *
         * `votes.view` suffit — l'ecran lui-meme le verifie : consulter un
         * classement n'est pas gerer un concours, et c'est souvent le
         * co-organisateur qui l'annonce.
         */
        Route::get('/votes/{poll}/resultats', PollResults::class)
            ->name('polls.results');

        // Section 24.1 : le client demande, l'organisateur decide.
        Route::get('/remboursements', DashboardRefunds::class)->name('refunds.index');

        /*
         * Exports (section 59).
         *
         * Dans le groupe `organization` comme tout le reste : un export lit les
         * memes donnees que les ecrans, avec la meme portee. Le format est un
         * segment contraint — une extension inconnue n'a pas a atteindre le
         * controleur.
         */
        Route::prefix('exports')->name('exports.')->group(function () {
            Route::get('/evenements/{event}/participants/{format}', [ExportController::class, 'participants'])
                ->name('participants');
            Route::get('/evenements/{event}/commandes/{format}', [ExportController::class, 'orders'])
                ->name('orders');
            Route::get('/evenements/{event}/controles/{format}', [ExportController::class, 'scans'])
                ->name('scans');
            Route::get('/collectes/{campaign}/dons/{format}', [ExportController::class, 'donations'])
                ->name('donations');
            Route::get('/collectes/{format}', [ExportController::class, 'campaigns'])
                ->name('campaigns');
            Route::get('/votes/{poll}/resultats/{format}', [ExportController::class, 'pollResults'])
                ->name('poll-results');
        })->whereIn('format', ExportFormat::values());
    });
});
