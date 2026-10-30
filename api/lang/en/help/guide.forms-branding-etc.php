<?php

return [
    'entries' => [
        'branding' => [
            'label' => 'How the brand and page form works',
            'help' => 'Here you set the look of this game’s app: colours, fonts, a style sheet of its own, the blocks of the front page and the link preview. An empty box is the app’s own look; a saved draft reaches the game with the next published pack.',
            'steps' => [
                'Start with the colours: change a few (background, accent, text) and leave the rest empty. Colours are written as #rrggbb.',
                'Choose the fonts for headings, text and numbers, or leave the app’s font.',
                'Add the blocks of the front page from the top (Top of the page, Cards, Text, Picture, Buttons) and write the texts at least in English and Polish.',
                'Set the title, the description and the picture of the link preview: this is what shows when someone pastes the game’s address in a chat.',
                'Add your own CSS last, and only when the colours are not enough; the rules that do not pass are reported when you save.',
                'Save the draft and publish a pack so that players see the change.',
            ],
            'example' => 'A game about ships: the Accent #2fb7c9 instead of amber, the headings font “System serif”, a Top of the page block titled “Fleets at sea” (in Polish “Floty na morzu”) and a link preview with the same title.',
        ],
        'vocabulary' => [
            'label' => 'How the Vocabulary works',
            'help' => 'The Vocabulary changes the words of the app for this game (the names of slots, the menu, the texts of screens) in five languages. An empty box is the app’s own text; a saved draft reaches the game with the next pack.',
            'steps' => [
                'Choose an area, or type a word in Search and confirm with Enter; at most 30 messages are shown at a time.',
                'Under the key of a message is the app’s text, and in an empty box of a language it shows as a hint.',
                'Write the new text in the languages you have; leave the names in braces, such as {name}, unchanged.',
                'Fill in every language you care about: an empty language will show the pack’s English text, not the app’s text in that language.',
                'Save before you change the area, the search or the filter: unsaved changes are lost. Changed messages have a dot ● by the key.',
                'You can download a whole theme to a file or load one from a file with the buttons at the top of the page; a loaded one replaces all the words of the pack in the draft.',
            ],
            'example' => 'A game about ships: a slot with the key hull gets the message slot.hull, in English “Hull” and in Polish “Kadłub”.',
        ],
        'rules_text' => [
            'label' => 'How editing the rules as text works',
            'help' => 'The advanced way: the whole document of the rules or of the tutorial as JSON, for what the other pages do not show. The document is judged just as on the other pages and saved as a draft that reaches the game with the next pack.',
            'steps' => [
                'Choose the document: Game rules or Tutorial.',
                'Change only what you must, and keep the brackets, commas and quotation marks. Most numbers are easier to change in the Parameter studio.',
                'Save. A syntax error is shown by the box, an error in the content by a red notification with the place and the reason; in both cases nothing is saved.',
                'Removing a city, a track or a tutorial step that players use is refused.',
                'Changing the document in the Document box throws away the text that was not saved.',
            ],
            'example' => 'In the Game rules, change “price_cents_per_liter” in “fuel” from 200 to 250 (these are cents: $2.50 a litre), save, and check in the Parameter studio that the Fuel section shows $2.50.',
        ],
        'studio' => [
            'label' => 'How the Parameter studio works',
            'help' => 'The studio changes the numbers of the game, one section at a time: prices, times, rewards, wear. The numbers are in the unit people think in (%, s, $, l); a saved draft reaches the game with the next pack.',
            'steps' => [
                'Leave simple mode on (the pace and the money of the game); turn it off when you need every number.',
                'Choose a section; the sentence under it says what changing its numbers does in the game.',
                'Set a number with its slider, or turn Sliders off and type the exact value. Beside a number you see its value and, in yellow, the value of the live pack when it is different; the arrow puts the live one back.',
                'Save before you change the section, the mode or the sliders: unsaved changes are lost.',
                'Use the Balance preview to see how the saved draft changes the pace of the game against the live pack.',
            ],
            'example' => 'In the Fuel section a litre costs $2.00 (200 cents in the document). Set $2.50, save, and look in the Balance preview at how the pace of the game changes.',
        ],
        'model' => [
            'label' => 'How the Model of the game works',
            'help' => 'The model says what a car is made of: the slots that parts go into (with the role that the formulas know) and the statistics that the screens show. A saved draft reaches the game with the next pack.',
            'steps' => [
                'Add a slot: give it a key (lowercase letters, digits, _) and choose a role, or leave “No role”.',
                'Set the order of the slots by dragging: the garage and the shop list them in that order.',
                'Hide a slot if the garage is not to list it.',
                'Put the statistics in the order of the screens, or leave out those that the game should not show.',
                'Write the name of a new slot in the Vocabulary as slot.key (English and Polish), so that the game does not show the bare key.',
                'Save; a refusal says what still names the slot you want to get rid of.',
            ],
            'example' => 'A game about ships: a slot hull with the role Tires (it decides grip), reactor with the role Engine and thrusters with the role Forced induction; the names “Hull”, “Reactor” and “Thrusters” written in the Vocabulary as slot.hull, slot.reactor and slot.thrusters.',
        ],
    ],
];
