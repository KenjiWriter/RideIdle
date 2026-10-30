<?php

return [
    'entries' => [
        'start' => [
            'label' => 'Contenu de départ',
            'help' => 'Avec quoi le nouveau jeu démarre. Le jeu est créé en brouillon dans tous les cas ; le premier pack est mis en ligne tout de suite quand il y a un contenu de départ.',
            'tip' => 'Si le contenu ne peut pas être mis en place (le jeu copié n’a pas de pack en ligne, ou le contenu échoue aux contrôles), le jeu est quand même créé, sans contenu, et le panneau le signale ; rien n’est perdu.',
            'options' => [
                'template' => ['label' => 'Le jeu de voitures (modèle)', 'help' => 'Les voitures, pièces, circuits, règles, tutoriel et textes du jeu de voitures du dépôt deviennent le premier pack, en ligne tout de suite ; le plus simple est de partir de quelque chose qui marche déjà et de le modifier.'],
                'copy' => ['label' => 'Une copie d’un autre jeu', 'help' => 'Le pack que le jeu choisi joue actuellement devient le premier pack du nouveau (sans ses joueurs, ses comptes ni ses brouillons non publiés).'],
                'none' => ['label' => 'Sans contenu', 'help' => 'Un jeu vide. Il a besoin d’un pack avant de pouvoir être mis en ligne.'],
            ],
        ],
        'copy_from' => [
            'label' => 'Quel jeu copier',
            'help' => 'Le jeu dont le pack en ligne devient le premier pack du nouveau. Seul le pack que ce jeu joue actuellement est copié : pas ses brouillons, ses joueurs ni ses comptes.',
            'tip' => 'Si le jeu choisi n’a pas de pack en ligne, le nouveau jeu est créé sans contenu et le panneau en avertit.',
        ],
        'request_domain' => [
            'label' => 'Mettre en place le domaine et le SSL maintenant',
            'help' => 'Une fois le jeu créé, demander au serveur de mettre en place le domaine (l’entrée du serveur web) et le certificat SSL. Le serveur prend la demande en charge en moins d’une minute, et vous pouvez la suivre sur la page « Domaine et SSL ».',
            'tip' => 'Le domaine devrait déjà pointer vers ce serveur (utilisez « Vérifier le DNS » ci-dessus), et l’installation a besoin d’une adresse e-mail pour Let’s Encrypt (ACME_EMAIL) ; sans elles, l’interrupteur n’apparaît pas ou la demande est refusée, et le jeu est quand même créé. Vous pourrez redemander plus tard sur la page « Domaine et SSL ».',
        ],
        'units' => [
            'label' => 'Système d’unités',
            'help' => 'Dans quoi le nouveau jeu compte la distance et la vitesse : ce que l’opérateur écrit et ce que le joueur voit (les nombres des règles restent en mètres, kilomètres et km/h). Cela se change ensuite dans le studio (« Unités du jeu ») et dans le texte des règles.',
            'tip' => 'Cela ne vaut que pour un jeu qui part du modèle ; une copie d’un autre jeu a les unités de celui-ci, et un jeu sans contenu n’en a aucune.',
            'options' => [
                'metric' => ['label' => 'Métrique', 'help' => 'Des mètres sur un circuit, des kilomètres sur la route, des km/h, la consommation en l/100 km : le jeu de voitures.'],
                'nautical' => ['label' => 'Marin', 'help' => 'Des milles marins sur un circuit et sur la route, des nœuds, la consommation en litres par mille marin : un jeu de bateaux.'],
                'imperial' => ['label' => 'Milles', 'help' => 'Des milles sur un circuit et sur la route, des milles à l’heure, la consommation en litres pour 100 milles.'],
            ],
        ],
    ],
];
