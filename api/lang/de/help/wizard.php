<?php

return [
    'entries' => [
        'start' => [
            'label' => 'Inhalt zum Start',
            'help' => 'Womit das neue Spiel beginnt. Das Spiel wird in jedem Fall als Entwurf angelegt; das erste Paket wird sofort live geschaltet, wenn es Inhalt zum Starten gibt.',
            'tip' => 'Wenn der Inhalt nicht eingespielt werden kann (das kopierte Spiel hat kein Live-Paket oder der Inhalt besteht die Prüfungen nicht), wird das Spiel trotzdem angelegt, ohne Inhalt, und das Panel sagt es; nichts geht verloren.',
            'options' => [
                'template' => ['label' => 'Das Autospiel (Vorlage)', 'help' => 'Autos, Teile, Strecken, Regeln, Tutorial und Texte des Autospiels aus dem Repository werden das erste Paket, sofort live; am einfachsten startest du mit etwas, das schon funktioniert, und änderst es.'],
                'copy' => ['label' => 'Eine Kopie eines anderen Spiels', 'help' => 'Das Paket, das das gewählte Spiel gerade spielt, wird das erste Paket des neuen (ohne dessen Spieler, Konten und unveröffentlichte Entwürfe).'],
                'none' => ['label' => 'Ohne Inhalt', 'help' => 'Ein leeres Spiel. Es braucht ein Paket, bevor es live geschaltet werden kann.'],
            ],
        ],
        'copy_from' => [
            'label' => 'Welches Spiel kopieren',
            'help' => 'Das Spiel, dessen Live-Paket das erste Paket des neuen wird. Kopiert wird nur das Paket, das dieses Spiel gerade spielt: nicht seine Entwürfe, Spieler oder Konten.',
            'tip' => 'Hat das gewählte Spiel kein Live-Paket, wird das neue Spiel ohne Inhalt angelegt, und das Panel warnt davor.',
        ],
        'request_domain' => [
            'label' => 'Domain und SSL jetzt einrichten',
            'help' => 'Wenn das Spiel angelegt ist, den Server bitten, die Domain (den Eintrag des Webservers) und das SSL-Zertifikat einzurichten. Der Server nimmt die Anfrage innerhalb einer Minute auf, und du kannst sie auf der Seite „Domain und SSL“ verfolgen.',
            'tip' => 'Die Domain sollte schon auf diesen Server zeigen (nutze oben „DNS prüfen“), und die Installation braucht eine E-Mail-Adresse für Let’s Encrypt (ACME_EMAIL); ohne sie erscheint der Schalter nicht oder die Anfrage wird abgelehnt, und das Spiel wird trotzdem angelegt. Du kannst später auf der Seite „Domain und SSL“ erneut anfragen.',
        ],
    ],
];
