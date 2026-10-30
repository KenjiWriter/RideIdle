<?php

return [
    'entries' => [
        'slug' => [
            'label' => 'Slug (identifier)',
            'help' => 'The permanent identifier of the car or part: lower-case letters and digits joined by dashes. You choose it once, when you create the definition; afterwards it cannot be changed, and it is not reused even after the definition is archived.',
            'example' => 'rdzak-gt-4, turbo-osemka-t2.',
            'range' => 'Up to 64 characters: a–z, 0–9 and single dashes between words.',
            'tip' => 'The pack, the rules (the shop list, the stock tires), the tutorial and the players\' cars all refer to a car or part by its slug. That is why the display name may change and the slug may not.',
        ],
        'name' => [
            'label' => 'Name',
            'help' => 'The name players see, one per language of the game. English and Polish are required; a language left empty shows the English text.',
            'example' => 'Rdzak GT-4; Street tires (en) and Opony uliczne (pl).',
            'range' => 'Up to 200 characters in each language.',
        ],
        'description' => [
            'label' => 'Description',
            'help' => 'A short text for the player, shown today on the screen where a starter wreck is chosen. It is optional; a car without one simply has none.',
            'range' => 'Up to 200 characters in each language.',
        ],
        'image_upload' => [
            'label' => 'Upload a picture',
            'help' => 'A picture of the car or part, shown on its cards wherever the game has none of its own. Without one the definition looks as it did before.',
            'range' => 'PNG, WebP or JPEG, file up to 1 MB, each side from :min to :max pixels.',
            'tip' => 'The file is kept in the database under the checksum of its bytes, and the definition only names it. It reaches the game with the next pack, and a pack saved as a file carries it along.',
        ],
        'image_remove' => [
            'label' => 'Remove the picture',
            'help' => 'Turn it on and save the draft so that the car or part has no picture any more. The switch only shows when a picture is set.',
        ],
    ],
];
