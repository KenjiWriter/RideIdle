<?php

return [
    'entries' => [
        'document' => [
            'label' => 'Document',
            'help' => 'Le document à afficher sous forme de texte. Changer de document abandonne ce qui a été saisi et non enregistré.',
            'options' => [
                'starter' => ['label' => 'Règles du jeu', 'help' => 'Tous les nombres et toutes les règles du monde (starter.json) : circuits, prix, durées, usure, marché. La même chose que le Studio de paramètres, mais entièrement sous forme de texte.'],
                'tutorial' => ['label' => 'Tutoriel', 'help' => 'Les étapes du tutoriel et leurs récompenses (tutorial.json) ; les mêmes données que celles de la page Tutoriel.'],
            ],
        ],
        'json' => [
            'label' => 'Texte du document (JSON)',
            'help' => 'Tout le document en JSON, pour ce que les autres pages ne montrent pas. Il est jugé par le même schéma et les mêmes règles du monde que sur les autres pages ; l’enregistrement crée un brouillon qui arrive dans le jeu avec le prochain pack.',
            'tip' => 'Une erreur de syntaxe (une virgule manquante, par exemple) est signalée par le champ lui-même et rien n’est enregistré ; une erreur de contenu (valeur fausse, champ manquant) est signalée par une notification rouge avec l’endroit et la raison (les huit premières), là aussi sans enregistrement. Un document identique au document publié ne laisse aucun brouillon.',
        ],
    ],
];
