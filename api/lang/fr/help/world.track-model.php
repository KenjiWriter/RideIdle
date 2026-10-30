<?php

return [
    'entries' => [
        'speed_kmh' => [
            'label' => 'Vitesse du circuit',
            'help' => 'La vitesse moyenne de la voiture de référence sur ce circuit, avec tout ce qui la ralentit : les virages, la surface, les montées et la circulation. Le tour de référence est la longueur divisée par cette vitesse ; le carburant, le gain et l’XP d’un tour découlent de son temps. Une vraie voiture roule plus vite ou moins vite selon son adéquation au circuit.',
            'example' => 'Circuit urbain de Złomowo : 124,8 km/h (2600 m en 75 s) ; Circuit de l’Autodrome : 144 km/h ; Carrière de Kruszyn (gravier) : environ 91 km/h.',
            'range' => 'De 20 à 300 km/h, au plus quatre décimales.',
            'tip' => 'Vous ne savez pas quoi saisir ? Le bouton « Suggérer une vitesse » la calcule à partir des virages, des montées et de la circulation. Un circuit plus lent, c’est un tour plus long et plus de carburant au kilomètre ; le gain à l’heure reste celui que vous fixez.',
        ],
        'pay_per_hour' => [
            'label' => 'Gain à l’heure ($)',
            'help' => 'Les dollars que rapporte une heure de conduite de la voiture de référence sur ce circuit par temps sec, avant que le palier de la voiture, la place parmi les bots et la météo ne les modifient. Vous saisissez des dollars ; le jeu garde des cents. Le gain d’un tour découle de ce nombre et du temps du tour : un circuit plus long ne rapporte donc pas moins à l’heure.',
            'example' => '744 $ (circuit urbain de Złomowo : 15,50 $ pour un tour de 75 s), 600 $ (Sprint de la casse), 1 192,50 $ (Circuit de l’Autodrome).',
            'range' => 'Au moins 0,01 $.',
            'tip' => 'Le gain net à l’heure est le gain à l’heure moins le carburant à l’heure : c’est ce que comparent les contrôles d’équilibre entre les circuits, si bien qu’un nouveau gain change le meilleur circuit à un niveau donné. La pluie, l’averse et la neige rapportent 7 % de moins (aujourd’hui).',
        ],
        'xp_per_hour' => [
            'label' => 'XP à l’heure',
            'help' => 'L’expérience qu’une heure de conduite sur ce circuit donne par temps sec : elle fait monter le niveau du pilote, qui ouvre de nouvelles villes, de nouveaux circuits et de nouvelles pièces. L’XP d’un tour découle de ce nombre et du temps du tour, arrondi à l’entier. Une voiture plus rapide en reçoit proportionnellement moins par tour : en une heure, chaque voiture donne donc la même XP.',
            'example' => '960 XP (circuit urbain de Złomowo, 20 XP par tour), 900 XP (Circuit de l’Autodrome et les sprints).',
            'range' => 'De 0 à 3600, au plus deux décimales.',
            'tip' => 'Sur un circuit très court, l’arrondi de l’XP d’un tour à l’entier peut changer le résultat jusqu’à un cinquième ; par temps humide, c’est 7 % de moins.',
        ],
    ],
];
