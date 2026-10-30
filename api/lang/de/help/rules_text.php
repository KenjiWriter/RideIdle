<?php

return [
    'entries' => [
        'document' => [
            'label' => 'Dokument',
            'help' => 'Welches Dokument als Text gezeigt wird. Ein Wechsel des Dokuments verwirft, was eingegeben und nicht gespeichert wurde.',
            'options' => [
                'starter' => ['label' => 'Spielregeln', 'help' => 'Alle Zahlen und Regeln der Welt (starter.json): Strecken, Preise, Zeiten, Verschleiß, Markt. Dasselbe wie das Parameter-Studio, aber vollständig als Text.'],
                'tutorial' => ['label' => 'Tutorial', 'help' => 'Die Schritte des Tutorials und ihre Belohnungen (tutorial.json); dieselben Daten, die die Seite Tutorial bearbeitet.'],
            ],
        ],
        'json' => [
            'label' => 'Text des Dokuments (JSON)',
            'help' => 'Das ganze Dokument als JSON, für das, was die anderen Seiten nicht zeigen. Es wird vom selben Schema und denselben Regeln der Welt geprüft wie auf den anderen Seiten; Speichern erzeugt einen Entwurf, der mit dem nächsten Paket ins Spiel kommt.',
            'tip' => 'Einen Syntaxfehler (etwa ein fehlendes Komma) zeigt das Feld selbst, und nichts wird gespeichert; einen Fehler im Inhalt (falscher Wert, fehlendes Feld) zeigt eine rote Meldung mit Stelle und Grund (die ersten acht), ebenfalls ohne Speichern. Ein Dokument, das dem veröffentlichten gleicht, hinterlässt keinen Entwurf.',
        ],
    ],
];
