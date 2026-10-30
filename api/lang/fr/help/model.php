<?php

return [
    'entries' => [
        'slots' => [
            'label' => 'Emplacements',
            'help' => 'Les emplacements de la voiture où se montent les pièces, dans l’ordre où le garage et la boutique les listent. De 1 à 24 emplacements ; faites glisser une ligne pour changer l’ordre.',
            'range' => 'De 1 à 24 emplacements.',
            'tip' => 'Un emplacement qu’une pièce ou une règle nomme encore ne peut pas être supprimé : l’enregistrement est refusé, avec la liste des endroits qui l’utilisent. Une publication n’enlèvera pas non plus un emplacement dans lequel des joueurs ont des pièces ; changer la clé d’un emplacement utilisé est donc bloqué.',
        ],
        'key' => [
            'label' => 'Clé de l’emplacement',
            'help' => 'Le nom que l’emplacement porte dans les pièces, les règles et les voitures des joueurs. Le nom affiché dans le jeu (par exemple « Pneus ») est donné par le Vocabulaire sous la forme slot.clé ; pour une nouvelle clé, il faut l’y écrire (anglais et polonais).',
            'example' => 'tires, suspension, brakes, engine, boost, gearbox, exhaust, cooling, tank, ecu (le modèle du jeu de voitures).',
            'range' => '32 caractères au plus : lettres minuscules, chiffres et _, en commençant par une lettre, sans doublon.',
        ],
        'role' => [
            'label' => 'Rôle de l’emplacement',
            'help' => 'Dit aux formules du jeu ce qu’est cet emplacement dans ce jeu, quelle que soit sa clé : un emplacement au rôle « Pneus » décide de l’adhérence même s’il s’appelle « coque ». Un rôle appartient à un seul emplacement, et le jeu doit avoir un emplacement au rôle des pneus. Sans rôle, un emplacement contient des pièces dont les formules lisent déjà les statistiques (comme l’échappement).',
            'tip' => 'Les pièces d’un emplacement au rôle des pneus doivent avoir un type de pneu, et celles d’un emplacement au rôle du moteur un moteur (architecture et cylindrée) ; dans les autres emplacements, les pièces n’ont pas ces champs.',
            'options' => [
                'tires' => ['label' => 'Pneus', 'help' => 'L’adhérence, la tenue de route et le freinage partent d’ici, et le type de pneu choisit la colonne de la matrice des surfaces. Rôle obligatoire.'],
                'suspension' => ['label' => 'Suspension', 'help' => 'La tenue de route et la garde au sol (aptitude tout-terrain) de la voiture.'],
                'brakes' => ['label' => 'Freins', 'help' => 'Le freinage de la voiture.'],
                'engine' => ['label' => 'Moteur', 'help' => 'La puissance et la masse de la voiture ; une pièce de cet emplacement remplace le moteur d’origine.'],
                'boost' => ['label' => 'Suralimentation', 'help' => 'Le gain de puissance, le temps de réponse et la charge du moteur.'],
                'gearbox' => ['label' => 'Boîte de vitesses', 'help' => 'La limite de vitesse maximale et le démarrage.'],
                'cooling' => ['label' => 'Refroidissement', 'help' => 'L’intercooler et le radiateur : ils décident de ce que la suralimentation perd.'],
                'tank' => ['label' => 'Réservoir', 'help' => 'La capacité du réservoir, c’est-à-dire la distance que parcourt la voiture.'],
            ],
        ],
        'hidden' => [
            'label' => 'Masqué dans le garage',
            'help' => 'Un emplacement masqué peut toujours contenir des pièces et a sa place dans les règles, mais le garage ne le liste pas (dans le jeu de voitures, c’est l’emplacement « ecu »). Un emplacement masqué n’a pas besoin de nom dans le Vocabulaire.',
        ],
        'stats' => [
            'label' => 'Statistiques',
            'help' => 'Les statistiques d’une voiture que montrent les écrans du garage et de l’atelier, dans l’ordre de la liste. Une statistique omise n’est pas montrée, et on ne peut pas en ajouter, car ce sont les formules qui les produisent.',
            'tip' => 'La liste ne change que ce qu’on voit et le score global de la voiture (la moyenne des barres). Aucune formule de course ne la lit : omettre une statistique ne change donc pas la conduite.',
        ],
        'stat' => [
            'label' => 'Statistique',
            'help' => 'La statistique à montrer à cet endroit de la liste. Chacune ne peut apparaître qu’une fois.',
            'options' => [
                'accel' => ['label' => 'Accélération', 'help' => 'La puissance de la voiture par tonne : plus elle est grande, plus la voiture démarre et prend de la vitesse vite.'],
                'top_speed' => ['label' => 'Vitesse max.', 'help' => 'Croît avec la puissance (avec la racine cubique du gain de puissance) et ne dépasse pas la limite de la boîte.'],
                'grip' => ['label' => 'Adhérence', 'help' => 'Vient des pneus : la fermeté avec laquelle la voiture tient la route.'],
                'handling' => ['label' => 'Tenue de route', 'help' => 'Des pneus et de la suspension, multipliée par le facteur de masse : une voiture lourde tourne moins bien.'],
                'braking' => ['label' => 'Freinage', 'help' => 'Des pneus et des freins, multiplié par le facteur de masse : une voiture lourde freine moins bien.'],
                'clearance' => ['label' => 'Tout-terrain', 'help' => 'La garde au sol : part de la carrosserie et est moyennée avec la suspension.'],
            ],
        ],
    ],
];
