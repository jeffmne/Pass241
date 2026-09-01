<?php

namespace App\Services\Export\Exports;

use App\Enums\CommissionType;
use App\Enums\PermissionName;
use App\Models\Commission;
use App\Services\Export\Exportable;
use App\Services\Finance\TransactionLedger;
use Generator;

/**
 * Le grand livre des transactions, en fichier (sections 56, 59).
 *
 * C'est l'export qu'on ouvre devant un comptable, ou qu'on garde pour justifier
 * un reversement des mois plus tard. Il porte donc les trois montants qui
 * expliquent la difference entre ce que le public a paye et ce que
 * l'organisateur a recu : le brut, la commission au taux fige ce jour-la, et le
 * net.
 *
 * LES AJUSTEMENTS SORTENT EN NEGATIF. En base ils sont positifs — une ligne
 * d'ajustement decrit un mouvement de retour, pas un solde. Mais dans un
 * tableur, quelqu'un va selectionner la colonne « Net » et lire la somme en bas
 * de son ecran. Ce total doit etre le bon sans qu'il ait a soustraire quoi que
 * ce soit a la main, ni meme a savoir qu'il y avait quelque chose a soustraire.
 *
 * La colonne « Nature » dit lequel des deux on lit, pour que le signe ne soit
 * jamais a interpreter.
 */
class TransactionsExport implements Exportable
{
    public function __construct(
        private readonly TransactionLedger $ledger,
        private readonly ?CommissionType $type,
        private readonly string $period,
    ) {}

    public function label(): string
    {
        return 'Transactions';
    }

    public function filename(): string
    {
        $scope = $this->type?->value ? strtolower($this->type->value) : 'toutes';

        return 'transactions-'.$scope.'-'.now()->format('Y-m-d');
    }

    public function headings(): array
    {
        return [
            'Date',
            'Module',
            'Nature',
            'Reference',
            'Montant brut',
            'Taux',
            'Commission',
            'Net',
        ];
    }

    public function rows(): Generator
    {
        $tz = config('app.display_timezone');

        /*
         * `lazyById` avec la relation polymorphe chargee par paquet : sans le
         * `with`, chaque ligne irait chercher sa commande, son don ou son
         * bulletin une par une, et un export d'un millier de transactions
         * produirait un millier de requetes.
         */
        $transactions = $this->ledger->query($this->type, $this->period)
            ->with('commissionable')
            ->orderBy('id')
            ->lazyById(500);

        foreach ($transactions as $transaction) {
            // -1 pour un ajustement : c'est ici, et seulement ici, que le signe
            // change. La base garde ses montants positifs.
            $sign = $transaction->isAdjustment() ? -1 : 1;

            yield [
                $transaction->created_at->timezone($tz)->format('Y-m-d H:i'),
                $transaction->type->label(),
                $transaction->isAdjustment() ? 'Remboursement' : 'Encaissement',
                $this->reference($transaction),
                $sign * $transaction->gross_amount,
                $transaction->formattedRate(),
                $sign * $transaction->commission_amount,
                $sign * $transaction->net_amount,
            ];
        }
    }

    /**
     * Reference lisible de la transaction commissionnee.
     *
     * Les trois modules l'exposent par le meme contrat (`Payable`, decision
     * D-041) : cette colonne n'a donc pas a savoir si elle regarde une commande,
     * un don ou un bulletin.
     *
     * Le `?->` couvre le seul cas ou elle serait vide : une ligne dont la
     * transaction d'origine a disparu. Elle doit malgre tout sortir — une
     * commission sans reference reste un mouvement d'argent, et la masquer
     * ferait mentir le total.
     */
    private function reference(Commission $transaction): ?string
    {
        $payable = $transaction->commissionable;

        return method_exists($payable, 'paymentReference')
            ? $payable->paymentReference()
            : null;
    }

    public function permission(): PermissionName
    {
        return PermissionName::FinancesView;
    }

    public function auditContext(): array
    {
        return [
            'module' => $this->type?->value ?? 'all',
            'period' => $this->period,
        ];
    }
}
