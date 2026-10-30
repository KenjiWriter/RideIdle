<?php

return [
    'entries' => [
        'slug' => [
            'label' => 'Slug of the game',
            'help' => 'The short name that commands, the panel and the log use to point at this game. You choose it once, when the game is made.',
            'example' => 'ships, raceidle',
            'range' => '2–32 characters: lower-case letters, digits and dashes, a letter first; different for every game.',
            'tip' => 'It cannot be changed afterwards, because the commands (--game=slug), the deploy scripts, the audit trail and the game switcher of the panel all name the game by it. In the wizard it is filled in from the name; you can correct it before you go on.',
        ],
        'name' => [
            'label' => 'Name of the game',
            'help' => 'What the game is called: on the browser tab, in the app bar, under the icon on the home screen of a phone (only the first words that fit in 12 letters), in e-mails and on the start screen.',
            'example' => 'Space Ships',
            'range' => 'Up to 64 characters.',
        ],
        'domain' => [
            'label' => 'Domain',
            'help' => 'The address the game answers on, in lower case, without http:// and without a path. Whoever opens this address gets this game. Without a domain the game is reachable from the panel only (unless it is the only game of the installation).',
            'example' => 'ships.example.com',
            'range' => 'At least two parts (game.example.com), up to 190 characters; different for every game.',
            'tip' => 'Before a certificate can be issued the domain must point at this server (an A record, AAAA for IPv6). After you set or change it, open the page “Domain and SSL” of the game: it checks the DNS and asks the server to set up the address and the SSL certificate. A game that is a draft does not answer on its domain even then.',
        ],
        'locale' => [
            'label' => 'Language of the game',
            'help' => 'The language in which new players see the game until they choose their own; it is also the language of the page and the app of the game when the language of the visitor is not one of the game’s.',
            'options' => [
                'pl' => ['label' => 'Polski', 'help' => 'New players see the game in Polish until they choose another language.'],
                'en' => ['label' => 'English', 'help' => 'New players see the game in English until they choose another language.'],
                'fr' => ['label' => 'Français', 'help' => 'New players see the game in French until they choose another language.'],
                'de' => ['label' => 'Deutsch', 'help' => 'New players see the game in German until they choose another language.'],
                'pt-BR' => ['label' => 'Português (Brasil)', 'help' => 'New players see the game in Brazilian Portuguese until they choose another language.'],
            ],
        ],
        'theme_color' => [
            'label' => 'Frame colour',
            'help' => 'The colour of the frame around the game: the bar of the browser and the phone, the start screen of the installed app and the link preview. The colours inside the game are set on the page “Brand and page”.',
            'example' => '#0e1013 (the dark of the car game)',
            'range' => 'Six hex digits with a #, for example #0e1013.',
        ],
        'icon_upload' => [
            'label' => 'Icon',
            'help' => 'A square picture (PNG, WebP or JPEG), at least :min × :min pixels, best 512: the icon of the app on the home screen and in the browser tab. Without one the game keeps the icon of the car game.',
            'range' => 'Square, at least :min px on a side, up to 2048 px and 1 MB.',
            'tip' => 'A chosen file replaces the current icon only when the game is saved; a picture that is not square or is smaller than :min px on a side is refused and nothing is saved.',
        ],
        'icon_remove' => [
            'label' => 'Take the icon off',
            'help' => 'When you save, the game goes back to the icon of the car game.',
        ],
        'icon_maskable_upload' => [
            'label' => 'Icon with a margin',
            'help' => 'The same picture drawn with a wide margin, so that what matters stays in the middle 80%: for the phones that cut icons into circles or rounded squares. Optional; without it the icon above is used as it is. Square, at least :min px.',
        ],
        'icon_maskable_remove' => [
            'label' => 'Take the icon with a margin off',
            'help' => 'When you save, phones use the plain icon above again.',
        ],
        'status' => [
            'label' => 'Status',
            'help' => 'Whether the game lets players in. A draft does not answer on its domain; make it live when its content (a pack) and its domain are ready.',
            'tip' => 'An empty game needs a pack before it can be made live. A new game always starts as a draft.',
            'options' => [
                'live' => ['label' => 'Live', 'help' => 'The game answers on its domain, and players can create accounts and play.'],
                'draft' => ['label' => 'Draft', 'help' => 'The game is being made: it answers to nobody on its domain and can be worked on in the panel only.'],
            ],
        ],
        'is_default' => [
            'label' => 'Default game',
            'help' => 'The game that answers where nothing says which one is meant: commands without --game, and an unknown host in development. It is not changed here.',
            'tip' => 'On a production server an address that no live game claims answers nobody, not the default game. While the default game has no live pack, it plays from the content files of the repository.',
        ],
    ],
];
