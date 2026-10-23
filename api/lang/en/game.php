<?php

return [

    'errors' => [
        'insufficient_funds' => 'Not enough cash.',
        'run_exists' => 'You already have a car on the track.',
        'no_run' => 'You have no car on the track yet.',
        'player_level_required' => 'Requires player level :required (you have :current).',
        'car_level_required' => 'Requires car level :required (you have :current).',
        'workshop_level_required' => 'Requires workshop level :required.',
        'car_locked' => 'This car is locked in a tournament and cannot be changed.',
        'part_not_for_sale' => 'This part is not for sale.',
        'part_not_found' => 'You do not have this part.',
        'part_busy' => 'This part is already waiting in the workshop.',
        'part_mounted' => 'This part is already on the car.',
        'part_not_mounted' => 'This part is not on the car.',
        'queue_full' => 'The workshop queue is full.',
        'no_car' => 'You have no car yet.',
        'request_reused' => 'This request was already used for something else.',
    ],

    'fuel' => [
        'empty' => 'The tank is empty.',
        'low' => 'Fuel is running low.',
    ],

    'rarity' => [
        'common' => 'Common',
        'uncommon' => 'Uncommon',
        'rare' => 'Rare',
        'epic' => 'Epic',
        'legendary' => 'Legendary',
    ],

    'drivetrain' => [
        'fwd' => 'Front-wheel drive',
        'rwd' => 'Rear-wheel drive',
        'awd' => 'All-wheel drive',
    ],

    'weather' => [
        'dry' => 'Dry',
        'rain' => 'Rain',
        'night' => 'Night',
    ],

];
