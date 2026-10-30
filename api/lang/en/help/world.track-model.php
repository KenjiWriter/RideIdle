<?php

return [
    'entries' => [
        'speed_kmh' => [
            'label' => 'Track speed',
            'help' => 'The average speed of the reference car on this track, with everything that slows it counted in: the corners, the surface, the climbs and the traffic. The reference lap is the length divided by this speed; the fuel, the pay and the XP of a lap follow from its time. A real car drives faster or slower depending on how well it fits the track.',
            'example' => 'Złomowo street track: 124.8 km/h (2600 m in 75 s); Autodrome Circuit: 144 km/h; Kruszyn Quarry (gravel): about 91 km/h.',
            'range' => 'From 20 to 300 km/h, at most four decimals.',
            'tip' => 'Not sure what to enter? The “Suggest a speed” button works it out from the corners, the climbs and the traffic. A slower track means a longer lap and more fuel per kilometre; the pay an hour stays what you set.',
        ],
        'pay_per_hour' => [
            'label' => 'Pay per hour ($)',
            'help' => 'The dollars that an hour of driving the reference car on this track pays in dry weather, before the tier of the car, the place among the bots and the weather change it. You enter dollars; the game keeps cents. The pay of a lap comes from this number and the time of the lap, so a longer track does not pay less an hour.',
            'example' => '$744 (Złomowo street track: $15.50 for a lap of 75 s), $600 (Scrapyard Dash), $1,192.50 (Autodrome Circuit).',
            'range' => 'At least $0.01.',
            'tip' => 'Earnings an hour are the pay an hour minus the fuel an hour: that is what the balance checks compare between tracks, so a new pay changes which track is the best at a level. Rain, downpour and snow pay 7% less (now).',
        ],
        'xp_per_hour' => [
            'label' => 'XP per hour',
            'help' => 'The experience that an hour of driving on this track gives in dry weather: it raises the level of the driver, which opens new cities, tracks and parts. The XP of a lap comes from this number and the time of the lap, rounded to a whole number. A faster car gets proportionally less per lap, so in an hour every car gives the same XP.',
            'example' => '960 XP (Złomowo street track, 20 XP a lap), 900 XP (Autodrome Circuit and the sprints).',
            'range' => 'From 0 to 3600, at most two decimals.',
            'tip' => 'On a very short track the rounding of the XP of a lap to a whole number can change the result by as much as a fifth; in wet weather it is 7% less.',
        ],
    ],
];
