<?php

return [
    'entries' => [
        'tutorial' => [
            'label' => 'How the tutorial works',
            'help' => 'The tutorial leads a new driver through the first minutes, the first day and the first week of the game with text messages from the mentor (K.). Changes are saved as a draft first and reach the game only with the next published pack.',
            'steps' => [
                'At the top set “The first run”: the fuel at the start, the lap of the first breakdown, the longest first job, the amount the mentor covers and (optionally) the listing on the exchange.',
                'Add a step: give it a fixed key, a phase and the mentor’s number, then choose when it starts and when it ends (facts about the driver, for example lap_completed or cash:450).',
                'Write the goal for the goal bar, up to two messages of the mentor and the words of the reply button; optionally add what the step waits for. A step without messages is carried out without showing.',
                'Pick what the screen highlights and the workshop jobs the step shortens; if needed set a part for the start of the step and a reward for the end.',
                'Do not change keys and do not remove steps that players are in: publishing such a pack is refused.',
                'Save the draft, look it over on the “Changes to publish” page and ask for a pack on the “Publications” page.',
            ],
            'example' => 'Step “exchange” (phase 3): start cash:450 (it waits until the driver can afford a car), end car_bought, it highlights the exchange, and the listing of the tutorial (Rdzak GT-2, 25 years, 280,000 km, condition 30%, $450) waits in their city; after the purchase the mentor gives $300 back.',
        ],
    ],
];
