<?php

return [
    'entries' => [
        '/tracks/*/speed_kmh' => [
            'label' => 'Vitesse du circuit (km/h)',
            'help' => 'La vitesse moyenne de la voiture de référence sur ce circuit, avec tout ce qui la ralentit : les virages, la surface, les montées et la circulation. Le tour de référence est la longueur du circuit divisée par cette vitesse, et le carburant, le gain et l’XP d’un tour découlent du temps de ce tour.',
            'example' => 'Circuit urbain de Złomowo : 124,8 km/h (2600 m en 75 s), Circuit de l’Autodrome : 144 km/h (3200 m en 80 s), Carrière de Kruszyn : 90,947 km/h (2400 m en 95 s).',
            'range' => 'De 20 à 300 km/h, au plus quatre décimales (d’après le schéma).',
            'tip' => 'Plus le circuit est lent (gravier, sinueux, montagne), plus le tour est long et plus il consomme de carburant au kilomètre, car le moteur travaille plus longtemps. Le gain à l’heure reste celui que vous fixez : changer la vitesse ne change donc pas ce que rapporte une heure, seulement le nombre de kilomètres parcourus en une heure (donc l’usure des pièces et l’entretien). Une vraie voiture roule plus vite ou moins vite selon son adéquation.',
        ],
        '/tracks/*/pay_cents_per_hour' => [
            'label' => 'Gain à l’heure ($)',
            'help' => 'Les dollars que rapporte une heure de conduite de la voiture de référence sur ce circuit par temps sec, avant que le palier de la voiture, la place parmi les bots et la météo ne les modifient. Le gain d’un tour est ce nombre multiplié par le temps du tour de référence : un circuit plus long ne rapporte donc pas moins à l’heure.',
            'example' => 'Circuit urbain de Złomowo : 744 $ de l’heure (15,50 $ pour un tour de 75 s), Sprint de la casse : 600 $, Circuit de l’Autodrome : 1 192,50 $.',
            'range' => 'Au moins 0,01 $ (d’après le schéma).',
            'tip' => 'C’est le principal levier de l’économie d’un circuit : le gain net à l’heure est le gain à l’heure moins le carburant à l’heure. Les ancres de l’économie donnent le revenu net d’une heure pour le niveau du circuit ; ici vous saisissez le brut.',
        ],
        '/tracks/*/xp_per_hour' => [
            'label' => 'XP à l’heure',
            'help' => 'L’XP qu’une heure de conduite sur ce circuit donne au pilote par temps sec. L’XP d’un tour est ce nombre multiplié par le temps du tour de référence, arrondi à l’entier. Une voiture plus rapide en reçoit proportionnellement moins par tour : en une heure, chaque voiture donne donc la même chose.',
            'example' => 'Circuit urbain de Złomowo : 960 XP de l’heure (20 XP par tour), Circuit de l’Autodrome : 900.',
            'range' => 'De 0 à 3600, au plus deux décimales (d’après le schéma).',
            'tip' => 'Sur un circuit très court, l’arrondi à l’XP entier par tour modifie le résultat de dix pour cent ou plus : le validateur avertit quand l’arrondi retire plus d’un dixième.',
        ],
        '/track_model/fuel_ml_per_s/sprint' => [
            'label' => 'Carburant par seconde de tour : sprint',
            'help' => 'Le carburant que brûle le moteur d’une voiture de soif normale à chaque seconde d’un tour sur un circuit du type « sprint ». Le carburant d’un tour de la voiture de référence est ce nombre multiplié par le temps du tour, et une voiture plus gourmande en brûle davantage.',
            'example' => 'Sprint 12 ml (0,012 l) par seconde : le Sprint de la casse (12 s) brûle 144 ml.',
            'range' => 'De 1 à 1000 ml par seconde (d’après le schéma).',
            'tip' => 'Changer ce nombre déplace d’un coup le carburant de tous les circuits de ce type. Un réservoir dure sa capacité ÷ ce nombre, en secondes : 45 l à 12 ml font environ une heure, à 32 ml environ 23 minutes.',
        ],
        '/track_model/fuel_ml_per_s/standard' => [
            'label' => 'Carburant par seconde de tour : standard',
            'help' => 'Le carburant que brûle le moteur d’une voiture de soif normale à chaque seconde d’un tour sur un circuit du type « standard ». Le carburant d’un tour de la voiture de référence est ce nombre multiplié par le temps du tour, et une voiture plus gourmande en brûle davantage.',
            'example' => 'Standard 20 ml par seconde : le circuit urbain de Złomowo (75 s) brûle 1,5 l par tour.',
            'range' => 'De 1 à 1000 ml par seconde (d’après le schéma).',
            'tip' => 'Changer ce nombre déplace d’un coup le carburant de tous les circuits de ce type. Un réservoir dure sa capacité ÷ ce nombre, en secondes : 45 l à 12 ml font environ une heure, à 32 ml environ 23 minutes.',
        ],
        '/track_model/fuel_ml_per_s/endurance' => [
            'label' => 'Carburant par seconde de tour : endurance',
            'help' => 'Le carburant que brûle le moteur d’une voiture de soif normale à chaque seconde d’un tour sur un circuit du type « endurance ». Le carburant d’un tour de la voiture de référence est ce nombre multiplié par le temps du tour, et une voiture plus gourmande en brûle davantage.',
            'example' => 'Endurance 32 ml par seconde : le Périphérique de Złomowo (300 s) brûle 9,6 l par tour.',
            'range' => 'De 1 à 1000 ml par seconde (d’après le schéma).',
            'tip' => 'Changer ce nombre déplace d’un coup le carburant de tous les circuits de ce type. Un réservoir dure sa capacité ÷ ce nombre, en secondes : 45 l à 12 ml font environ une heure, à 32 ml environ 23 minutes.',
        ],
    ],
];
