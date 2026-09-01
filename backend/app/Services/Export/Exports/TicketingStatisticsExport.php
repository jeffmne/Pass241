<?php

namespace App\Services\Export\Exports;

use App\Enums\PermissionName;
use App\Models\Organization;
use App\Services\Export\Exportable;
use App\Services\Statistics\TicketingStatistics;
use Generator;

/**
 * Statistiques de billetterie de l'organisation (sections 56, 59).
 *
 * Le dernier des huit exports annonces par la section 59. Il repond a la
 * question qu'on se pose apres l'evenement, quand on prepare le suivant :
 * qu'est-ce qui s'est vendu, et qu'est-ce qui est reste sur les bras.
 *
 * LES CHIFFRES VIENNENT DE `TicketingStatistics`, seul point de calcul — le
 * meme que la vue generale. Le fichier dit donc exactement ce que l'ecran dit,
 * au meme instant. Recalculer ici avec une requete « equivalente » aurait
 * produit, tot ou tard, un export qui contredit l'ecran ; et c'est l'export
 * qu'on garde, qu'on transfere, et qu'on ressort six mois plus tard.
 *
 * Les categories sont agregees PAR NOM et non par identifiant : « VIP » veut
 * dire la meme chose d'un evenement a l'autre, et c'est cette lecture qui
 * interesse l'organisateur.
 *
 * `finances.view` est exigee parce que le fichier porte une colonne de recette.
 * La vue generale applique deja cette regle a l'ecran (section 10) ; un export
 * ne doit jamais sortir ce qu'un ecran refuserait d'afficher.
 */
class TicketingStatisticsExport implements Exportable
{
    public function __construct(
        private readonly TicketingStatistics $statistics,
        private readonly Organization $organization,
    ) {}

    public function label(): string
    {
        return 'Statistiques billetterie';
    }

    public function filename(): string
    {
        return 'statistiques-billetterie-'.now()->format('Y-m-d');
    }

    public function headings(): array
    {
        return [
            'Categorie de billet',
            'Vendus',
            'Invitations',
            'Capacite',
            'Restant',
            'Part des ventes (%)',
            'Recette',
        ];
    }

    public function rows(): Generator
    {
        /*
         * Pas de `lazyById` ici, et c'est volontaire : ce sont des categories
         * de billets agregees par nom, quelques dizaines de lignes au plus.
         * Le generateur reste la forme du contrat, il n'a simplement rien de
         * lourd a menager.
         */
        foreach ($this->statistics->byTicketType($this->organization) as $type) {
            yield [
                $type['name'],
                $type['sold'],
                $type['invited'],
                // Une capacite nulle veut dire « illimitee », pas « zero
                // place » : un zero dans cette colonne se lirait comme une
                // categorie epuisee.
                $type['capacity'] > 0 ? $type['capacity'] : null,
                $type['capacity'] > 0 ? $type['remaining'] : null,
                round($type['share'], 1),
                $type['revenue'],
            ];
        }
    }

    public function permission(): PermissionName
    {
        return PermissionName::FinancesView;
    }

    public function auditContext(): array
    {
        return [];
    }
}
