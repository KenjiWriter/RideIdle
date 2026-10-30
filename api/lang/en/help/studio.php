<?php

return [
    'entries' => [
        'simple' => [
            'label' => 'Simple mode',
            'help' => 'On: only the numbers that set the pace and the money of the game (prices, the reward for a lap, the times of the workshop, levels), a small part of the roughly 750. Off: every number of the section, including those that are rarely touched.',
            'tip' => 'Simple mode is on when the page opens. Switching it redraws the form from the saved draft, so save your changes before you switch.',
        ],
        'section' => [
            'label' => 'Section',
            'help' => 'The numbers of the game are split into sections; one is shown at a time, with how many numbers it has in brackets (in simple mode only the simple ones) and a sentence on what changing its numbers does in the game. Save before you change the section: what is not saved is lost.',
            'options' => [
                'fuel' => ['label' => 'Fuel', 'help' => 'The price of a litre of fuel; the cost of every lap depends on it.'],
                'repair' => ['label' => 'Roadside repair', 'help' => 'The price of the repair after a breakdown on the track.'],
                'breakdown' => ['label' => 'Breakdowns', 'help' => 'The chance of a breakdown on a lap and how much wear raises it.'],
                'tow' => ['label' => 'Towing', 'help' => 'How long a tow takes after a breakdown and the fuel left for the way.'],
                'car' => ['label' => 'Car: XP, levels, mass', 'help' => 'How fast cars gain levels, how mass affects handling, and what the cars of the higher tiers pay.'],
                'engines' => ['label' => 'Engines', 'help' => 'The power, the mass and the requirements of the engines.'],
                'drivetrain' => ['label' => 'Drivetrain', 'help' => 'How front-wheel, rear-wheel and all-wheel drive hold the surface, and their power limits.'],
                'garage' => ['label' => 'Garages', 'help' => 'How many cars a player keeps in a city and what it costs to start; the prices of garages and yard wrecks.'],
                'market' => ['label' => 'Market: exchange, dealer, storage, crates', 'help' => 'The prices of used cars, resale, the odds in storage units and how often the exchange refreshes.'],
                'clock' => ['label' => 'Game clock', 'help' => 'How much slower cars drive at night on tracks without lights.'],
                'player' => ['label' => 'Player: XP and levels', 'help' => 'How fast the player gains levels, which open the cities, tracks and parts.'],
                'start' => ['label' => 'Start', 'help' => 'The head start a new player has on the first lap.'],
                'bots' => ['label' => 'Bots', 'help' => 'The times of the rivals against the reference track and the multipliers of the reward by position.'],
                'shop' => ['label' => 'Shop', 'help' => 'Crates and the rarity of parts: odds, strength and prices.'],
                'affixes' => ['label' => 'Affixes', 'help' => 'The strength, the odds and the effects of the affixes of parts, including those that depend on the surface.'],
                'wear' => ['label' => 'Wear and service', 'help' => 'The life of parts in kilometres, the cost of repair and of service.'],
                'premium' => ['label' => 'Premium currency', 'help' => 'The prices of RideCoin packs, speeding up for coins, the price of the lawyer, the auto-refill (packs of charges), the VIP account and the pass of the unlimited refill.'],
                'workshop' => ['label' => 'Workshop', 'help' => 'The times of fitting, upgrading and merging, the prices of bays and mechanics, the length of the queue.'],
                'tire_matrix' => ['label' => 'Tire matrix', 'help' => 'The grip of every kind of tire on every surface.'],
                'weather' => ['label' => 'Weather', 'help' => 'How the weather affects a lap.'],
                'regions' => ['label' => 'Cities (numbers)', 'help' => 'The position of the cities on the map and their requirements.'],
                'roads' => ['label' => 'Roads', 'help' => 'The distances between cities in kilometres, and with them the time and cost of a journey.'],
                'travel' => ['label' => 'Travel and speed-up', 'help' => 'The speed and fuel of a journey, and the boosted drive with the police and fines.'],
                'tracks' => ['label' => 'Tracks: times, rewards, weights', 'help' => 'A track’s length, lap time, fuel, reward and XP, and the weights of its statistics; it changes the earnings per hour directly.'],
            ],
        ],
        'sliders' => [
            'label' => 'Sliders',
            'help' => 'On: a number whose limits the schema gives has a slider with the value as a tooltip; the others are always fields. Off: every number is a field in which you type the exact value.',
            'tip' => 'A slider moves in steps of the number and is no use for very wide ranges (over 2000 steps), where there is a field. Switching it redraws the form from the saved draft, so save your changes before you switch.',
        ],
    ],
];
