<?php

return [
    'entries' => [
        'start' => [
            'label' => 'Content to start with',
            'help' => 'What the new game starts with. The game is made as a draft either way; the first pack is made live at once when there is content to start from.',
            'tip' => 'If the content cannot be put in (the copied game has no live pack, or the content fails the checks), the game is still made, without content, and the panel says so; nothing is lost.',
            'options' => [
                'template' => ['label' => 'The car game (template)', 'help' => 'The cars, parts, tracks, rules, tutorial and texts of the car game from the repository become the first pack, live at once; it is easiest to start from something that already works and change it.'],
                'copy' => ['label' => 'A copy of another game', 'help' => 'The pack that the chosen game plays now becomes the first pack of the new one (not its players, accounts or unpublished drafts).'],
                'none' => ['label' => 'No content', 'help' => 'An empty game. It needs a pack before it can be made live.'],
            ],
        ],
        'copy_from' => [
            'label' => 'Which game to copy',
            'help' => 'The game whose live pack becomes the first pack of the new one. Only the pack that game plays now is copied: not its drafts, players or accounts.',
            'tip' => 'When the chosen game has no live pack, the new game is made without content and the panel warns about it.',
        ],
        'request_domain' => [
            'label' => 'Set up the domain and SSL now',
            'help' => 'When the game is made, ask the server to set up the domain (the web server entry) and the SSL certificate. The server takes the request up within a minute, and you can follow it on the page “Domain and SSL”.',
            'tip' => 'The domain should already point at this server (use “Check DNS” above), and the installation needs an e-mail address for Let’s Encrypt (ACME_EMAIL); without them the switch is not shown or the request is refused, and the game is still made. You can ask again later on the page “Domain and SSL”.',
        ],
        'units' => [
            'label' => 'System of units',
            'help' => 'What the new game counts distance and speed in: what the operator writes and the player sees (the numbers in the rules stay in metres, kilometres and km/h). It is changed later in the studio (“Units of the game”) and in the text of the rules.',
            'tip' => 'It is only for a game that starts from the template; a copy of another game has the units of that one, and a game with no content has none.',
            'options' => [
                'metric' => ['label' => 'Metric', 'help' => 'Metres on a track, kilometres on the road, km/h, fuel use in l/100 km: the car game.'],
                'nautical' => ['label' => 'Nautical', 'help' => 'Nautical miles on a track and on the road, knots, fuel use in litres per nautical mile: a game of ships.'],
                'imperial' => ['label' => 'Miles', 'help' => 'Miles on a track and on the road, miles an hour, fuel use in litres per 100 miles.'],
            ],
        ],
    ],
];
