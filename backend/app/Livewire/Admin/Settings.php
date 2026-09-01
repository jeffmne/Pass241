<?php

namespace App\Livewire\Admin;

use App\Enums\CommissionType;
use App\Livewire\Concerns\SuperAdminOnly;
use App\Services\Platform\PlatformSettings;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Reglages de la plateforme, pour le Super Admin (section 58).
 *
 * CE QUI SE REGLE ICI : le taux de commission. Une valeur metier, qu'un
 * responsable produit change en connaissance de cause, sans deploiement.
 *
 * CE QUI NE S'Y REGLE PAS, ET NE S'Y AFFICHE PAS : les secrets. Cles du
 * prestataire de paiement, identifiants S3, mot de passe SMTP restent en
 * variables d'environnement. Les deplacer en base les rendrait lisibles par
 * toute requete SQL et les ferait entrer dans les sauvegardes — pour le seul
 * confort de les saisir a l'ecran. La configuration technique est donc
 * presentee en LECTURE SEULE, et sans jamais montrer une valeur secrete : ce
 * qui compte est de verifier quel prestataire est branche, pas avec quelle
 * cle.
 *
 * CHANGER LE TAUX NE CHANGE RIEN AU PASSE. Chaque commission porte un
 * instantane du sien (decision D-003) : les transactions d'hier et les
 * reversements calcules a partir d'elles restent exactement ce qu'ils etaient.
 * L'ecran le dit, parce que c'est la premiere inquietude qu'on a en modifiant
 * un taux.
 */
#[Layout('components.layouts.admin')]
class Settings extends Component
{
    use SuperAdminOnly;

    /** Taux en POURCENTAGE a l'ecran, en points de base en base. */
    public string $ticketingRate = '';

    public string $donationRate = '';

    public function mount(PlatformSettings $settings): void
    {
        $this->ticketingRate = $this->toPercent($settings->commissionBasisPoints(CommissionType::Ticketing));
        $this->donationRate = $this->toPercent($settings->commissionBasisPoints(CommissionType::Donation));
    }

    public function save(PlatformSettings $settings): void
    {
        /*
         * La virgule AVANT la validation.
         *
         * L'ecran affiche « 2,5 » — c'est la notation decimale francaise, et
         * c'est celle qu'un utilisateur d'ici saisit. `numeric` ne la connait
         * pas : sans cette normalisation, le formulaire refusait sa propre
         * valeur initiale.
         */
        $this->ticketingRate = str_replace(',', '.', trim($this->ticketingRate));
        $this->donationRate = str_replace(',', '.', trim($this->donationRate));

        $this->validate([
            // Deux decimales : 2,5 % existe, 2,567 % n'a aucun sens sur une
            // monnaie sans centimes.
            'ticketingRate' => ['required', 'numeric', 'min:0', 'max:30'],
            'donationRate' => ['required', 'numeric', 'min:0', 'max:30'],
        ], attributes: [
            'ticketingRate' => 'taux billetterie',
            'donationRate' => 'taux collectes',
        ]);

        try {
            $settings->setCommissionBasisPoints(
                CommissionType::Ticketing,
                $this->toBasisPoints($this->ticketingRate),
                auth()->user(),
            );

            $settings->setCommissionBasisPoints(
                CommissionType::Donation,
                $this->toBasisPoints($this->donationRate),
                auth()->user(),
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('ticketingRate', $e->getMessage());

            return;
        }

        // Relecture depuis la plateforme : l'ecran affiche ce qui a ete
        // reellement retenu, dans la notation francaise, et non la chaine que
        // l'utilisateur venait de taper.
        $this->mount($settings);

        $this->dispatch('notify', message: 'Taux enregistres. Les transactions passees ne changent pas.');
    }

    /**
     * Pourcentage saisi vers points de base, en arithmetique entiere.
     *
     * `round` avant le cast : 2,5 saisi devient 250, et non 249 par un
     * arrondi vers zero sur un flottant qui vaut 249,99999.
     */
    private function toBasisPoints(string $percent): int
    {
        return (int) round(((float) str_replace(',', '.', $percent)) * 100);
    }

    private function toPercent(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, ',', ''), '0'), ',');
    }

    public function render()
    {
        return view('livewire.admin.settings', [
            /*
             * Configuration technique, en lecture seule et sans secret. On
             * montre QUI est branche, jamais AVEC QUOI.
             */
            'technical' => [
                'Prestataire de paiement' => config('payments.default'),
                'Delai de paiement' => config('payments.timeout_seconds').' s',
                'Delai avant reversement' => config('payments.payouts.delay_hours').' h',
                'Reversement minimum' => number_format((int) config('payments.payouts.minimum_amount'), 0, ',', ' ').' F',
                'Messagerie' => config('mail.default'),
                'Stockage des fichiers' => config('filesystems.default'),
                'Fuseau d\'affichage' => config('app.display_timezone'),
                'Environnement' => app()->environment(),
            ],
        ]);
    }
}
