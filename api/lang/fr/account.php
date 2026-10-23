<?php

return [

    'recovery' => [
        'invalid' => 'Ce code de récupération n’est pas valide.',
        'locked' => 'Trop de tentatives échouées. Réessayez plus tard.',
    ],

    'email' => [
        'sent' => 'Nous avons envoyé un lien à :email. Il fonctionne une seule fois, pendant :minutes minutes.',
        'already_linked' => 'Ce compte a déjà un e-mail.',
    ],

    'login_link' => [
        'sent' => 'Si cet e-mail appartient à un garage, un lien est en route. Il fonctionne une seule fois, pendant :minutes minutes.',
    ],

    'oauth' => [
        'unavailable' => 'Ce mode de connexion n’est pas disponible.',
    ],

    'token' => [
        'invalid' => 'Ce lien n’est pas valide ou a expiré.',
    ],

    'identity' => [
        'taken' => 'Cette connexion appartient déjà à un autre garage. Connectez-vous à ce garage ou utilisez-en une autre.',
        'provider_linked' => 'Ce compte a déjà une connexion de ce type.',
    ],

    'name' => [
        'attribute' => 'nom du pilote',
        'taken' => 'Ce nom de pilote est déjà pris.',
    ],

];
