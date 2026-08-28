<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Taux de commission
    |--------------------------------------------------------------------------
    |
    | Section 21.2 : 5 % sur la billetterie, 2,5 % sur les collectes.
    |
    | Les votes payants sont a 5 %, comme la billetterie : un vote payant est
    | une vente de service a un public, pas un don a une cause. Le taux ne
    | figurait dans aucune section de la spec — il a ete tranche a l'ouverture
    | de la phase 9, sur recommandation de l'analyse d'architecture (section
    | 86.5, question ouverte 1).
    |
    | Exprimes en points de base (1 % = 100) plutot qu'en pourcentage flottant :
    | 2,5 % ne se represente pas exactement en binaire, et une commission
    | calculee sur un flottant finirait par produire un franc d'ecart sur
    | certaines transactions. Tout le calcul reste entier (decision D-003).
    |
    | Le taux applique est fige au moment de la transaction (snapshot dans
    | l'entite Commission) : changer ces valeurs n'affecte jamais le passe.
    |
    */

    'basis_points' => [
        'TICKETING' => 500,   // 5,00 %
        'DONATION' => 250,    // 2,50 %
        'VOTING' => 500,      // 5,00 %
    ],

];
