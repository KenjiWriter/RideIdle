<?php

return [
    'entries' => [
        'world' => [
            'label' => 'How the world form works',
            'help' => 'Here you set the places where cars earn: cities with their weather, the roads between them and the tracks. What you enter is saved as a draft of the rules and reaches players only with the next published pack.',
            'steps' => [
                'Tab Cities: add a city with a slug, names in the languages, a place on the map and the chances of its weather (100% in all).',
                'Tab Roads: join the cities with roads and give their length in km; every city must be reachable from the first one.',
                'Tab Tracks: add a track in a city: surface, kind, level, lap length, reference lap, fuel, reward and XP.',
                'Set what the track asks of a car (percentages that add up to 100) and the reference value of each of those statistics.',
                'Save the draft; the changes reach players with the next published pack (page Publications).',
            ],
            'example' => 'A gravel sprint in Kruszyn, as in the car game: slug kruszyn-gravel-sprint, surface Gravel, kind Sprint, level 3, 380 m, reference lap 15 000 ms (15 s), fuel 180 ml, reward $2.92, XP 3; it asks for Acceleration 20, Grip 30, Handling 15, Braking 10, Off-road 25 (references 65, 92, 71, 71, 80).',
        ],
        'game' => [
            'label' => 'How the game form works',
            'help' => 'This is one game of the installation: its name, address, language, colour, icons and whether it is live. The game itself (cars, rules, texts) is edited on the other pages; this form is only its identity.',
            'steps' => [
                'Change the name, language or colour; the bar above the form and the preview show how the game will look before you save.',
                'Add or change the domain, then open the page “Domain and SSL”: the DNS must point at this server before a certificate is issued.',
                'Upload a square icon (and, if you like, one with a margin for the phones that cut icons).',
                'Set the status to Live when the content and the domain are ready; a draft answers to nobody.',
                'Save. The slug and the default game cannot be changed here.',
            ],
            'example' => 'Space Ships at ships.example.com: language English, colour #1a2b4c, a 512 × 512 icon, status Live.',
        ],
        'wizard' => [
            'label' => 'How the wizard makes a game',
            'help' => 'A game is made in three steps: its name and address, the content it starts from, and what the server does about the domain. It starts as a draft, which answers to nobody until you make it live; the slug, colours, icons and look can be set later.',
            'steps' => [
                'Name and domain: type the name (the slug is filled in from it), the domain if you have one, the language and the colour.',
                'Starting point: choose the car game as a template, a copy of another game, or no content.',
                'Domain and SSL: check that the domain points at this server and, if you want, ask the server to set it up at once.',
                'Press “Create the game”. The panel takes you to the page “Domain and SSL” of the new game and switches to it.',
                'When the content and the domain are ready, set the status to Live by editing the game.',
            ],
            'example' => 'Name “Space Ships”, domain ships.example.com, language English, start from the car game, ask for the domain: a draft game with the live pack of the car game and a request waiting for the server.',
        ],
    ],
];
