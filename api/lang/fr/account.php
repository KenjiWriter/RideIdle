<?php

return [

    'recovery' => [
        'invalid' => 'Ce code de récupération n’est pas valide.',
        'locked' => 'Trop de tentatives échouées. Réessayez plus tard.',
    ],

    'email' => [
        'sent' => 'Nous avons envoyé un lien à :email. Il n’est utilisable qu’une fois et reste valable :minutes minutes.',
        'already_linked' => 'Ce compte a déjà une adresse e-mail.',
    ],

    'login_link' => [
        'sent' => 'Si cette adresse e-mail est associée à un garage, un lien vous a été envoyé. Il n’est utilisable qu’une fois et reste valable :minutes minutes.',
    ],

    'oauth' => [
        'unavailable' => 'Ce mode de connexion n’est pas disponible.',
        'ticket_invalid' => 'Cette connexion a expiré ou a déjà été utilisée. Recommencez.',
        'no_account' => 'Aucun garage n’est lié à ce compte :provider. Commencez à jouer en invité et liez le compte depuis votre garage.',
        'wrong_account' => 'Cette connexion a été démarrée pour un autre garage. Recommencez depuis le garage que vous voulez lier.',
    ],

    'delete' => [
        'name_mismatch' => 'Saisissez le nom de votre pilote exactement comme il est écrit pour supprimer le compte.',
    ],

    'token' => [
        'invalid' => 'Ce lien n’est pas valide ou a expiré.',
    ],

    'identity' => [
        'taken' => 'Ce moyen de connexion est déjà associé à un autre garage. Connectez-vous à ce garage ou utilisez-en un autre.',
        'provider_linked' => 'Ce compte a déjà un moyen de connexion de ce type.',
    ],

    'name' => [
        'attribute' => 'nom du pilote',
        'taken' => 'Ce nom de pilote est déjà pris.',
    ],

];
