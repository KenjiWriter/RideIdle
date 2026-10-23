<?php

return [

    'account_link' => [
        'claim' => [
            'subject' => 'Confirmez votre e-mail pour sécuriser votre garage',
            'intro' => 'Quelqu’un a demandé à sécuriser le garage de :driver avec cet e-mail. Si c’était vous, confirmez-le ci-dessous.',
            'action' => 'Confirmer l’e-mail',
        ],
        'add' => [
            'subject' => 'Confirmez votre e-mail pour l’ajouter à votre garage',
            'intro' => 'Quelqu’un a demandé à ajouter cet e-mail comme moyen de connexion au garage de :driver. Si c’était vous, confirmez-le ci-dessous.',
            'action' => 'Confirmer l’e-mail',
        ],
        'login' => [
            'subject' => 'Votre lien de connexion à RideIdle',
            'intro' => 'Utilisez le bouton ci-dessous pour vous connecter au garage de :driver.',
            'action' => 'Se connecter',
        ],
        'existing' => [
            'subject' => 'Cet e-mail a déjà un garage',
            'intro' => 'Quelqu’un a essayé de sécuriser un nouveau garage avec cet e-mail, mais il appartient déjà au garage de :driver. Si c’était vous et que vous voulez utiliser ce garage sur cet appareil, connectez-vous ci-dessous.',
            'action' => 'Se connecter',
        ],
        'expires' => 'Le lien fonctionne une seule fois et pendant :minutes minutes.',
        'ignore' => 'Si ce n’était pas vous, ignorez ce message : rien ne change sans le lien.',
        'fallback' => 'Si le bouton ne fonctionne pas, copiez cette adresse dans votre navigateur :',
    ],

];
