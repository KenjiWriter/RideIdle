<?php

return [
    'entries' => [
        '/missions/reset_hour' => [
            'label' => 'Heure de début du jour des missions',
            'help' => 'L’heure (dans le fuseau horaire du jeu) à laquelle commence le jour des missions, et avec lui la nouvelle série quotidienne. La semaine commence le lundi à cette heure et le mois le premier à cette heure. La période précédente se termine alors aussi : ce que le joueur a terminé sans le récupérer, le serveur le récupère pour lui, et le reste est perdu.',
            'example' => '4 : un joueur qui joue le soir et après minuit a un seul jour de missions, et reçoit la nouvelle série à 4 h du matin.',
            'range' => '0 – 23 (l’heure).',
        ],
        '/missions/periods/daily/bonus_coins' => [
            'label' => 'Bonus pour la série complète : jour (pièces)',
            'help' => 'Le nombre de pièces premium (RideCoin) que le joueur reçoit pour avoir terminé toutes les missions de la série du jour. Ce sont les seules pièces gratuites que donnent les missions ; les récompenses des missions seules n’en contiennent pas. Ce nombre fixe donc toutes les pièces gratuites de cette période, et le schéma n’autorise pas plus de 5.',
            'example' => '2 pièces : avec les trois périodes réussies à chaque fois, cela fait 2, 8 et 25 pièces, environ 119 par mois ; le compte VIP coûte 150 pièces et un lot de 50 pièces 4,99 zł.',
            'range' => '0 – 5 pièces.',
            'tip' => 'Les joueurs ressentent une baisse du bonus comme une récompense retirée, alors commencez bas : l’augmenter plus tard est facile.',
        ],
        '/missions/periods/weekly/bonus_coins' => [
            'label' => 'Bonus pour la série complète : semaine (pièces)',
            'help' => 'Le nombre de pièces premium (RideCoin) que le joueur reçoit pour avoir terminé toutes les missions de la série de la semaine. Ce sont les seules pièces gratuites que donnent les missions ; les récompenses des missions seules n’en contiennent pas. Ce nombre fixe donc toutes les pièces gratuites de cette période, et le schéma n’autorise pas plus de 20.',
            'example' => '8 pièces : avec les trois périodes réussies à chaque fois, cela fait 2, 8 et 25 pièces, environ 119 par mois ; le compte VIP coûte 150 pièces et un lot de 50 pièces 4,99 zł.',
            'range' => '0 – 20 pièces.',
            'tip' => 'Les joueurs ressentent une baisse du bonus comme une récompense retirée, alors commencez bas : l’augmenter plus tard est facile.',
        ],
        '/missions/periods/monthly/bonus_coins' => [
            'label' => 'Bonus pour la série complète : mois (pièces)',
            'help' => 'Le nombre de pièces premium (RideCoin) que le joueur reçoit pour avoir terminé toutes les missions de la série du mois. Ce sont les seules pièces gratuites que donnent les missions ; les récompenses des missions seules n’en contiennent pas. Ce nombre fixe donc toutes les pièces gratuites de cette période, et le schéma n’autorise pas plus de 60.',
            'example' => '25 pièces : avec les trois périodes réussies à chaque fois, cela fait 2, 8 et 25 pièces, environ 119 par mois ; le compte VIP coûte 150 pièces et un lot de 50 pièces 4,99 zł.',
            'range' => '0 – 60 pièces.',
            'tip' => 'Les joueurs ressentent une baisse du bonus comme une récompense retirée, alors commencez bas : l’augmenter plus tard est facile.',
        ],
        '/missions/periods/daily/cash_ppm' => [
            'label' => 'Argent pour la série : jour (% du réservoir)',
            'help' => 'L’argent que paie l’ensemble de la série du jour, en part de ce que la meilleure voiture du joueur gagne avec un plein, carburant déduit (100 % est un réservoir entier). La série le répartit entre ses missions selon leurs parts, elle paie donc la même somme quelles que soient les missions tirées. C’est un supplément aux gains des voitures, qui ne compte pas dans l’économie du jeu : les barrières et le simulateur ne le voient pas.',
            'example' => '6 % : un joueur dont le réservoir rapporte 1 000 $ reçoit environ 60 $ pour toute la série.',
            'range' => '0 – 1000 %.',
        ],
        '/missions/periods/weekly/cash_ppm' => [
            'label' => 'Argent pour la série : semaine (% du réservoir)',
            'help' => 'L’argent que paie l’ensemble de la série de la semaine, en part de ce que la meilleure voiture du joueur gagne avec un plein, carburant déduit (100 % est un réservoir entier). La série le répartit entre ses missions selon leurs parts, elle paie donc la même somme quelles que soient les missions tirées. C’est un supplément aux gains des voitures, qui ne compte pas dans l’économie du jeu : les barrières et le simulateur ne le voient pas.',
            'example' => '20 % : un joueur dont le réservoir rapporte 1 000 $ reçoit environ 200 $ pour toute la série.',
            'range' => '0 – 1000 %.',
        ],
        '/missions/periods/monthly/cash_ppm' => [
            'label' => 'Argent pour la série : mois (% du réservoir)',
            'help' => 'L’argent que paie l’ensemble de la série du mois, en part de ce que la meilleure voiture du joueur gagne avec un plein, carburant déduit (100 % est un réservoir entier). La série le répartit entre ses missions selon leurs parts, elle paie donc la même somme quelles que soient les missions tirées. C’est un supplément aux gains des voitures, qui ne compte pas dans l’économie du jeu : les barrières et le simulateur ne le voient pas.',
            'example' => '60 % : un joueur dont le réservoir rapporte 1 000 $ reçoit environ 600 $ pour toute la série.',
            'range' => '0 – 1000 %.',
        ],
        '/missions/periods/daily/xp_ppm' => [
            'label' => 'XP pour la série : jour (% du réservoir)',
            'help' => 'L’XP du joueur (le niveau du pilote) que paie l’ensemble de la série du jour, en part de l’XP que la meilleure voiture du joueur récolte avec un plein. Le niveau du joueur ouvre pistes, villes et pièces, et le tutoriel dit que les niveaux doivent venir des tours, alors gardez ce nombre petit : chaque point décale le rythme de tout le jeu.',
            'example' => '5 % : un joueur dont le réservoir donne 1 000 XP reçoit environ 50 XP pour toute la série.',
            'range' => '0 – 1000 %.',
        ],
        '/missions/periods/weekly/xp_ppm' => [
            'label' => 'XP pour la série : semaine (% du réservoir)',
            'help' => 'L’XP du joueur (le niveau du pilote) que paie l’ensemble de la série de la semaine, en part de l’XP que la meilleure voiture du joueur récolte avec un plein. Le niveau du joueur ouvre pistes, villes et pièces, et le tutoriel dit que les niveaux doivent venir des tours, alors gardez ce nombre petit : chaque point décale le rythme de tout le jeu.',
            'example' => '15 % : un joueur dont le réservoir donne 1 000 XP reçoit environ 150 XP pour toute la série.',
            'range' => '0 – 1000 %.',
        ],
        '/missions/periods/monthly/xp_ppm' => [
            'label' => 'XP pour la série : mois (% du réservoir)',
            'help' => 'L’XP du joueur (le niveau du pilote) que paie l’ensemble de la série du mois, en part de l’XP que la meilleure voiture du joueur récolte avec un plein. Le niveau du joueur ouvre pistes, villes et pièces, et le tutoriel dit que les niveaux doivent venir des tours, alors gardez ce nombre petit : chaque point décale le rythme de tout le jeu.',
            'example' => '40 % : un joueur dont le réservoir donne 1 000 XP reçoit environ 400 XP pour toute la série.',
            'range' => '0 – 1000 %.',
        ],
    ],
];
