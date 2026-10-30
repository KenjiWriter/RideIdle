<?php

return [
    'entries' => [
        'world' => [
            'label' => 'Comment fonctionne le formulaire du monde',
            'help' => 'Vous réglez ici les lieux où les voitures gagnent : les villes avec leur météo, les routes qui les relient et les circuits. Ce que vous saisissez est enregistré en brouillon des règles et n’atteint les joueurs qu’avec le prochain pack publié.',
            'steps' => [
                'Onglet Villes : ajoutez une ville avec un slug, des noms dans les langues, une place sur la carte et les chances de sa météo (100% en tout).',
                'Onglet Routes : reliez les villes par des routes et donnez leur longueur en km ; chaque ville doit être atteignable depuis la première.',
                'Onglet Circuits : ajoutez un circuit dans une ville : revêtement, genre, niveau, longueur du tour, tour de référence, carburant, gain et XP.',
                'Réglez ce que le circuit demande à une voiture (des pourcentages qui font 100) et la valeur de référence de chacune de ces statistiques.',
                'Enregistrez le brouillon ; les changements atteignent les joueurs avec le prochain pack publié (page Publications).',
            ],
            'example' => 'Un sprint sur gravier à Kruszyn, comme dans le jeu de voitures : slug kruszyn-gravel-sprint, revêtement Gravier, genre Sprint, niveau 3, 380 m, tour de référence 15 000 ms (15 s), carburant 180 ml, gain 2,92 $, XP 3 ; il demande Accélération 20, Adhérence 30, Tenue de route 15, Freinage 10, Tout-terrain 25 (références 65, 92, 71, 71, 80).',
        ],
        'game' => [
            'label' => 'Comment fonctionne le formulaire du jeu',
            'help' => 'C’est un jeu de l’installation : son nom, son adresse, sa langue, sa couleur, ses icônes et s’il est en ligne. Le jeu lui-même (voitures, règles, textes) se modifie sur les autres pages ; ce formulaire n’est que son identité.',
            'steps' => [
                'Changez le nom, la langue ou la couleur ; la barre au-dessus du formulaire et l’aperçu montrent l’allure du jeu avant l’enregistrement.',
                'Ajoutez ou changez le domaine, puis ouvrez la page « Domaine et SSL » : le DNS doit pointer vers ce serveur avant qu’un certificat soit délivré.',
                'Téléversez une icône carrée (et, si vous voulez, une avec marge pour les téléphones qui découpent les icônes).',
                'Mettez le statut En ligne quand le contenu et le domaine sont prêts ; un brouillon ne répond à personne.',
                'Enregistrez. Le slug et le jeu par défaut ne se changent pas ici.',
            ],
            'example' => 'Vaisseaux Spatiaux sur ships.example.com : langue français, couleur #1a2b4c, une icône de 512 × 512, statut En ligne.',
        ],
        'wizard' => [
            'label' => 'Comment l’assistant crée un jeu',
            'help' => 'Un jeu se crée en trois étapes : son nom et son adresse, le contenu dont il part, et ce que le serveur fait du domaine. Il démarre en brouillon, qui ne répond à personne tant que vous ne le mettez pas en ligne ; le slug, les couleurs, les icônes et l’apparence se règlent plus tard.',
            'steps' => [
                'Nom et domaine : saisissez le nom (le slug en est proposé), le domaine si vous en avez un, la langue et la couleur.',
                'Point de départ : choisissez le jeu de voitures comme modèle, une copie d’un autre jeu ou aucun contenu.',
                'Domaine et SSL : vérifiez que le domaine pointe vers ce serveur et, si vous le voulez, demandez au serveur de le mettre en place tout de suite.',
                'Appuyez sur « Créer le jeu ». Le panneau vous mène à la page « Domaine et SSL » du nouveau jeu et passe sur lui.',
                'Quand le contenu et le domaine sont prêts, mettez le statut En ligne en modifiant le jeu.',
            ],
            'example' => 'Nom « Vaisseaux Spatiaux », domaine ships.example.com, langue français, départ depuis le jeu de voitures, domaine demandé : un jeu en brouillon avec le pack en ligne du jeu de voitures et une demande qui attend le serveur.',
        ],
    ],
];
