<?php

return [
    'entries' => [
        '/units/lap/metres' => [
            'label' => 'Lap unit: how many metres',
            'help' => 'How many metres one unit is, in which this game counts the length of the lap of a track: 1 for metres, 1852 for the nautical mile, 1609.344 for the statute mile. The numbers of the rules and of the database stay in metres; the unit says what the length of a track is written and shown in.',
            'example' => 'The car game: 1 (a metre). A game of ships: 1852 (a nautical mile).',
            'range' => 'A number above 0 (from the schema).',
            'tip' => 'Changing it moves no time and no fuel: it only changes what the player sees and what the operator writes.',
        ],
        '/units/travel/metres' => [
            'label' => 'Travel unit: how many metres',
            'help' => 'How many metres one unit is, in which this game counts the roads between cities, the odometer of a car, the interval of the service, the life of the parts and the mileage of the cars on the exchange: 1000 for kilometres, 1852 for the nautical mile, 1609.344 for the statute mile. The numbers of the rules and of the database stay in kilometres; the unit says what to show and write them in.',
            'example' => 'The car game: 1000 (a kilometre). A game of ships: 1852 (a nautical mile).',
            'range' => 'A number above 0 (from the schema).',
            'tip' => 'A road in the rules is whole kilometres, so 20 nautical miles is 37 km in the document (37.04 rounded) and 19.98 miles on the screen.',
        ],
        '/units/speed/kmh' => [
            'label' => 'Speed unit: how many km/h',
            'help' => 'How many km/h one unit of speed is in this game: 1 for km/h, 1.852 for the knot, 1.609344 for miles an hour. The speeds in the rules and in the packs stay in km/h; the unit says what to show and write them in.',
            'example' => 'The car game: 1 (km/h). A game of ships: 1.852 (a knot).',
            'range' => 'A number above 0 (from the schema).',
        ],
        '/units/lap/decimals' => [
            'label' => 'Lap: decimals',
            'help' => 'How many decimals the number has when the player sees it in this unit (0 is a whole number). It only changes how things are shown; the boxes of the forms always have as many as it takes for what is written to come back to the same metre.',
            'example' => 'A lap in nautical miles: 2 decimals (1.4 nm); a road in nautical miles: 0 (20 nm).',
            'range' => 'From 0 to 4 (from the schema).',
        ],
        '/units/travel/decimals' => [
            'label' => 'Travel: decimals',
            'help' => 'How many decimals the number has when the player sees it in this unit (0 is a whole number). It only changes how things are shown; the boxes of the forms always have as many as it takes for what is written to come back to the same metre.',
            'example' => 'A lap in nautical miles: 2 decimals (1.4 nm); a road in nautical miles: 0 (20 nm).',
            'range' => 'From 0 to 4 (from the schema).',
        ],
        '/units/speed/decimals' => [
            'label' => 'Speed: decimals',
            'help' => 'How many decimals the number has when the player sees it in this unit (0 is a whole number). It only changes how things are shown; the boxes of the forms always have as many as it takes for what is written to come back to the same metre.',
            'example' => 'A lap in nautical miles: 2 decimals (1.4 nm); a road in nautical miles: 0 (20 nm).',
            'range' => 'From 0 to 4 (from the schema).',
        ],
        '/units/fuel_economy/per' => [
            'label' => 'Fuel use per how many travel units',
            'help' => 'Per how many travel units the player sees the fuel use: 100 gives “l/100 km”, 1 gives “l per nautical mile”. It is only what is shown; the fuel numbers of the game do not change.',
            'example' => '100 in the car game, 1 in a game of ships.',
            'range' => 'One of 1, 10, 100 (from the schema).',
        ],
        '/track_model/advisor/free_speed_kmh/asphalt' => [
            'label' => 'Free speed: asphalt (km/h)',
            'help' => 'The speed that the difficulty calculator in the track form starts from for the surface “asphalt”: the speed of the reference car on a straight, empty track. The game does not read this number: only the “Suggest a speed” button does.',
            'example' => 'Asphalt 180 km/h.',
            'range' => 'From 20 to 300 km/h (from the schema).',
            'tip' => 'Changing it moves no track that exists; it only changes what the calculator suggests next time.',
        ],
        '/track_model/advisor/free_speed_kmh/gravel' => [
            'label' => 'Free speed: gravel (km/h)',
            'help' => 'The speed that the difficulty calculator in the track form starts from for the surface “gravel”: the speed of the reference car on a straight, empty track. The game does not read this number: only the “Suggest a speed” button does.',
            'example' => 'Gravel 130 km/h.',
            'range' => 'From 20 to 300 km/h (from the schema).',
            'tip' => 'Changing it moves no track that exists; it only changes what the calculator suggests next time.',
        ],
        '/track_model/advisor/corner_loss_kmh' => [
            'label' => 'Speed lost per corner per kilometre (km/h)',
            'help' => 'How many km/h the difficulty calculator takes off for every sharp corner per kilometre of the track. The game does not read this number: only the “Suggest a speed” button does.',
            'example' => '10 km/h: a track with 4 corners a kilometre loses 40 km/h.',
            'range' => 'From 0 to 100 km/h (from the schema).',
        ],
        '/track_model/advisor/climb_loss_kmh' => [
            'label' => 'Speed lost per 10 m of climb per kilometre (km/h)',
            'help' => 'How many km/h the difficulty calculator takes off for every 10 metres of climb per kilometre of the track. The game does not read this number: only the “Suggest a speed” button does.',
            'example' => '5 km/h: a track that climbs 20 m a kilometre loses 10 km/h.',
            'range' => 'From 0 to 100 km/h (from the schema).',
        ],
        '/track_model/advisor/traffic_loss_kmh' => [
            'label' => 'Speed lost per step of traffic (km/h)',
            'help' => 'How many km/h the difficulty calculator takes off for every step of traffic and narrow places (0 is an empty track, 3 is tight and crowded). The game does not read this number: only the “Suggest a speed” button does.',
            'example' => '5 km/h: traffic of step 2 takes off 10 km/h.',
            'range' => 'From 0 to 100 km/h (from the schema).',
        ],
    ],
];
