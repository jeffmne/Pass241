<?php

namespace App\Livewire\Dashboard\Finances;

use App\Enums\CommissionType;
use App\Enums\PermissionName;
use App\Livewire\Concerns\RequiresPermission;
use App\Services\Finance\TransactionLedger;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Le grand livre des transactions de l'organisation (sections 56, 59).
 *
 * L'ECRAN QUI REPOND A « POURQUOI AI-JE RECU MOINS QUE CE QUI A ETE PAYE ».
 *
 * Jusqu'ici les chiffres d'argent vivaient chacun dans son module : les ventes
 * sur la vue generale, la progression sur la collecte, les revenus sur le
 * classement d'un scrutin. Aucun ecran ne montrait les trois ensemble, ni ne
 * disait ce que la plateforme avait retenu. Un organisateur qui vend des
 * billets, recoit des dons et fait payer des votes n'avait aucun endroit ou
 * lire son total.
 *
 * TROIS MODULES, UNE SEULE TABLE. `commissions` porte deja les trois par une
 * relation polymorphe (decision D-041) : cet ecran ne fait que la lire dans
 * l'ordre du temps. Il n'introduit aucun nouveau calcul financier, et surtout
 * aucun compteur — la source de verite reste la ligne de commission, ecrite a
 * la confirmation du paiement et jamais modifiee.
 *
 * LECTURE SEULE, DEFINITIVEMENT. Aucune action, aucun formulaire, aucun chemin
 * d'ecriture : la table est en ajout seul et l'ecran n'a rien a y corriger
 * (regle 80.1.6). Un test le verifie.
 *
 * `finances.view` est exigee des le montage : le co-organisateur gere
 * l'operationnel sans voir l'argent (section 10), et cet ecran n'est QUE de
 * l'argent.
 */
#[Layout('components.layouts.app')]
class Transactions extends Component
{
    use RequiresPermission, WithPagination;

    /**
     * Filtres portes par l'URL.
     *
     * Un organisateur qui envoie « regarde les votes de cette annee » a son
     * comptable doit pouvoir coller un lien. C'est aussi ce qui permet aux deux
     * liens d'export de reprendre exactement les filtres affiches, au lieu de
     * sortir un fichier qui ne correspond pas a l'ecran d'ou on l'a demande.
     */
    #[Url]
    public string $module = '';

    #[Url]
    public string $period = TransactionLedger::DEFAULT_PERIOD;

    protected function requiredPermission(): PermissionName
    {
        return PermissionName::FinancesView;
    }

    public function updatingModule(): void
    {
        $this->resetPage();
    }

    public function updatingPeriod(): void
    {
        $this->resetPage();
    }

    public function render(TransactionLedger $ledger)
    {
        /*
         * Les deux filtres sont NORMALISES avant tout usage : ils viennent de
         * l'URL, donc de n'importe ou. Un module invente retombe sur « tous »
         * et une periode inventee sur la periode par defaut — plutot qu'une
         * erreur 500 sur un lien mal recopie.
         */
        $type = CommissionType::tryFrom($this->module);
        $period = $ledger->normalizePeriod($this->period);

        return view('livewire.dashboard.finances.transactions', [
            'transactions' => $ledger->query($type, $period)
                ->with('commissionable')
                // Le plus recent en premier : c'est le mouvement qu'on vient
                // verifier. `id` departage les lignes d'une meme seconde, qu'un
                // tri sur la seule date rendrait instables d'une page a
                // l'autre.
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25),
            'totals' => $ledger->totals($type, $period),
            'type' => $type,
            'period' => $period,
            'periods' => TransactionLedger::PERIODS,
            'modules' => CommissionType::cases(),
            'canExport' => auth()->user()->can(PermissionName::ExportsGenerate->value),
        ]);
    }
}
