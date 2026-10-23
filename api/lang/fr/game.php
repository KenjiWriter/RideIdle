<?php

return [

    'errors' => [
        'insufficient_funds' => 'Pas assez d’argent.',
        'run_exists' => 'Vous avez déjà une voiture en piste.',
        'no_run' => 'Vous n’avez pas encore de voiture en piste.',
        'player_level_required' => 'Niveau de joueur :required requis (vous avez :current).',
        'car_level_required' => 'Niveau de voiture :required requis (vous avez :current). Vous gagnez de l’XP en conduisant cette voiture.',
        'track_locked' => 'Niveau de joueur :required requis (vous êtes niveau :current). Vous gagnez de l’XP en conduisant.',
        'city_locked' => 'Niveau de joueur :required requis (vous êtes niveau :current). Vous gagnez de l’XP en conduisant.',
        'city_not_found' => 'Cette ville n’existe pas.',
        'no_road' => 'Aucune route n’y mène d’ici.',
        'already_there' => 'La voiture y est déjà.',
        'already_travelling' => 'La voiture est déjà en route.',
        'car_cannot_travel' => 'Seule une voiture qui roule peut partir : réparez-la, faites le plein ou attendez l’atelier d’abord.',
        'travel_no_fuel' => 'Pas assez de carburant pour la route : il faut :required l et le réservoir en contient :available l.',
        'car_travelling' => 'La voiture est sur la route, l’atelier ne peut donc pas travailler dessus maintenant.',
        'track_elsewhere' => 'Cette piste est dans une autre ville. Rendez-vous d’abord sur place par la route.',
        'workshop_level_required' => 'Niveau d’atelier :required requis.',
        'car_locked' => 'Cette voiture est verrouillée dans un tournoi et ne peut pas être modifiée.',
        'part_not_for_sale' => 'Cette pièce n’est pas en vente.',
        'part_not_found' => 'Vous n’avez pas cette pièce.',
        'part_busy' => 'Cette pièce attend déjà à l’atelier.',
        'part_mounted' => 'Cette pièce est déjà montée sur la voiture.',
        'part_not_mounted' => 'Cette pièce n’est pas montée sur la voiture.',
        'queue_full' => 'La file d’attente de l’atelier est pleine.',
        'slot_busy' => 'Une autre pièce pour cet emplacement attend déjà à l’atelier.',
        'job_not_found' => 'Ce travail n’existe pas.',
        'job_done' => 'Ce travail est déjà terminé.',
        'no_car' => 'Vous n’avez pas encore de voiture.',
        'request_reused' => 'Cette requête a déjà été utilisée pour autre chose.',
    ],

    'fuel' => [
        'empty' => 'Le réservoir est vide.',
        'low' => 'Le niveau de carburant est bas.',
    ],

    'rarity' => [
        'common' => 'Commune',
        'uncommon' => 'Peu commune',
        'rare' => 'Rare',
        'epic' => 'Épique',
        'legendary' => 'Légendaire',
    ],

    'drivetrain' => [
        'fwd' => 'Traction avant',
        'rwd' => 'Propulsion',
        'awd' => 'Transmission intégrale',
    ],

    'weather' => [
        'dry' => 'Sec',
        'rain' => 'Pluie',
        'night' => 'Nuit',
    ],

];
