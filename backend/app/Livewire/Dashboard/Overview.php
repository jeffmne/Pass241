<?php

namespace App\Livewire\Dashboard;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Settlement;
use App\Services\Statistics\TicketingStatistics;
use App\Support\Tenancy\OrganizationContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Vue generale du dashboard organisateur (section 56).
 *
 * Le premier ecran apres la connexion, et souvent le seul qu'un organisateur
 * regarde dans la journee. Il repond a quatre questions, dans cet ordre :
 * combien s'est vendu, comment cela evolue, que faire ensuite, et ou en sont
 * mes evenements.
 *
 * TOUT EST AGREGE EN SQL et mis en cache par `TicketingStatistics` : cet ecran
 * est rafraichi en boucle un soir de mise en vente, il ne doit jamais couter le
 * parcours d'une table de billets.
 *
 * LES MONTANTS SONT CONDITIONNES par la permission financiere ; les decomptes
 * de billets ne le sont pas (section 10). Un CO_ORGANIZER gere la billetterie
 * sans acces aux finances : il doit savoir combien de billets sont partis pour
 * organiser l'entree, sans voir les recettes.
 */
#[Layout('components.layouts.app')]
class Overview extends Component
{
    public function render()
    {
        $organization = app(OrganizationContext::class)->current();
        $user = auth()->user();

        $statistics = app(TicketingStatistics::class);

        $canSeeMoney = $user->can(PermissionName::FinancesView->value);

        return view('livewire.dashboard.overview', [
            'stats' => $statistics->forOrganization($organization),
            'byType' => $statistics->byTicketType($organization),
            /*
             * Series du tableau de bord (section 56).
             *
             * Elles sont agregees en SQL et mises en cache comme le reste des
             * chiffres : afficher une courbe ne doit pas couter plus cher que
             * d'afficher le total qu'elle detaille.
             *
             * La courbe de recettes est conditionnee par la permission
             * financiere, exactement comme les montants des cartes. Un
             * CO_ORGANIZER qui ne voit pas les recettes ne doit pas pouvoir les
             * lire sur un graphique — ce serait la meme fuite, en plus discret.
             */
            'dailySales' => $statistics->dailySales($organization),
            'monthlyRevenue' => $canSeeMoney ? $statistics->monthlyRevenue($organization) : [],
            /*
             * Les MONTANTS sont conditionnes par la permission financiere ;
             * les decomptes de billets ne le sont pas (section 10).
             *
             * Un CO_ORGANIZER gere la billetterie sans acces aux finances : il
             * doit savoir combien de billets sont partis pour organiser
             * l'entree, sans voir les recettes. Masquer les deux le rendrait
             * incapable de faire son travail.
             */
            'canSeeMoney' => $canSeeMoney,
            'organization' => $organization,
            /*
             * Les trois evenements les plus proches, avec leur remplissage.
             *
             * `withCount` plutot qu'un chargement des billets : trois lignes de
             * resultat quel que soit le nombre de billets vendus, meme regle
             * que pour les chiffres consolides.
             *
             * Tries par date de debut CROISSANTE parmi ceux qui n'ont pas eu
             * lieu : ce que l'organisateur surveille, c'est le prochain, pas le
             * dernier cree.
             */
            'recentEvents' => Event::query()
                ->withCount([
                    'tickets as sold_count' => fn ($query) => $query
                        ->whereIn('status', [TicketStatus::Active->value, TicketStatus::Used->value]),
                ])
                ->withSum('ticketTypes as capacity_count', 'quantity')
                ->orderByRaw('starts_at >= now() DESC')
                ->orderBy('starts_at')
                ->limit(3)
                ->get(),
            /*
             * Les cinq dernieres ventes.
             *
             * Reservees a qui peut voir les finances : une liste de ventes avec
             * leurs montants EST une donnee financiere, quel que soit l'ecran
             * qui la porte.
             */
            // `items` est charge explicitement : `Order::totalTickets()` les
            // additionne, et le chargement differe est desactive dans ce
            // projet. Sans cette ligne, l'ecran leve une
            // LazyLoadingViolationException des la premiere commande payee.
            'recentSales' => $canSeeMoney
                ? Order::query()->paid()->with(['event', 'items'])->latest('paid_at')->limit(5)->get()
                : collect(),
            'user' => $user,
            'roles' => $user->roles->pluck('name')
                ->map(fn (string $name) => RoleName::tryFrom($name)?->label() ?? $name)
                ->all(),
            'memberCount' => $organization->members()->active()->count(),
            // Section 21.2 : l'organisateur doit savoir quand son argent
            // arrive. C'est la question la plus posee au support d'une
            // billetterie, et la seule reponse acceptable est une date.
            // `settlable` et non `event` : un reversement porte desormais sur
            // un evenement OU une collecte (decision D-041).
            'settlements' => Settlement::query()
                ->with('settlable')
                ->latest()
                ->limit(5)
                ->get(),
            'upcomingPayout' => $this->upcomingPayout($organization),
        ]);
    }

    /**
     * Prochain reversement attendu, quand il n'a pas encore ete instruit.
     *
     * Calcule a partir du premier evenement termine non encore reverse : c'est
     * lui qui determine la date. Aucun montant n'est affiche ici — il ne sera
     * fige qu'a la creation du reversement, et annoncer un chiffre qui bougera
     * vaut moins que ne rien annoncer.
     */
    private function upcomingPayout(Organization $organization): ?Event
    {
        return Event::query()
            ->whereNotNull('ends_at')
            ->whereDoesntHave('settlement')
            ->where('ends_at', '<=', now())
            ->orderBy('ends_at')
            ->first();
    }
}
