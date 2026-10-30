<?php

return [
    'entries' => [
        'vehicle' => [
            'label' => 'How the car form works',
            'help' => 'Here you make a new car or change an existing one. Saving makes a draft: it is checked against the schema and saved only if it passes, but it reaches the players only with the next pack that you publish.',
            'example' => 'Rdzak GT-4: hatchback, front-wheel drive, combustion, R4 1.8 l with tune 1.0, 97 kW, 1000 kg, 175 km/h, clearance 20, cargo 120 kg, 4 seats, 45 l tank, tier 2, level 3, price $21,500, sources: starter wreck and exchange.',
            'steps' => [
                'Enter the slug (a permanent identifier, chosen once) and the name; English and Polish are required.',
                'Choose the class, the drivetrain and the powertrain; for a combustion car give the factory engine (layout, displacement, tune) and the tank.',
                'Enter the power, mass, top speed, clearance, cargo and seats; power and mass change driving the most.',
                'Choose the tier: the player level has to be 3 × tier − 3 (at least 1). Set the price in dollars and tick the sources: starter wreck, exchange, dealer.',
                'Save the draft; errors from the schema are shown in a notification. Then publish a pack: it goes through the gates of the simulator, which watch the balance.',
            ],
        ],
        'part' => [
            'label' => 'How the part form works',
            'help' => 'Here you make a new part or change an existing one. Saving makes a draft that is checked against the schema and reaches the game with the next published pack; a part enters the shop only when it is on the shop list in the rules (Rules as text, shop.parts).',
            'example' => 'Street tires T2: slot Tires, kind Street, tier 2, price $570, grip 100, handling 98, braking 100. Turbo Ósemka T2: slot Forced induction, tier 2, price $4,200, power 1.25, lag 3, fuel use 1.15, load 8, at least 4 cylinders.',
            'steps' => [
                'Enter the slug and the name in the languages; English and Polish are required.',
                'Choose the slot: its role says what the part does. For tires choose the kind, for an engine the layout and the displacement.',
                'Choose the tier and give the price in dollars; the prices of most parts are checked against the curve of the economy (±20%).',
                'Add statistics as lines of "a name and a number" (for example grip 100, power 1.25); multipliers have 1 as "no change".',
                'If needed, open "What it needs and what it fits": minimum cylinders, booster, powertrain, classes.',
                'Save the draft and publish a pack; you can work out the effect of the part beforehand on the Part preview page.',
            ],
        ],
    ],
];
