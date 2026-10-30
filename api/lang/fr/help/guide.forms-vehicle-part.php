<?php

return [
    'entries' => [
        'vehicle' => [
            'label' => 'Comment fonctionne le formulaire de voiture',
            'help' => 'Ici, vous créez une nouvelle voiture ou modifiez une voiture existante. L’enregistrement produit un brouillon : il est vérifié d’après le schéma et enregistré seulement s’il passe, mais il n’arrive chez les joueurs qu’avec le prochain pack que vous publiez.',
            'example' => 'Rdzak GT-4 : compacte, traction avant, thermique, R4 1,8 l avec réglage 1,0, 97 kW, 1000 kg, 175 km/h, garde au sol 20, charge utile 120 kg, 4 places, réservoir de 45 l, tier 2, niveau 3, prix 21 500 $, sources : épave de départ et bourse.',
            'steps' => [
                'Saisissez le slug (identifiant permanent, choisi une seule fois) et le nom ; l’anglais et le polonais sont obligatoires.',
                'Choisissez la classe, la transmission et la motorisation ; pour une thermique, indiquez le moteur d’origine (architecture, cylindrée, réglage) et le réservoir.',
                'Saisissez la puissance, la masse, la vitesse maximale, la garde au sol, la charge utile et les places ; la puissance et la masse changent le plus la conduite.',
                'Choisissez le tier : le niveau de joueur doit valoir 3 × tier − 3 (au moins 1). Fixez le prix en dollars et cochez les sources : épave de départ, bourse, concessionnaire.',
                'Enregistrez le brouillon ; les erreurs du schéma s’affichent dans une notification. Publiez ensuite un pack : il passe les barrières du simulateur, qui surveillent l’équilibre.',
            ],
        ],
        'part' => [
            'label' => 'Comment fonctionne le formulaire de pièce',
            'help' => 'Ici, vous créez une nouvelle pièce ou modifiez une pièce existante. L’enregistrement produit un brouillon vérifié d’après le schéma, qui arrive dans le jeu avec le prochain pack publié ; une pièce n’entre en boutique que si elle figure sur la liste de la boutique dans les règles (Règles en texte, shop.parts).',
            'example' => 'Pneus routiers T2 : emplacement Pneus, type Routiers, tier 2, prix 570 $, adhérence 100, tenue de route 98, freinage 100. Turbo Ósemka T2 : emplacement Suralimentation, tier 2, prix 4 200 $, puissance 1,25, temps de réponse 3, consommation 1,15, charge 8, au moins 4 cylindres.',
            'steps' => [
                'Saisissez le slug et le nom dans les langues ; l’anglais et le polonais sont obligatoires.',
                'Choisissez l’emplacement : son rôle dit ce que fait la pièce. Pour des pneus, choisissez le type ; pour un moteur, l’architecture et la cylindrée.',
                'Choisissez le tier et indiquez le prix en dollars ; les prix de la plupart des pièces sont comparés à la courbe de l’économie (±20 %).',
                'Ajoutez des statistiques sous forme de lignes « un nom et un nombre » (par exemple adhérence 100, puissance 1,25) ; les multiplicateurs ont 1 pour « sans changement ».',
                'Si besoin, ouvrez « Ce qu’elle exige et à quoi elle convient » : cylindres minimum, suralimentation, motorisation, classes.',
                'Enregistrez le brouillon et publiez un pack ; vous pouvez calculer l’effet de la pièce au préalable sur la page Aperçu de pièce.',
            ],
        ],
    ],
];
