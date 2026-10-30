<?php

return [
    'entries' => [
        'slug' => [
            'label' => 'Slug du jeu',
            'help' => 'Le nom court par lequel les commandes, le panneau et le journal désignent ce jeu. Vous le choisissez une seule fois, à la création du jeu.',
            'example' => 'ships, raceidle',
            'range' => '2 à 32 caractères : lettres minuscules, chiffres et tirets, une lettre en premier ; différent pour chaque jeu.',
            'tip' => 'Il ne peut pas être changé ensuite, car les commandes (--game=slug), les scripts de déploiement, le journal d’audit et le sélecteur de jeu du panneau nomment le jeu ainsi. Dans l’assistant, il est proposé à partir du nom ; vous pouvez le corriger avant de continuer.',
        ],
        'name' => [
            'label' => 'Nom du jeu',
            'help' => 'Le nom du jeu : sur l’onglet du navigateur, dans la barre de l’appli, sous l’icône sur l’écran d’accueil d’un téléphone (seuls les premiers mots qui tiennent en 12 lettres), dans les e-mails et sur l’écran de démarrage.',
            'example' => 'Vaisseaux Spatiaux',
            'range' => 'Jusqu’à 64 caractères.',
        ],
        'domain' => [
            'label' => 'Domaine',
            'help' => 'L’adresse sur laquelle le jeu répond, en minuscules, sans http:// et sans chemin. Qui ouvre cette adresse arrive dans ce jeu. Sans domaine, le jeu n’est joignable que depuis le panneau (sauf s’il est le seul jeu de l’installation).',
            'example' => 'ships.example.com',
            'range' => 'Au moins deux parties (jeu.example.com), jusqu’à 190 caractères ; différent pour chaque jeu.',
            'tip' => 'Avant qu’un certificat puisse être délivré, le domaine doit pointer vers ce serveur (un enregistrement A, AAAA pour IPv6). Après l’avoir saisi ou changé, ouvrez la page « Domaine et SSL » du jeu : elle vérifie le DNS et demande au serveur de mettre l’adresse et le certificat SSL en place. Un jeu en brouillon ne répond pas sur son domaine, même alors.',
        ],
        'locale' => [
            'label' => 'Langue du jeu',
            'help' => 'La langue dans laquelle les nouveaux joueurs voient le jeu jusqu’à ce qu’ils choisissent la leur ; c’est aussi la langue de la page et de l’appli du jeu quand la langue du visiteur n’est pas l’une de celles du jeu.',
            'options' => [
                'pl' => ['label' => 'Polski', 'help' => 'Les nouveaux joueurs voient le jeu en polonais jusqu’à ce qu’ils choisissent une autre langue.'],
                'en' => ['label' => 'English', 'help' => 'Les nouveaux joueurs voient le jeu en anglais jusqu’à ce qu’ils choisissent une autre langue.'],
                'fr' => ['label' => 'Français', 'help' => 'Les nouveaux joueurs voient le jeu en français jusqu’à ce qu’ils choisissent une autre langue.'],
                'de' => ['label' => 'Deutsch', 'help' => 'Les nouveaux joueurs voient le jeu en allemand jusqu’à ce qu’ils choisissent une autre langue.'],
                'pt-BR' => ['label' => 'Português (Brasil)', 'help' => 'Les nouveaux joueurs voient le jeu en portugais du Brésil jusqu’à ce qu’ils choisissent une autre langue.'],
            ],
        ],
        'theme_color' => [
            'label' => 'Couleur du cadre',
            'help' => 'La couleur du cadre autour du jeu : la barre du navigateur et du téléphone, l’écran de démarrage de l’appli installée et l’aperçu du lien. Les couleurs à l’intérieur du jeu se règlent sur la page « Marque et page ».',
            'example' => '#0e1013 (le sombre du jeu de voitures)',
            'range' => 'Six chiffres hexadécimaux précédés de #, par exemple #0e1013.',
        ],
        'icon_upload' => [
            'label' => 'Icône',
            'help' => 'Une image carrée (PNG, WebP ou JPEG), d’au moins :min × :min pixels, idéalement 512 : l’icône de l’appli sur l’écran d’accueil et dans l’onglet du navigateur. Sans elle, le jeu garde l’icône du jeu de voitures.',
            'range' => 'Carrée, d’au moins :min px de côté, jusqu’à 2048 px et 1 Mo.',
            'tip' => 'Un fichier choisi ne remplace l’icône actuelle qu’à l’enregistrement du jeu ; une image qui n’est pas carrée ou qui fait moins de :min px de côté est refusée et rien n’est enregistré.',
        ],
        'icon_remove' => [
            'label' => 'Retirer l’icône',
            'help' => 'À l’enregistrement, le jeu revient à l’icône du jeu de voitures.',
        ],
        'icon_maskable_upload' => [
            'label' => 'Icône avec marge',
            'help' => 'La même image dessinée avec une large marge, pour que l’essentiel reste dans les 80% du centre : pour les téléphones qui découpent les icônes en cercles ou en carrés arrondis. Facultative ; sans elle, l’icône ci-dessus est utilisée telle quelle. Carrée, d’au moins :min px.',
        ],
        'icon_maskable_remove' => [
            'label' => 'Retirer l’icône avec marge',
            'help' => 'À l’enregistrement, les téléphones utilisent de nouveau l’icône simple d’en haut.',
        ],
        'status' => [
            'label' => 'Statut',
            'help' => 'Si le jeu laisse entrer les joueurs. Un brouillon ne répond pas sur son domaine ; mettez le jeu en ligne quand son contenu (un pack) et son domaine sont prêts.',
            'tip' => 'Un jeu vide a besoin d’un pack avant de pouvoir être mis en ligne. Un nouveau jeu démarre toujours en brouillon.',
            'options' => [
                'live' => ['label' => 'En ligne', 'help' => 'Le jeu répond sur son domaine, et les joueurs peuvent créer des comptes et jouer.'],
                'draft' => ['label' => 'Brouillon', 'help' => 'Le jeu est en cours de création : il ne répond à personne sur son domaine et on ne peut y travailler que depuis le panneau.'],
            ],
        ],
        'is_default' => [
            'label' => 'Jeu par défaut',
            'help' => 'Le jeu qui répond là où rien ne dit lequel est visé : les commandes sans --game et un hôte inconnu en développement. On ne le change pas ici.',
            'tip' => 'Sur un serveur de production, une adresse qu’aucun jeu en ligne ne revendique ne répond à personne, pas au jeu par défaut. Tant que le jeu par défaut n’a pas de pack en ligne, il joue à partir des fichiers de contenu du dépôt.',
        ],
    ],
];
