<?php

return [
    'entries' => [
        'tutorial' => [
            'label' => 'Comment fonctionne le tutoriel',
            'help' => 'Le tutoriel guide un nouveau pilote à travers les premières minutes, le premier jour et la première semaine du jeu par des SMS du mentor (K.). Les modifications sont d’abord enregistrées en brouillon et n’arrivent dans le jeu qu’avec le prochain paquet publié.',
            'steps' => [
                'En haut, réglez « La première sortie » : le carburant au départ, le tour de la première panne, la durée maximale du premier travail, la somme que couvre le mentor et (en option) l’annonce à la bourse.',
                'Ajoutez une étape : donnez-lui une clé fixe, une phase et le numéro du mentor, puis choisissez quand elle commence et quand elle finit (des faits sur le pilote, par exemple lap_completed ou cash:450).',
                'Écrivez l’objectif de la barre d’objectif, jusqu’à deux messages du mentor et les mots du bouton de réponse ; ajoutez au besoin ce que l’étape attend. Une étape sans message est exécutée sans être montrée.',
                'Choisissez ce que l’écran met en avant et les travaux d’atelier que l’étape raccourcit ; au besoin, réglez une pièce pour le début de l’étape et une récompense pour la fin.',
                'Ne changez pas les clés et ne retirez pas les étapes où des joueurs se trouvent : la publication d’un tel paquet est refusée.',
                'Enregistrez le brouillon, relisez-le sur la page « Modifications à publier » et demandez un paquet sur la page « Publications ».',
            ],
            'example' => 'Étape « exchange » (phase 3) : début cash:450 (elle attend que le pilote puisse se payer une voiture), fin car_bought, elle met en avant la bourse, et l’annonce du tutoriel (Rdzak GT-2, 25 ans, 280 000 km, état 30 %, 450 $) attend dans sa ville ; après l’achat, le mentor rend 300 $.',
        ],
    ],
];
