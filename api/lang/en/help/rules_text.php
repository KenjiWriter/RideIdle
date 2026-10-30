<?php

return [
    'entries' => [
        'document' => [
            'label' => 'Document',
            'help' => 'Which document to show as text. Changing the document throws away what was typed and not saved.',
            'options' => [
                'starter' => ['label' => 'Game rules', 'help' => 'Every number and rule of the world (starter.json): tracks, prices, times, wear, the market. The same as the Parameter studio, but all of it as text.'],
                'tutorial' => ['label' => 'Tutorial', 'help' => 'The steps of the tutorial and their rewards (tutorial.json); the same data that the Tutorial page edits.'],
            ],
        ],
        'json' => [
            'label' => 'Document text (JSON)',
            'help' => 'The whole document as JSON, for what the other pages do not show. The same schema and the same rules of the world judge it as on the other pages; saving makes a draft that reaches the game with the next pack.',
            'tip' => 'A syntax error (a missing comma, say) is shown by the box itself and nothing is saved; an error in the content (a wrong value, a missing field) is shown by a red notification with the place and the reason (the first eight), also with no save. A document equal to the published one leaves no draft.',
        ],
    ],
];
