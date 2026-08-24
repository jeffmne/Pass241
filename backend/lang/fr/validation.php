<?php

/*
 * Messages de validation en francais.
 *
 * L'interface, les emails et les messages d'erreur sont en francais (marche
 * gabonais). Sans ce fichier, Laravel affiche la cle brute de traduction —
 * « validation.max.file » — a l'utilisateur final.
 *
 * Ce fichier couvre les regles reellement utilisees dans le produit. Une regle
 * ajoutee plus tard doit etre traduite ici en meme temps.
 */

return [
    'accepted' => 'Le champ :attribute doit etre accepte.',
    'active_url' => 'Le champ :attribute n\'est pas une URL valide.',
    'after' => 'Le champ :attribute doit etre une date posterieure au :date.',
    'after_or_equal' => 'Le champ :attribute doit etre une date posterieure ou egale au :date.',
    'alpha' => 'Le champ :attribute ne doit contenir que des lettres.',
    'alpha_dash' => 'Le champ :attribute ne doit contenir que des lettres, des chiffres, des tirets et des tirets bas.',
    'alpha_num' => 'Le champ :attribute ne doit contenir que des lettres et des chiffres.',
    'array' => 'Le champ :attribute doit etre une liste.',
    'before' => 'Le champ :attribute doit etre une date anterieure au :date.',
    'before_or_equal' => 'Le champ :attribute doit etre une date anterieure ou egale au :date.',
    'boolean' => 'Le champ :attribute doit etre vrai ou faux.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'current_password' => 'Le mot de passe est incorrect.',
    'date' => 'Le champ :attribute n\'est pas une date valide.',
    'date_equals' => 'Le champ :attribute doit etre une date egale au :date.',
    'date_format' => 'Le champ :attribute ne correspond pas au format :format.',
    'declined' => 'Le champ :attribute doit etre refuse.',
    'different' => 'Les champs :attribute et :other doivent etre differents.',
    'digits' => 'Le champ :attribute doit contenir :digits chiffres.',
    'digits_between' => 'Le champ :attribute doit contenir entre :min et :max chiffres.',
    'dimensions' => 'Le champ :attribute a des dimensions d\'image non valides.',
    'distinct' => 'Le champ :attribute contient une valeur en double.',
    'email' => 'Le champ :attribute doit etre une adresse email valide.',
    'ends_with' => 'Le champ :attribute doit se terminer par l\'un des elements suivants : :values.',
    'enum' => 'La valeur du champ :attribute n\'est pas autorisee.',
    'exists' => 'La valeur du champ :attribute n\'existe pas.',
    'file' => 'Le champ :attribute doit etre un fichier.',
    'filled' => 'Le champ :attribute doit avoir une valeur.',
    'image' => 'Le champ :attribute doit etre une image.',
    'in' => 'La valeur du champ :attribute n\'est pas autorisee.',
    'integer' => 'Le champ :attribute doit etre un nombre entier.',
    'ip' => 'Le champ :attribute doit etre une adresse IP valide.',
    'json' => 'Le champ :attribute doit etre au format JSON.',
    'lowercase' => 'Le champ :attribute doit etre en minuscules.',
    'max' => [
        'array' => 'Le champ :attribute ne doit pas contenir plus de :max elements.',
        'file' => 'Le fichier :attribute ne doit pas depasser :max Ko.',
        'numeric' => 'Le champ :attribute ne doit pas etre superieur a :max.',
        'string' => 'Le champ :attribute ne doit pas depasser :max caracteres.',
    ],
    'mimes' => 'Le champ :attribute doit etre un fichier de type : :values.',
    'mimetypes' => 'Le champ :attribute doit etre un fichier de type : :values.',
    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min elements.',
        'file' => 'Le fichier :attribute doit peser au moins :min Ko.',
        'numeric' => 'Le champ :attribute doit etre au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caracteres.',
    ],
    'not_in' => 'La valeur du champ :attribute n\'est pas autorisee.',
    'not_regex' => 'Le format du champ :attribute n\'est pas valide.',
    'numeric' => 'Le champ :attribute doit etre un nombre.',
    'present' => 'Le champ :attribute doit etre present.',
    'prohibited' => 'Le champ :attribute est interdit.',
    'regex' => 'Le format du champ :attribute n\'est pas valide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_if' => 'Le champ :attribute est obligatoire lorsque :other vaut :value.',
    'required_with' => 'Le champ :attribute est obligatoire lorsque :values est present.',
    'required_without' => 'Le champ :attribute est obligatoire lorsque :values est absent.',
    'same' => 'Les champs :attribute et :other doivent etre identiques.',
    'size' => [
        'array' => 'Le champ :attribute doit contenir :size elements.',
        'file' => 'Le fichier :attribute doit peser :size Ko.',
        'numeric' => 'Le champ :attribute doit valoir :size.',
        'string' => 'Le champ :attribute doit contenir :size caracteres.',
    ],
    'starts_with' => 'Le champ :attribute doit commencer par l\'un des elements suivants : :values.',
    'string' => 'Le champ :attribute doit etre une chaine de caracteres.',
    'timezone' => 'Le champ :attribute doit etre un fuseau horaire valide.',
    'unique' => 'Cette valeur de :attribute est deja utilisee.',
    'uploaded' => 'Le televersement du fichier :attribute a echoue.',
    'uppercase' => 'Le champ :attribute doit etre en majuscules.',
    'url' => 'Le champ :attribute doit etre une URL valide.',

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'message-personnalise',
        ],
    ],

    /*
     * Noms des champs tels qu'ils apparaissent dans les messages. Sans cette
     * table, l'utilisateur lit « Le champ organizationName est obligatoire ».
     */
    'attributes' => [
        'name' => 'nom',
        'email' => 'adresse email',
        'phone' => 'numero de telephone',
        'password' => 'mot de passe',
        'organizationName' => 'nom de l\'organisation',
        'organizationType' => 'type d\'organisation',
        'contactEmail' => 'email de contact',
        'contactPhone' => 'telephone de contact',
        'documents' => 'piece justificative',
        'documents.IDENTITY' => 'piece d\'identite',
        'documents.RCCM' => 'RCCM',
    ],
];
