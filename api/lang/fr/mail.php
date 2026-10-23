<?php

return [

    'account_link' => [
        'claim' => [
            'subject' => 'Confirmez votre adresse e-mail pour sécuriser votre garage',
            'intro' => 'Quelqu’un a demandé à sécuriser le garage du pilote :driver avec cette adresse e-mail. Si c’était vous, confirmez-la ci-dessous.',
            'action' => 'Confirmer l’adresse e-mail',
        ],
        'add' => [
            'subject' => 'Confirmez votre adresse e-mail pour l’ajouter à votre garage',
            'intro' => 'Quelqu’un a demandé à ajouter cette adresse e-mail comme moyen de connexion au garage du pilote :driver. Si c’était vous, confirmez-la ci-dessous.',
            'action' => 'Confirmer l’adresse e-mail',
        ],
        'login' => [
            'subject' => 'Votre lien de connexion à RideIdle',
            'intro' => 'Utilisez le bouton ci-dessous pour vous connecter au garage du pilote :driver.',
            'action' => 'Se connecter',
        ],
        'existing' => [
            'subject' => 'Cette adresse e-mail a déjà un garage',
            'intro' => 'Quelqu’un a essayé de sécuriser un nouveau garage avec cette adresse e-mail, mais elle est déjà associée au garage du pilote :driver. Si c’était vous et que vous voulez utiliser ce garage sur cet appareil, connectez-vous ci-dessous.',
            'action' => 'Se connecter',
        ],
        'expires' => 'Le lien n’est utilisable qu’une fois et reste valable :minutes minutes.',
        'ignore' => 'Si ce n’était pas vous, ignorez ce message : rien ne change sans le lien.',
        'fallback' => 'Si le bouton ne fonctionne pas, copiez cette adresse dans votre navigateur :',
    ],

];
