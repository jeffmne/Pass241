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
    | Adresse interne de l'application
    |--------------------------------------------------------------------------
    |
    | Utilisee uniquement par l'ecran de simulation, qui s'envoie a lui-meme un
    | webhook pour emprunter exactement le chemin de production.
    |
    | APP_URL est l'adresse vue depuis le navigateur (http://localhost:8000) ;
    | depuis le conteneur applicatif, elle ne mene nulle part, le serveur web
    | etant un conteneur voisin. D'ou ce reglage distinct.
    |
    */

    'internal_base_url' => env('APP_INTERNAL_URL', env('APP_URL')),

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

    /*
    |--------------------------------------------------------------------------
    | Frais du prestataire de paiement
    |--------------------------------------------------------------------------
    |
    | Ces frais sont a la charge de l'ACHETEUR, affiches separement avant le
    | paiement (decision D-023). Ils ne sont jamais preleves sur la commission
    | Pass241 ni sur ce qui revient a l'organisateur : un billet a 10 000 FCFA
    | rapporte toujours 500 a la plateforme et 9 500 a l'organisateur.
    |
    | Un bareme par driver, parce que chaque agregateur a le sien. Deux
    | composantes, parce qu'elles coexistent en pratique : un pourcentage en
    | points de base, et une part fixe par transaction. La part fixe est ce qui
    | rend un petit montant proportionnellement cher — a surveiller si des
    | billets a 1 000 FCFA apparaissent.
    |
    | ATTENTION — les valeurs de `fake` sont FICTIVES. Elles existent pour que
    | le parcours d'achat montre la ligne de frais en developpement, et n'ont
    | ete confirmees par aucun prestataire. Le bareme reel de PVit, PAYNALA ou
    | Digitech Africa est a saisir ici au moment du contrat.
    |
    | Un driver sans bareme fait echouer la commande, volontairement : sans
    | cette regle, brancher un vrai prestataire sans renseigner ses frais les
    | ferait silencieusement absorber par Pass241, transaction apres
    | transaction, sans que rien ne le signale.
    |
    */

    'fees' => [
        'fake' => [
            'basis_points' => 200,  // 2,00 % — fictif
            'fixed' => 0,           // en entiers XAF
        ],

        /*
        | PVit : UN BAREME PAR OPERATEUR, releve sur leur API `get-fees` et non
        | suppose. Airtel Money et Moov Money n'ont pas le meme taux, et un
        | bareme unique surfacturerait les uns ou sous-facturerait les autres.
        |
        | Mesure faite le 2026-08-31 sur le compte de test, lineaire et sans
        | part fixe sur toute la plage essayee (200 F a 50 000 F) :
        |   Airtel  1 000 -> 35    10 000 -> 350    50 000 -> 1 750   = 3,50 %
        |   Moov    1 000 -> 25    10 000 -> 250    50 000 -> 1 250   = 2,50 %
        |
        | En dessous de 200 F, PVit repond FEES_SCHEDULE_NOT_IMPLEMENTED : il
        | existe un montant minimum, a borner cote produit.
        |
        | CES TAUX SONT LE TOTAL DEBITE AU PAYEUR, part operateur comprise.
        |
        | PVit facture en DEUX composantes : sa part (`fees`) et celle de
        | l'operateur (`operatorFees`). Ni l'API de statut ni `get-fees` ne
        | donnent le total exact — seul le CALLBACK porte les deux. Releve sur
        | des transactions reelles le 2026-09-01 :
        |
        |   operateur  sous-total   fees     operatorFees   taux reel
        |   Airtel        500       12,5        5,125         3,525 %
        |   Airtel      1 000       25         10,25          3,525 %
        |   Airtel      2 000       50         20,5           3,525 %
        |   Airtel      5 000      125         51,25          3,525 %
        |   Moov        1 000       25          0             2,500 %
        |   Moov        4 000      100          0             2,500 %
        |
        | `GET-FEES` SE TROMPE LEGEREMENT SUR AIRTEL : il annonce 3,5 % la ou
        | l'operateur en prend 3,525 %. L'ecart est infime par transaction —
        | 1,25 F sur 5 000 — mais il est SYSTEMATIQUE et toujours dans le
        | mauvais sens : c'est un decouvert sur le compte marchand, a combler
        | transaction apres transaction. Le callback fait donc foi, pas
        | `get-fees`.
        |
        | 353 points de base et non 352,5 : le bareme est entier, et arrondir
        | au superieur est la seule direction qui ne cree jamais de manque. Le
        | payeur avance quelques centimes de plus que le prestataire ne prend,
        | ce qui est sans consequence — l'inverse ne l'est pas.
        |
        | PVit rend des frais FRACTIONNAIRES (52,5 F sur 1 500 F Airtel) alors
        | que le franc CFA n'a pas de subdivision. Notre calcul arrondit au
        | franc SUPERIEUR — la seule direction qui ne cree jamais de decouvert
        | sur le compte marchand.
        */
        'pvit' => [
            'operators' => [
                'AIRTEL' => ['basis_points' => 353, 'fixed' => 0],  // 3,525 % arrondi au superieur
                'MOOV' => ['basis_points' => 250, 'fixed' => 0],    // 2,5 %, sans part operateur
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | PVit — agregateur mobile money et carte (Gabon et CEMAC)
    |--------------------------------------------------------------------------
    |
    | CHAQUE API A SON PROPRE CODE D'URL, et ils different tous : ce ne sont pas
    | six occurrences d'une meme valeur. Ils sont lus depuis l'environnement
    | parce qu'ils sont propres au compte marchand, et qu'un compte de test et
    | un compte de production n'ont pas les memes.
    |
    | La version de l'API n'est pas uniforme non plus — `rest` et `renew-secret`
    | sont en v2, `status` et `balance` en v1 — d'ou des chemins complets
    | plutot qu'une base commune a laquelle on ajouterait un code.
    |
    | LA CLE SECRETE N'EST PAS ICI : elle est temporaire (3 600 secondes) et
    | s'obtient par `renew-secret`. Ce qui vit dans l'environnement, c'est le
    | mot de passe qui autorise sa rotation.
    |
    */

    'pvit' => [

        'base_url' => env('PVIT_BASE_URL', 'https://api.mypvit.pro'),

        /*
        | Compte d'operation (ACC_xxx). Il identifie le compte a crediter, et
        | il est exige par presque toutes les API — y compris le simple
        | controle de sante.
        */
        'operation_account_code' => env('PVIT_OPERATION_ACCOUNT_CODE'),

        // Mot de passe de rotation de la cle secrete, defini dans le menu APIs
        // de l'espace marchand.
        'secret_password' => env('PVIT_SECRET_PASSWORD'),

        /*
        | Code de l'URL de callback, obtenu apres avoir enregistre une adresse
        | PUBLIQUE dans l'espace marchand. PVit refuse `localhost` et
        | `127.0.0.1` : en developpement, il faut un tunnel.
        */
        'callback_url_code' => env('PVIT_CALLBACK_URL_CODE'),

        'endpoints' => [
            'renew_secret' => env('PVIT_ENDPOINT_RENEW_SECRET'),
            'payment' => env('PVIT_ENDPOINT_PAYMENT'),
            'status' => env('PVIT_ENDPOINT_STATUS'),
            'balance' => env('PVIT_ENDPOINT_BALANCE'),
            'fees' => env('PVIT_ENDPOINT_FEES'),
            'link' => env('PVIT_ENDPOINT_LINK'),
        ],

        /*
        | Correspondance entre NOS operateurs et les codes PVit.
        |
        | Relevee sur l'API `get-operators` pour le Gabon, et non supposee. PVit
        | expose aussi les memes operateurs via GIMAC (`GAB_AIRTEL`,
        | `GAB_MOOV`) : ce sont d'autres chemins d'acces, que l'on n'emprunte
        | pas — les codes retenus ici sont ceux des fournisseurs directs.
        */
        'operators' => [
            'AIRTEL' => 'AIRTEL_MONEY',
            'MOOV' => 'MOOV_MONEY',
        ],

        /*
        | Qui supporte les frais du prestataire.
        |
        | `CUSTOMER` traduit exactement la decision D-023 : les frais sont
        | avances par le payeur, pour que l'organisateur recoive le montant
        | annonce. Les mettre a la charge du marchand changerait le modele
        | economique du produit — ne pas modifier sans revoir D-023.
        */
        'charge_owner' => 'CUSTOMER',

    ],

    /*
    |--------------------------------------------------------------------------
    | Reversement
    |--------------------------------------------------------------------------
    */

    'payouts' => [

        /*
        | Pieces justificatives exigees de l'organisateur avant reversement.
        |
        | Desactive (decision D-025) : le compte mobile money de destination est
        | deja verifie par Airtel ou Moov, qui reclament une piece d'identite a
        | l'ouverture. Il y a donc bien une identite derriere le numero, meme si
        | Pass241 ne la collecte pas — et le reversement n'intervenant qu'apres
        | l'evenement, un organisateur fictif ne touche jamais rien.
        |
        | Le dispositif de collecte de pieces reste en place et fonctionnel :
        | ce reglage le rallume le jour ou le volume, un prestataire ou le
        | regulateur l'exigeront. Ne pas supprimer le code correspondant.
        */
        'require_kyc_documents' => env('PASS241_REQUIRE_KYC_DOCUMENTS', false),

        /*
        | Delai entre la fin de l'evenement et le reversement, en heures.
        |
        | Le reversement intervient APRES l'evenement (decision D-026). Ce
        | choix n'est pas administratif : la fenetre de remboursement se ferme
        | 48h AVANT l'evenement (section 24.1). Reverser apres garantit donc
        | qu'aucun remboursement n'exigera jamais de recuperer des fonds deja
        | partis chez l'organisateur — ce qui ferait de Pass241 le creancier de
        | ses propres clients.
        |
        | Les 24 heures supplementaires absorbent les incidents du jour meme :
        | un evenement annule sur place, une salle fermee, une reclamation
        | massive a la sortie.
        */
        'delay_hours' => (int) env('PASS241_PAYOUT_DELAY_HOURS', 24),

        /*
        | Montant net minimal declenchant un reversement.
        |
        | Sous ce seuil, les frais fixes du prestataire mangeraient l'essentiel
        | du virement. Zero desactive le seuil.
        */
        'minimum_amount' => (int) env('PASS241_PAYOUT_MINIMUM', 0),

    ],

    /*
    |--------------------------------------------------------------------------
    | Remboursements
    |--------------------------------------------------------------------------
    */

    'refunds' => [

        /*
        | Fenetre de demande, en heures avant le debut de l'evenement
        | (section 24.1).
        |
        | CETTE VALEUR EST LA PIERRE ANGULAIRE DU MODELE FINANCIER. Le
        | reversement intervient APRES l'evenement (decision D-026) : fermer
        | les demandes avant garantit qu'aucun remboursement n'exigera de
        | recuperer des fonds deja partis chez l'organisateur.
        |
        | L'augmenter est sans danger. La reduire — et a plus forte raison
        | passer en dessous de zero, donc autoriser les demandes apres
        | l'evenement — ferait de Pass241 le creancier de ses propres clients,
        | aupres de gens qui ont peut-etre deja depense l'argent. Ne pas
        | toucher sans revoir le reversement.
        */
        'window_hours' => (int) env('PASS241_REFUND_WINDOW_HOURS', 48),

    ],

];
