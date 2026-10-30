<?php

return [
    'entries' => [
        'world' => [
            'label' => 'So funktioniert das Formular der Welt',
            'help' => 'Hier stellst du die Orte ein, an denen Autos verdienen: Städte mit ihrem Wetter, die Straßen dazwischen und die Strecken. Was du eingibst, wird als Entwurf der Regeln gespeichert und erreicht die Spieler erst mit dem nächsten veröffentlichten Paket.',
            'steps' => [
                'Tab Städte: Füge eine Stadt mit Slug, Namen in den Sprachen, einem Ort auf der Karte und den Chancen ihres Wetters hinzu (zusammen 100%).',
                'Tab Straßen: Verbinde die Städte mit Straßen und gib ihre Länge in km an; jede Stadt muss von der ersten aus erreichbar sein.',
                'Tab Strecken: Füge eine Strecke in einer Stadt hinzu: Untergrund, Art, Stufe, Rundenlänge, Referenzrunde, Kraftstoff, Verdienst und XP.',
                'Lege fest, was die Strecke von einem Auto verlangt (Prozente, die zusammen 100 ergeben), und den Referenzwert jedes dieser Werte.',
                'Speichere den Entwurf; die Änderungen erreichen die Spieler mit dem nächsten veröffentlichten Paket (Seite Veröffentlichungen).',
            ],
            'example' => 'Ein Schotter-Sprint in Kruszyn wie im Autospiel: Slug kruszyn-gravel-sprint, Untergrund Schotter, Art Sprint, Stufe 3, 380 m, Referenzrunde 15 000 ms (15 s), Kraftstoff 180 ml, Verdienst 2,92 $, XP 3; verlangt Beschleunigung 20, Haftung 30, Fahrverhalten 15, Bremsen 10, Gelände 25 (Referenzwerte 65, 92, 71, 71, 80).',
        ],
        'game' => [
            'label' => 'So funktioniert das Formular des Spiels',
            'help' => 'Das ist ein Spiel der Installation: sein Name, seine Adresse, Sprache, Farbe, Symbole und ob es live ist. Das Spiel selbst (Autos, Regeln, Texte) bearbeitest du auf den anderen Seiten; dieses Formular ist nur seine Identität.',
            'steps' => [
                'Ändere Name, Sprache oder Farbe; die Leiste über dem Formular und die Vorschau zeigen, wie das Spiel aussehen wird, bevor du speicherst.',
                'Trage die Domain ein oder ändere sie, öffne dann die Seite „Domain und SSL“: Das DNS muss auf diesen Server zeigen, bevor ein Zertifikat ausgestellt wird.',
                'Lade ein quadratisches Symbol hoch (und, wenn du magst, eines mit Rand für Handys, die Symbole zuschneiden).',
                'Setze den Status auf Live, wenn Inhalt und Domain bereit sind; ein Entwurf antwortet niemandem.',
                'Speichere. Slug und Standardspiel lassen sich hier nicht ändern.',
            ],
            'example' => 'Weltraumschiffe unter ships.example.com: Sprache Deutsch, Farbe #1a2b4c, ein Symbol mit 512 × 512, Status Live.',
        ],
        'wizard' => [
            'label' => 'So legt der Assistent ein Spiel an',
            'help' => 'Ein Spiel entsteht in drei Schritten: sein Name und seine Adresse, der Inhalt, mit dem es beginnt, und was der Server mit der Domain tut. Es beginnt als Entwurf, der niemandem antwortet, bis du es live schaltest; Slug, Farben, Symbole und Aussehen kannst du später einstellen.',
            'steps' => [
                'Name und Domain: Gib den Namen ein (der Slug wird daraus vorgeschlagen), die Domain, falls du eine hast, die Sprache und die Farbe.',
                'Startpunkt: Wähle das Autospiel als Vorlage, eine Kopie eines anderen Spiels oder keinen Inhalt.',
                'Domain und SSL: Prüfe, ob die Domain auf diesen Server zeigt, und bitte den Server, wenn du willst, sie gleich einzurichten.',
                'Drücke „Spiel anlegen“. Das Panel führt dich zur Seite „Domain und SSL“ des neuen Spiels und wechselt zu ihm.',
                'Wenn Inhalt und Domain bereit sind, setze den Status beim Bearbeiten des Spiels auf Live.',
            ],
            'example' => 'Name „Weltraumschiffe“, Domain ships.example.com, Sprache Deutsch, Start vom Autospiel, Domain angefragt: ein Spiel im Entwurf mit dem Live-Paket des Autospiels und einer Anfrage, die auf den Server wartet.',
        ],
    ],
];
