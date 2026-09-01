<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\SuperAdminOnly;
use App\Services\Platform\HealthCheck;
use App\Services\Platform\SupervisionReport;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Supervision technique de la plateforme (sections 58, 72).
 *
 * L'ECRAN QU'ON OUVRE LE MATIN, et qui doit etre vide.
 *
 * Il repond a une question que ni la vue generale ni le journal d'audit ne
 * posaient : QU'EST-CE QUI EST RESTE EN CHEMIN ? La vue generale montre
 * l'activite de la plateforme, le journal montre ce que les gens ont fait —
 * aucun des deux ne montre ce que la machine n'a pas fait.
 *
 * DEUX BLOCS, ET LA SEPARATION DECIDE DE QUI EST REVEILLE LA NUIT.
 *
 * L'etat des services repond a « ca marche ? » : c'est ce qu'un moniteur
 * externe lit sur `/sante`, et une panne y est une urgence. Les incidents
 * repondent a « qu'est-ce qui demande un humain ? » : un email non parti n'est
 * pas une urgence, mais personne ne l'apprendra si cet ecran ne le dit pas.
 *
 * Melanger les deux ferait sonner une alerte de panne pour une invitation non
 * remise. Une alerte qui se declenche pour rien finit par ne plus etre lue, et
 * c'est alors la vraie panne qu'on rate.
 *
 * LECTURE SEULE, SANS EXCEPTION. Aucun bouton ne rejoue un job ni ne marque un
 * callback traite : rejouer un paiement risque de payer deux fois, et marquer
 * un incident « regle » sans l'avoir regle ne fait que le rendre invisible.
 * Ces gestes se font a la main, en connaissance de cause.
 */
#[Layout('components.layouts.admin')]
class Supervision extends Component
{
    use SuperAdminOnly;

    public function render(HealthCheck $health, SupervisionReport $report)
    {
        return view('livewire.admin.supervision', [
            // Rien n'est mis en cache : la valeur de cet ecran tient a sa
            // fraicheur. Un compteur d'incidents en retard de cinq minutes est
            // un compteur en qui on cesse de croire.
            'services' => $health->services(),
            'incidents' => $report->incidents(),
        ]);
    }
}
