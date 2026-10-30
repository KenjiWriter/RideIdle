<?php

return [
    'entries' => [
        'slots' => [
            'label' => 'Slots',
            'help' => 'The places in the car that parts go into, in the order the garage and the shop list them. From 1 to 24 slots; drag a row to change the order.',
            'range' => 'From 1 to 24 slots.',
            'tip' => 'A slot that a part or a rule still names cannot be removed: the save is refused with a list of the places that use it. A publication will not take out a slot that players have parts in either, so changing the key of a slot in use is blocked.',
        ],
        'key' => [
            'label' => 'Slot key',
            'help' => 'The name the slot has in parts, rules and the players’ cars. The name shown in the game (for example “Tires”) is given by the Vocabulary as slot.key; for a new key it has to be written there (English and Polish).',
            'example' => 'tires, suspension, brakes, engine, boost, gearbox, exhaust, cooling, tank, ecu (the car game’s model).',
            'range' => 'Up to 32 characters: lowercase letters, digits and _, starting with a letter, no repeats.',
        ],
        'role' => [
            'label' => 'Slot role',
            'help' => 'Tells the game’s formulas what this slot is in this game, whatever its key: a slot with the role “Tires” decides grip even if it is called “hull”. A role belongs to one slot, and the game must have a slot with the tires role. A slot without a role holds parts whose statistics the formulas already read (like the exhaust).',
            'tip' => 'Parts in a slot with the tires role must have a tire kind, and parts in a slot with the engine role an engine (layout and displacement); in other slots parts do not have those fields.',
            'options' => [
                'tires' => ['label' => 'Tires', 'help' => 'Grip, handling and braking start here, and the kind of tire picks the column of the surface matrix. A required role.'],
                'suspension' => ['label' => 'Suspension', 'help' => 'The car’s handling and clearance (off-road ability).'],
                'brakes' => ['label' => 'Brakes', 'help' => 'The car’s braking.'],
                'engine' => ['label' => 'Engine', 'help' => 'The car’s power and mass; a part in this slot replaces the factory engine.'],
                'boost' => ['label' => 'Forced induction', 'help' => 'The power gain, the lag and the load on the engine.'],
                'gearbox' => ['label' => 'Gearbox', 'help' => 'The top-speed limit and the launch.'],
                'cooling' => ['label' => 'Cooling', 'help' => 'The intercooler and the radiator: it decides how much the booster loses.'],
                'tank' => ['label' => 'Tank', 'help' => 'The capacity of the tank, that is how far the car goes.'],
            ],
        ],
        'hidden' => [
            'label' => 'Hidden in the garage',
            'help' => 'A hidden slot can still hold parts and has its place in the rules, but the garage does not list it (in the car game that is the “ecu” slot). A hidden slot does not need a name in the Vocabulary.',
        ],
        'stats' => [
            'label' => 'Statistics',
            'help' => 'The statistics of a car that the garage and workshop screens show, in the order of the list. A statistic that is left out is not shown, and a new one cannot be added, because the formulas make the statistics.',
            'tip' => 'The list changes only what is seen and the car’s overall score (the average of the bars). No race formula reads it, so leaving a statistic out does not change how the car drives.',
        ],
        'stat' => [
            'label' => 'Statistic',
            'help' => 'Which statistic to show at this place of the list. Each one can appear only once.',
            'options' => [
                'accel' => ['label' => 'Acceleration', 'help' => 'The car’s power per tonne: the more of it, the faster the car pulls away and gathers speed.'],
                'top_speed' => ['label' => 'Top speed', 'help' => 'Grows with power (with the cube root of the power gain) and does not exceed the gearbox’s limit.'],
                'grip' => ['label' => 'Grip', 'help' => 'Comes from the tires: how firmly the car holds the road.'],
                'handling' => ['label' => 'Handling', 'help' => 'From the tires and the suspension, multiplied by the mass factor: a heavy car turns worse.'],
                'braking' => ['label' => 'Braking', 'help' => 'From the tires and the brakes, multiplied by the mass factor: a heavy car brakes worse.'],
                'clearance' => ['label' => 'Off-road', 'help' => 'Ground clearance: starts from the body and is averaged with the suspension.'],
            ],
        ],
    ],
];
