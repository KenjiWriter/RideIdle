<?php

return [
    'entries' => [
        '/units/lap/metres' => [
            'label' => 'Unité du tour : combien de mètres',
            'help' => 'Combien de mètres fait l’unité dans laquelle ce jeu compte la longueur du tour d’un circuit : 1 pour les mètres, 1852 pour le mille marin, 1609,344 pour le mille terrestre. Les nombres des règles et de la base restent en mètres ; l’unité dit dans quoi la longueur d’un circuit est écrite et affichée.',
            'example' => 'Le jeu de voitures : 1 (un mètre). Un jeu de bateaux : 1852 (un mille marin).',
            'range' => 'Un nombre supérieur à 0 (d’après le schéma).',
            'tip' => 'Le changer ne déplace aucun temps ni aucun carburant : cela ne change que ce que voit le joueur et ce qu’écrit l’opérateur.',
        ],
        '/units/travel/metres' => [
            'label' => 'Unité de voyage : combien de mètres',
            'help' => 'Combien de mètres fait l’unité dans laquelle ce jeu compte les routes entre les villes, le compteur d’une voiture, l’intervalle d’entretien, la durée de vie des pièces et le kilométrage des voitures de la bourse : 1000 pour les kilomètres, 1852 pour le mille marin, 1609,344 pour le mille terrestre. Les nombres des règles et de la base restent en kilomètres ; l’unité dit dans quoi les afficher et les écrire.',
            'example' => 'Le jeu de voitures : 1000 (un kilomètre). Un jeu de bateaux : 1852 (un mille marin).',
            'range' => 'Un nombre supérieur à 0 (d’après le schéma).',
            'tip' => 'Une route des règles compte en kilomètres entiers : 20 milles marins font donc 37 km dans le document (37,04 arrondi) et 19,98 milles à l’écran.',
        ],
        '/units/speed/kmh' => [
            'label' => 'Unité de vitesse : combien de km/h',
            'help' => 'Combien de km/h vaut une unité de vitesse dans ce jeu : 1 pour les km/h, 1,852 pour le nœud, 1,609344 pour les miles à l’heure. Les vitesses des règles et des packs restent en km/h ; l’unité dit dans quoi les afficher et les écrire.',
            'example' => 'Le jeu de voitures : 1 (km/h). Un jeu de bateaux : 1,852 (un nœud).',
            'range' => 'Un nombre supérieur à 0 (d’après le schéma).',
        ],
        '/units/lap/decimals' => [
            'label' => 'Tour : décimales',
            'help' => 'Combien de décimales a le nombre quand le joueur le voit dans cette unité (0 est un nombre entier). Cela ne change que l’affichage ; les champs des formulaires ont toujours autant de décimales qu’il en faut pour que ce qui est écrit revienne au même mètre.',
            'example' => 'Un tour en milles marins : 2 décimales (1,4 nm) ; une route en milles marins : 0 (20 nm).',
            'range' => 'De 0 à 4 (d’après le schéma).',
        ],
        '/units/travel/decimals' => [
            'label' => 'Voyage : décimales',
            'help' => 'Combien de décimales a le nombre quand le joueur le voit dans cette unité (0 est un nombre entier). Cela ne change que l’affichage ; les champs des formulaires ont toujours autant de décimales qu’il en faut pour que ce qui est écrit revienne au même mètre.',
            'example' => 'Un tour en milles marins : 2 décimales (1,4 nm) ; une route en milles marins : 0 (20 nm).',
            'range' => 'De 0 à 4 (d’après le schéma).',
        ],
        '/units/speed/decimals' => [
            'label' => 'Vitesse : décimales',
            'help' => 'Combien de décimales a le nombre quand le joueur le voit dans cette unité (0 est un nombre entier). Cela ne change que l’affichage ; les champs des formulaires ont toujours autant de décimales qu’il en faut pour que ce qui est écrit revienne au même mètre.',
            'example' => 'Un tour en milles marins : 2 décimales (1,4 nm) ; une route en milles marins : 0 (20 nm).',
            'range' => 'De 0 à 4 (d’après le schéma).',
        ],
        '/units/fuel_economy/per' => [
            'label' => 'Consommation par combien d’unités de voyage',
            'help' => 'Pour combien d’unités de voyage le joueur voit la consommation de carburant : 100 donne « l/100 km », 1 donne « l par mille marin ». Ce n’est que l’affichage ; les nombres de carburant du jeu ne changent pas.',
            'example' => '100 dans le jeu de voitures, 1 dans un jeu de bateaux.',
            'range' => 'L’un de 1, 10, 100 (d’après le schéma).',
        ],
        '/track_model/advisor/free_speed_kmh/asphalt' => [
            'label' => 'Vitesse libre : asphalte (km/h)',
            'help' => 'La vitesse dont part le calculateur de difficulté du formulaire de circuit pour la surface « asphalte » : celle de la voiture de référence sur un circuit droit et vide. Le jeu ne lit pas ce nombre : seul le bouton « Suggérer une vitesse » le lit.',
            'example' => 'Asphalte 180 km/h.',
            'range' => 'De 20 à 300 km/h (d’après le schéma).',
            'tip' => 'Le changer ne déplace aucun circuit existant ; cela ne change que ce que le calculateur suggérera la prochaine fois.',
        ],
        '/track_model/advisor/free_speed_kmh/gravel' => [
            'label' => 'Vitesse libre : gravier (km/h)',
            'help' => 'La vitesse dont part le calculateur de difficulté du formulaire de circuit pour la surface « gravier » : celle de la voiture de référence sur un circuit droit et vide. Le jeu ne lit pas ce nombre : seul le bouton « Suggérer une vitesse » le lit.',
            'example' => 'Gravier 130 km/h.',
            'range' => 'De 20 à 300 km/h (d’après le schéma).',
            'tip' => 'Le changer ne déplace aucun circuit existant ; cela ne change que ce que le calculateur suggérera la prochaine fois.',
        ],
        '/track_model/advisor/corner_loss_kmh' => [
            'label' => 'Vitesse perdue par virage au kilomètre (km/h)',
            'help' => 'Combien de km/h le calculateur de difficulté retire pour chaque virage serré par kilomètre de circuit. Le jeu ne lit pas ce nombre : seul le bouton « Suggérer une vitesse » le lit.',
            'example' => '10 km/h : un circuit de 4 virages au kilomètre perd 40 km/h.',
            'range' => 'De 0 à 100 km/h (d’après le schéma).',
        ],
        '/track_model/advisor/climb_loss_kmh' => [
            'label' => 'Vitesse perdue par 10 m de dénivelé au kilomètre (km/h)',
            'help' => 'Combien de km/h le calculateur de difficulté retire pour chaque tranche de 10 mètres de dénivelé par kilomètre de circuit. Le jeu ne lit pas ce nombre : seul le bouton « Suggérer une vitesse » le lit.',
            'example' => '5 km/h : un circuit qui monte de 20 m au kilomètre perd 10 km/h.',
            'range' => 'De 0 à 100 km/h (d’après le schéma).',
        ],
        '/track_model/advisor/traffic_loss_kmh' => [
            'label' => 'Vitesse perdue par niveau de circulation (km/h)',
            'help' => 'Combien de km/h le calculateur de difficulté retire pour chaque niveau de circulation et de passages étroits (0 est un circuit vide, 3 est serré et encombré). Le jeu ne lit pas ce nombre : seul le bouton « Suggérer une vitesse » le lit.',
            'example' => '5 km/h : une circulation de niveau 2 retire 10 km/h.',
            'range' => 'De 0 à 100 km/h (d’après le schéma).',
        ],
    ],
];
