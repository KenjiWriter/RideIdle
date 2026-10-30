<?php

return [
    'entries' => [
        'slug' => [
            'label' => 'Slug (Kennung)',
            'help' => 'Die feste Kennung des Autos oder Teils: Kleinbuchstaben und Ziffern, durch Bindestriche verbunden. Du wählst sie einmal beim Anlegen; danach lässt sie sich nicht mehr ändern und wird auch nach dem Archivieren der Definition nie wiederverwendet.',
            'example' => 'rdzak-gt-4, turbo-osemka-t2.',
            'range' => 'Höchstens 64 Zeichen: a–z, 0–9 und einzelne Bindestriche zwischen den Wörtern.',
            'tip' => 'Das Paket, die Regeln (Shop-Liste, Serienreifen), das Tutorial und die Autos der Spieler verweisen über den Slug auf ein Auto oder Teil. Darum darf sich der angezeigte Name ändern, der Slug aber nicht.',
        ],
        'name' => [
            'label' => 'Name',
            'help' => 'Der Name, den die Spieler sehen, einmal pro Sprache des Spiels. Englisch und Polnisch sind Pflicht; eine leere Sprache zeigt den englischen Text.',
            'example' => 'Rdzak GT-4; Straßenreifen (de) und Street tires (en).',
            'range' => 'Höchstens 200 Zeichen pro Sprache.',
        ],
        'description' => [
            'label' => 'Beschreibung',
            'help' => 'Ein kurzer Text für den Spieler, heute auf dem Bildschirm gezeigt, auf dem das Start-Wrack gewählt wird. Er ist freiwillig; ein Auto ohne Beschreibung hat einfach keine.',
            'range' => 'Höchstens 200 Zeichen pro Sprache.',
        ],
        'image_upload' => [
            'label' => 'Bild hochladen',
            'help' => 'Ein Bild des Autos oder Teils, auf seinen Karten gezeigt, wo das Spiel kein eigenes hat. Ohne Bild sieht die Definition aus wie bisher.',
            'range' => 'PNG, WebP oder JPEG, Datei bis 1 MB, jede Seite von :min bis :max Pixel.',
            'tip' => 'Die Datei liegt in der Datenbank unter der Prüfsumme ihrer Bytes, die Definition nennt nur ihren Namen. Sie kommt mit dem nächsten Paket ins Spiel, und ein als Datei gespeichertes Paket nimmt sie mit.',
        ],
        'image_remove' => [
            'label' => 'Bild entfernen',
            'help' => 'Schalte es ein und speichere den Entwurf, damit das Auto oder Teil kein Bild mehr hat. Der Schalter erscheint nur, wenn ein Bild gesetzt ist.',
        ],
    ],
];
