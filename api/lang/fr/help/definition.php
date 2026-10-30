<?php

return [
    'entries' => [
        'slug' => [
            'label' => 'Slug (identifiant)',
            'help' => 'L’identifiant permanent de la voiture ou de la pièce : minuscules et chiffres reliés par des tirets. Vous le choisissez une seule fois, à la création ; ensuite il ne peut plus changer et n’est jamais réutilisé, même après l’archivage de la définition.',
            'example' => 'rdzak-gt-4, turbo-osemka-t2.',
            'range' => '64 caractères au plus : a–z, 0–9 et tirets simples entre les mots.',
            'tip' => 'Le pack, les règles (liste de la boutique, pneus d’origine), le tutoriel et les voitures des joueurs désignent une voiture ou une pièce par son slug. C’est pourquoi le nom affiché peut changer, mais pas le slug.',
        ],
        'name' => [
            'label' => 'Nom',
            'help' => 'Le nom que voient les joueurs, une version par langue du jeu. L’anglais et le polonais sont obligatoires ; une langue laissée vide affiche le texte anglais.',
            'example' => 'Rdzak GT-4 ; Pneus routiers (fr) et Street tires (en).',
            'range' => '200 caractères au plus dans chaque langue.',
        ],
        'description' => [
            'label' => 'Description',
            'help' => 'Un court texte pour le joueur, affiché aujourd’hui sur l’écran où l’on choisit l’épave de départ. Il est facultatif ; une voiture sans description n’en a simplement pas.',
            'range' => '200 caractères au plus dans chaque langue.',
        ],
        'image_upload' => [
            'label' => 'Téléverser une image',
            'help' => 'Une image de la voiture ou de la pièce, affichée sur ses cartes partout où le jeu n’a pas la sienne. Sans image, la définition garde son aspect actuel.',
            'range' => 'PNG, WebP ou JPEG, fichier de 1 Mo au plus, chaque côté de :min à :max pixels.',
            'tip' => 'Le fichier est conservé dans la base sous la somme de contrôle de ses octets, et la définition ne fait que le nommer. Il arrive dans le jeu avec le prochain pack, et un pack enregistré sous forme de fichier l’emporte avec lui.',
        ],
        'image_remove' => [
            'label' => 'Retirer l’image',
            'help' => 'Activez-le et enregistrez le brouillon pour que la voiture ou la pièce n’ait plus d’image. Le réglage n’apparaît que si une image est définie.',
        ],
    ],
];
