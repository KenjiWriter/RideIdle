<?php

return [
    'entries' => [
        'color_bg' => [
            'label' => 'Page background',
            'help' => 'The background of the whole app and of the input boxes. Usually the darkest colour of the theme; the page background itself is this colour slightly darkened.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
            'tip' => 'The dark wells and the shadows under the panels are worked out from this colour too, so changing it shifts the whole “bottom” of the theme.',
        ],
        'color_surface' => [
            'label' => 'Surface (cards)',
            'help' => 'The colour of cards and panels: the garage, shop and workshop screens and the flat cards on the front page. It should be a little lighter than the background.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_surface_2' => [
            'label' => 'Surface, level 2',
            'help' => 'A lighter surface: the top of panels and tiles, the headers of tables and the lit background of the stage the car stands on.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_surface_3' => [
            'label' => 'Surface, level 3',
            'help' => 'The lightest surface: button plates, chips, the top bar of the game and the empty cells of the fuel bar.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_line' => [
            'label' => 'Line',
            'help' => 'Thin lines and borders: the dividers between table rows and the outlines of cards and bars.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_line_2' => [
            'label' => 'Stronger line',
            'help' => 'More visible borders: input boxes, secondary buttons, tags and the edges of plates.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_ink' => [
            'label' => 'Main text',
            'help' => 'The colour of titles, numbers and ordinary text. It has to stand out clearly from the background and the surfaces, because the whole app depends on it to be readable.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
            'tip' => 'Half transparent, this colour is also the outline of the car’s name that is drawn behind the car on the stage.',
        ],
        'color_muted' => [
            'label' => 'Muted text',
            'help' => 'Second-level text: descriptions, paragraphs of the pages, table rows and the gear icon in the top bar.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_faint' => [
            'label' => 'Faint text',
            'help' => 'The quietest text: the small captions above fields, the hints in empty boxes and empty slots. Do not lower the contrast so far that it stops being readable.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
            'tip' => 'A few small details have fixed colours (for example the grey labels of the bottom menu, the line under a screen title and the phone of the tutorial), so the colours on this page will not change them.',
        ],
        'color_amber' => [
            'label' => 'Accent (amber by default)',
            'help' => 'The main accent colour, used for whatever can be pressed or is highlighted: the main button, the level badge, progress bars, the active menu tab, the border of a highlighted card, warning stripes and the coin.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
            'tip' => 'The light and dark shades of the button, its edge and its glow are all worked out from this one colour. “Amber” is only the name of the role: the colour can be anything.',
        ],
        'color_amber_ink' => [
            'label' => 'Text on the accent',
            'help' => 'The colour of lettering and icons that lie on the accent: the text of the main button, the level number, the labels on plates. It must contrast strongly with the accent colour.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_amber_bg' => [
            'label' => 'Accent background',
            'help' => 'A very dark shade of the accent: the background of the “You” row in the race table and the second colour of the warning stripes on the front page.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_cyan' => [
            'label' => 'Second accent (cyan by default)',
            'help' => 'The colour of what is in progress: the fuel bar on the tank screen, the bars and rings of workshop jobs, the border of an “in progress” panel, the RideCoin gem and the car-class plate in the top bar.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
            'tip' => 'It also tints the background of the stage the car stands on, lightly.',
        ],
        'color_cyan_bg' => [
            'label' => 'Dark shade of the second accent',
            'help' => 'The colour of text and icons on plates of the second accent: the car-class plate in the top bar and the clock of a job waiting in the queue. Despite the name “bg” it is not a background.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_ok' => [
            'label' => 'Success (green)',
            'help' => 'The colour of what went well: the fill of the fuel bar in the top bar, the “+” button by the tank, the plus sign when a statistic goes up, the border of a fitted part and the “OK” tags.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_danger' => [
            'label' => 'Error and warning (red)',
            'help' => 'The colour of errors and alarms: error messages, low fuel, a worn-out part, delete buttons and the minus sign when a statistic goes down.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_r1' => [
            'label' => 'Rarity 1: common',
            'help' => 'The colour of the lowest rarity of parts (“Common” in the game): the name of the rarity, the little squares, the border of a part’s tile and the marker of a part on the car.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
            'tip' => 'It is also the colour of the lettering of inactive tabs (Car | Fleet, Shop | Exchange | Dealer).',
        ],
        'color_r2' => [
            'label' => 'Rarity 2: uncommon',
            'help' => 'The colour of the “Uncommon” rarity: the name of the rarity, the little squares, the border of a part’s tile and the marker of a part on the car.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_r3' => [
            'label' => 'Rarity 3: rare',
            'help' => 'The colour of the “Rare” rarity: the name of the rarity, the little squares, the border of a part’s tile and the marker of a part on the car.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_r4' => [
            'label' => 'Rarity 4: epic',
            'help' => 'The colour of the “Epic” rarity: the name of the rarity, the little squares, the border of a part’s tile and the marker of a part on the car.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
        ],
        'color_r5' => [
            'label' => 'Rarity 5: legendary',
            'help' => 'The colour of the highest rarity, “Legendary”: the name of the rarity, the little squares, the border of a part’s tile and the marker of a part on the car.',
            'range' => '#rrggbb: six hex digits. Empty box: the app’s colour (:default).',
            'tip' => 'By default it is the same colour as the accent, so legendary parts glow like buttons. Change it on its own if the accent is to be different.',
        ],
        'font_display' => [
            'label' => 'Headings font',
            'help' => 'The font of titles, buttons, car names and big numbers (in the app it is narrow, heavy and set in capitals). Empty box: the app’s font.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'The app’s narrow, strong font, the default for headings. The file comes with the app.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'The app’s readable text font, the default for ordinary text. The file comes with the app.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'A font with letters of equal width, the default for numbers. The file comes with the app.'],
                'system-sans' => ['label' => 'System sans-serif', 'help' => 'The sans-serif font of the player’s device (for example Segoe UI or Roboto); it looks a little different on every device.'],
                'system-serif' => ['label' => 'System serif', 'help' => 'The serif font of the player’s device (Georgia or Times New Roman).'],
                'system-mono' => ['label' => 'System monospace', 'help' => 'The equal-width font of the player’s device (Consolas, Courier New or similar).'],
            ],
        ],
        'font_sans' => [
            'label' => 'Text font',
            'help' => 'The font of ordinary text: descriptions, paragraphs, messages and input boxes. Empty box: the app’s font.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'The app’s narrow, strong font, the default for headings. The file comes with the app.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'The app’s readable text font, the default for ordinary text. The file comes with the app.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'A font with letters of equal width, the default for numbers. The file comes with the app.'],
                'system-sans' => ['label' => 'System sans-serif', 'help' => 'The sans-serif font of the player’s device (for example Segoe UI or Roboto); it looks a little different on every device.'],
                'system-serif' => ['label' => 'System serif', 'help' => 'The serif font of the player’s device (Georgia or Times New Roman).'],
                'system-mono' => ['label' => 'System monospace', 'help' => 'The equal-width font of the player’s device (Consolas, Courier New or similar).'],
            ],
        ],
        'font_mono' => [
            'label' => 'Numbers font',
            'help' => 'The font of numbers and small captions: the chips in the top bar, the labels above fields and the tags. Empty box: the app’s font.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'The app’s narrow, strong font, the default for headings. The file comes with the app.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'The app’s readable text font, the default for ordinary text. The file comes with the app.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'A font with letters of equal width, the default for numbers. The file comes with the app.'],
                'system-sans' => ['label' => 'System sans-serif', 'help' => 'The sans-serif font of the player’s device (for example Segoe UI or Roboto); it looks a little different on every device.'],
                'system-serif' => ['label' => 'System serif', 'help' => 'The serif font of the player’s device (Georgia or Times New Roman).'],
                'system-mono' => ['label' => 'System monospace', 'help' => 'The equal-width font of the player’s device (Consolas, Courier New or similar).'],
            ],
        ],
        'css' => [
            'label' => 'Own style sheet (CSS)',
            'help' => 'CSS rules that come after the app’s style sheet and have the last word, so they can change the look of any element. The sheet cannot load anything from outside: @import, @font-face, @namespace, image-set(), src(), image(), element(), cross-fade(), paint(), attr(), expression(), javascript:, behavior:, -moz-binding, any url() other than a picture of the game (/api/v1/media/name) and a backslash outside a quoted string are all refused.',
            'example' => '.ttl { text-shadow: none; } takes the shadow off the titles of screens.',
            'range' => 'At most 20,000 bytes (a letter outside ASCII takes 2 to 4 bytes).',
            'tip' => 'The sheet is read the way a browser reads it: comments and quoted strings are skipped, so content: "\201C" is allowed. A sheet that does not pass is not saved, and the message gives the reason for each thing that was refused; a sheet is never accepted in part. A picture in url() must already be in the game’s store of images (it is uploaded in the panel), and its name is 64 hex characters with the extension png, webp or jpg.',
        ],
        'blocks' => [
            'label' => 'Page blocks',
            'help' => 'The page at the address of the game’s domain, from top to bottom. Add blocks and drag them to change the order; there can be at most 12. With no block at all the game shows the app’s own front page.',
            'tip' => 'The kinds of block: Top of the page (eyebrow, title, subtitle, a picture and the start buttons), Cards (1 to 6), Text (a title and paragraphs), Picture (with a description and a caption) and Buttons (a title, a sentence and the start buttons). The app adds the “Create your driver” and “Continue” buttons itself, and a block that lacks something it needs (a title, say) is left out.',
        ],
        'items' => [
            'label' => 'Cards',
            'help' => 'From 1 to 6 cards in one block, side by side (one under another on a narrow screen). Each has a kicker (a small caption above the title, optional), a title and a text.',
        ],
        'text_eyebrow' => [
            'label' => 'Eyebrow',
            'help' => 'A small line in capitals above the main title. Write it in the languages the game should speak; English and Polish are required once any language has text in the box, and a language without text shows English.',
            'example' => 'Browser game · idle · racing (the app’s own front page).',
            'range' => 'Up to :limit characters in each language.',
        ],
        'text_title' => [
            'label' => 'Title',
            'help' => 'The heading: in the Top of the page block it is the big title of the page (required), on a card the title of the card (required), and in the Text and Buttons blocks the heading above the content (optional). English and Polish are required when the box has text; a language without text shows English.',
            'example' => 'A workshop that races while you’re away (the app’s own front page).',
            'range' => 'Up to :limit characters in each language.',
        ],
        'text_subtitle' => [
            'label' => 'Subtitle',
            'help' => 'The sentence under the title in the Top of the page block, right above the start buttons. English and Polish are required when the box has text; a language without text shows English.',
            'range' => 'Up to :limit characters in each language.',
        ],
        'text_alt' => [
            'label' => 'Picture description',
            'help' => 'A description of the picture for people who cannot see it, and for when the picture does not load. Required in the Picture block, optional in the Top of the page block.',
            'example' => 'The workshop in Złomowo at dusk and three wrecks in the yard.',
            'range' => 'Up to :limit characters in each language.',
        ],
        'text_kicker' => [
            'label' => 'Card kicker',
            'help' => 'A small line in capitals above the title of a card. Optional; English and Polish are required when the box has text, and a language without text shows English.',
            'example' => 'Matching, Growth, Idle (the cards of the app’s own front page).',
            'range' => 'Up to :limit characters in each language.',
        ],
        'text_text' => [
            'label' => 'Text',
            'help' => 'A short text: on a card the description under the title (required), in the Buttons block the sentence under the title (optional). It is plain text, never HTML. A language without text shows English.',
            'range' => 'Up to :limit characters in each language.',
        ],
        'text_body' => [
            'label' => 'Body',
            'help' => 'The content of the Text block. A blank line starts a new paragraph; everything is plain text, never HTML. English and Polish are required; a language without text shows English.',
            'range' => 'Up to :limit characters in each language.',
        ],
        'text_caption' => [
            'label' => 'Caption',
            'help' => 'The caption under the picture in the Picture block. Optional; English and Polish are required when the box has text, and a language without text shows English.',
            'range' => 'Up to :limit characters in each language.',
        ],
        'text_description' => [
            'label' => 'Link preview description',
            'help' => 'The sentence under the title in the preview that a chat or a social network makes of the game’s address. There is one preview: it takes the text in the game’s language (from its settings) or English if that language has none, so the other languages are not read. Empty box: the name of the game.',
            'range' => 'Up to :limit characters in each language.',
        ],
        'share_title' => [
            'label' => 'Link preview title',
            'help' => 'The title that a chat or a social network shows when someone pastes the game’s address. It is one text, with no languages. Empty box: the name of the game.',
            'range' => 'Up to 70 characters.',
        ],
        'image_upload' => [
            'label' => 'Picture',
            'help' => 'The picture of the block. Choose a file and saving the draft keeps it in the store of images and writes its name into the block; with no choice the current picture stays.',
            'range' => 'PNG, WebP or JPEG, up to 1 MB, each side from :min to :max pixels.',
            'tip' => 'In the Top of the page block the picture is stretched to the full width as a band 220 px high (340 px on a wide screen) and cropped, so keep important things in the middle. In the Picture block it is shown whole at full width.',
        ],
        'share_image_upload' => [
            'label' => 'Preview picture',
            'help' => 'The picture in the link preview, usually 1200 × 630 pixels. Choose a file and saving the draft keeps it in the store of images; with no choice the current picture stays.',
            'range' => 'PNG, WebP or JPEG, up to 1 MB, each side from :min to :max pixels.',
            'tip' => 'Without a picture of its own the preview takes the game’s icon; a game with no icon has no picture in the preview.',
        ],
        'image_remove' => [
            'label' => 'Take the picture off',
            'help' => 'Turn it on and save the draft so that the block (or the link preview) has no picture any more. A file chosen in the box beside it takes precedence. It shows only when a picture is set.',
            'tip' => 'The Picture block needs a picture, so saving without one is refused.',
        ],
    ],
];
