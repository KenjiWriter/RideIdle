<?php

return [
    'entries' => [
        'simple' => [
            'label' => 'Mode simple',
            'help' => 'Activé : seulement les nombres qui règlent le rythme et l’argent du jeu (prix, récompense d’un tour, durées de l’atelier, niveaux), soit une petite partie des quelque 750. Désactivé : tous les nombres de la section, y compris ceux qu’on touche rarement.',
            'tip' => 'Le mode simple est activé à l’ouverture de la page. Le basculer redessine le formulaire à partir du brouillon enregistré ; enregistrez donc vos modifications avant de basculer.',
        ],
        'section' => [
            'label' => 'Section',
            'help' => 'Les nombres du jeu sont répartis en sections ; une seule est affichée à la fois, avec entre parenthèses son nombre de nombres (en mode simple, seulement les simples) et une phrase sur ce que changer ses nombres fait dans le jeu. Enregistrez avant de changer de section : ce qui n’est pas enregistré est perdu.',
            'options' => [
                'fuel' => ['label' => 'Carburant', 'help' => 'Le prix du litre de carburant ; le coût de chaque tour en dépend.'],
                'repair' => ['label' => 'Réparation sur la route', 'help' => 'Le prix de la réparation après une panne sur le circuit.'],
                'breakdown' => ['label' => 'Pannes', 'help' => 'La probabilité d’une panne sur un tour et l’effet de l’usure sur elle.'],
                'tow' => ['label' => 'Remorquage', 'help' => 'La durée d’un remorquage après une panne et le carburant laissé pour le trajet.'],
                'car' => ['label' => 'Voiture : XP, niveaux, masse', 'help' => 'La vitesse à laquelle les voitures gagnent des niveaux, l’effet de la masse sur la tenue de route et ce que rapportent les voitures des classes supérieures.'],
                'engines' => ['label' => 'Moteurs', 'help' => 'La puissance, la masse et les exigences des moteurs.'],
                'drivetrain' => ['label' => 'Transmission', 'help' => 'La façon dont la traction, la propulsion et l’intégrale tiennent la surface, et leurs limites de puissance.'],
                'garage' => ['label' => 'Garages', 'help' => 'Combien de voitures un joueur garde dans une ville et ce que coûte le départ ; les prix des garages et des épaves de la casse.'],
                'market' => ['label' => 'Marché : bourse, concessionnaire, boxes, caisses', 'help' => 'Les prix des voitures d’occasion, la revente, les chances dans les boxes de stockage et le rythme de renouvellement de la bourse.'],
                'clock' => ['label' => 'Horloge du jeu', 'help' => 'De combien on roule plus lentement la nuit sur les circuits sans éclairage.'],
                'player' => ['label' => 'Joueur : XP et niveaux', 'help' => 'La vitesse à laquelle le joueur gagne des niveaux, qui ouvrent les villes, les circuits et les pièces.'],
                'start' => ['label' => 'Départ', 'help' => 'L’avance d’un nouveau joueur sur le premier tour.'],
                'bots' => ['label' => 'Bots', 'help' => 'Les temps des rivaux par rapport au circuit de référence et les multiplicateurs de récompense selon la place.'],
                'shop' => ['label' => 'Boutique', 'help' => 'Les caisses et la rareté des pièces : chances, puissance et prix.'],
                'affixes' => ['label' => 'Affixes', 'help' => 'La puissance, les chances et les effets des affixes des pièces, y compris ceux qui dépendent de la surface.'],
                'wear' => ['label' => 'Usure et entretien', 'help' => 'La durée de vie des pièces en kilomètres, le coût de la réparation et de l’entretien.'],
                'premium' => ['label' => 'Monnaie premium', 'help' => 'Les prix des packs de RideCoin, l’accélération contre des pièces et le prix de l’avocat.'],
                'workshop' => ['label' => 'Atelier', 'help' => 'Les durées de montage, d’amélioration et de fusion, les prix des postes et des mécaniciens, la longueur de la file.'],
                'tire_matrix' => ['label' => 'Matrice des pneus', 'help' => 'L’adhérence de chaque type de pneu sur chaque surface.'],
                'weather' => ['label' => 'Météo', 'help' => 'La façon dont la météo influe sur un tour.'],
                'regions' => ['label' => 'Villes (nombres)', 'help' => 'La position des villes sur la carte et leurs exigences.'],
                'roads' => ['label' => 'Routes', 'help' => 'Les distances entre les villes en kilomètres, et avec elles la durée et le coût d’un voyage.'],
                'travel' => ['label' => 'Voyages et accélération', 'help' => 'La vitesse et le carburant d’un voyage, et la conduite accélérée avec la police et les amendes.'],
                'tracks' => ['label' => 'Circuits : temps, récompenses, poids', 'help' => 'La longueur, le temps au tour, le carburant, la récompense et l’XP d’un circuit, et les poids de ses statistiques ; cela change directement le gain par heure.'],
            ],
        ],
        'sliders' => [
            'label' => 'Curseurs',
            'help' => 'Activé : un nombre dont le schéma donne les limites a un curseur avec la valeur en infobulle ; les autres sont toujours des champs. Désactivé : tous les nombres sont des champs où l’on saisit la valeur exacte.',
            'tip' => 'Un curseur avance par pas du nombre et ne convient pas aux plages très larges (plus de 2000 pas), où il y a un champ. Le basculer redessine le formulaire à partir du brouillon enregistré ; enregistrez donc vos modifications avant de basculer.',
        ],
    ],
];
