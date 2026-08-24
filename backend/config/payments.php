<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider par defaut
    |--------------------------------------------------------------------------
    |
    | `fake` simule le flux complet de la section 21.1 — push USSD, validation,
    | refus, absence de callback jusqu'au timeout — sans acces aux operateurs
    | (decision D-002).
    |
    */

    'default' => env('PAYMENT_DEFAULT_DRIVER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Delai de validation d'un paiement
    |--------------------------------------------------------------------------
    |
    | Section 21.1 : passe ce delai sans callback, la commande bascule en
    | FAILED et le hold de stock est relache. Le hold suit exactement le meme
    | delai, comme le precise la meme section.
    |
    | Note : le schema de la section 17.1 mentionne 10 minutes pour le hold,
    | mais la section 21.1 fixe le timeout a 5 minutes et indique explicitement
    | que le hold suit ce timeout. C'est cette derniere formulation qui fait
    | foi, les deux valeurs devant rester egales par construction.
    |
    */

    'timeout_seconds' => 5 * 60,

    /*
    |--------------------------------------------------------------------------
    | Prefixes des operateurs
    |--------------------------------------------------------------------------
    |
    | Section 16.1 : le numero saisi doit correspondre a l'operateur choisi, et
    | cette verification se fait cote serveur.
    |
    | ATTENTION — valeurs a confirmer aupres d'Airtel Gabon et de Moov Gabon
    | avant la mise en ligne. Les plans de numerotation evoluent, et un prefixe
    | manquant ici empeche un client legitime de payer.
    |
    | Une liste vide desactive la verification pour cet operateur : le format du
    | numero reste verifie, mais son appartenance a l'operateur n'est plus
    | exigee. C'est l'etat par defaut, volontairement, tant que les listes
    | n'ont pas ete confirmees — mieux vaut laisser passer un numero a
    | verifier plus tard que refuser un vrai client sur une donnee inventee.
    |
    */

    'prefixes' => [
        'AIRTEL' => [],
        'MOOV' => [],
    ],

];
