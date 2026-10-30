<?php

return [
    'entries' => [
        '/tracks/*/speed_kmh' => [
            'label' => 'Track speed (km/h)',
            'help' => 'The average speed of the reference car on this track, with everything that slows it counted in: the corners, the surface, the climbs and the traffic. The reference lap is the length of the track divided by this speed, and the fuel, the pay and the XP of a lap follow from the time of that lap.',
            'example' => 'Złomowo street track: 124.8 km/h (2600 m in 75 s), Autodrome Circuit: 144 km/h (3200 m in 80 s), Kruszyn Quarry: 90.947 km/h (2400 m in 95 s).',
            'range' => 'From 20 to 300 km/h, at most four decimals (from the schema).',
            'tip' => 'The slower the track (gravel, twisty, mountain), the longer the lap and the more fuel per kilometre, because the engine works for longer. The pay an hour stays what you set, so changing the speed does not change what an hour earns, only how many kilometres the car drives in an hour (so the wear of the parts and the service). A real car drives the track faster or slower depending on its fit.',
        ],
        '/tracks/*/pay_cents_per_hour' => [
            'label' => 'Pay per hour ($)',
            'help' => 'The dollars that an hour of driving the reference car on this track pays in dry weather, before the tier of the car, the place among the bots and the weather change it. The pay of a lap is this number times the time of the reference lap, so a longer track does not pay less an hour.',
            'example' => 'Złomowo street track: $744 an hour ($15.50 for a lap of 75 s), Scrapyard Dash: $600, Autodrome Circuit: $1,192.50.',
            'range' => 'At least $0.01 (from the schema).',
            'tip' => 'This is the main lever of the economy of a track: earnings an hour are the pay an hour minus the fuel an hour. The anchors of the economy give the net income of an hour for the level of the track, and here you enter the gross.',
        ],
        '/tracks/*/xp_per_hour' => [
            'label' => 'XP per hour',
            'help' => 'The XP that an hour of driving on this track gives the driver in dry weather. The XP of a lap is this number times the time of the reference lap, rounded to a whole number. A faster car gets proportionally less per lap, so in an hour every car gives the same.',
            'example' => 'Złomowo street track: 960 XP an hour (20 XP a lap), Autodrome Circuit: 900.',
            'range' => 'From 0 to 3600, at most two decimals (from the schema).',
            'tip' => 'On a very short track the rounding to whole XP a lap changes the result by ten percent or more: the validator warns when the rounding takes more than a tenth.',
        ],
        '/track_model/fuel_ml_per_s/sprint' => [
            'label' => 'Fuel per second of a lap: sprint',
            'help' => 'How much fuel the engine of a car of normal thirst burns in every second of a lap on a track of the kind “sprint”. The fuel of a lap of the reference car is this number times the time of the lap, and a thirstier car burns more.',
            'example' => 'Sprint 12 ml (0.012 l) a second: the Scrapyard Dash (12 s) burns 144 ml.',
            'range' => 'From 1 to 1000 ml a second (from the schema).',
            'tip' => 'Changing this moves the fuel of every track of the kind at once. A tank lasts the capacity ÷ this number in seconds: 45 l at 12 ml is about an hour, at 32 ml about 23 minutes.',
        ],
        '/track_model/fuel_ml_per_s/standard' => [
            'label' => 'Fuel per second of a lap: standard',
            'help' => 'How much fuel the engine of a car of normal thirst burns in every second of a lap on a track of the kind “standard”. The fuel of a lap of the reference car is this number times the time of the lap, and a thirstier car burns more.',
            'example' => 'Standard 20 ml a second: the Złomowo street track (75 s) burns 1.5 l a lap.',
            'range' => 'From 1 to 1000 ml a second (from the schema).',
            'tip' => 'Changing this moves the fuel of every track of the kind at once. A tank lasts the capacity ÷ this number in seconds: 45 l at 12 ml is about an hour, at 32 ml about 23 minutes.',
        ],
        '/track_model/fuel_ml_per_s/endurance' => [
            'label' => 'Fuel per second of a lap: endurance',
            'help' => 'How much fuel the engine of a car of normal thirst burns in every second of a lap on a track of the kind “endurance”. The fuel of a lap of the reference car is this number times the time of the lap, and a thirstier car burns more.',
            'example' => 'Endurance 32 ml a second: the Złomowo Ring Road (300 s) burns 9.6 l a lap.',
            'range' => 'From 1 to 1000 ml a second (from the schema).',
            'tip' => 'Changing this moves the fuel of every track of the kind at once. A tank lasts the capacity ÷ this number in seconds: 45 l at 12 ml is about an hour, at 32 ml about 23 minutes.',
        ],
    ],
];
