<?php

return [
    'entries' => [
        'slug' => [
            'label' => 'Slug des Spiels',
            'help' => 'Der Kurzname, mit dem Befehle, das Panel und das Protokoll auf dieses Spiel zeigen. Du wählst ihn einmal, wenn das Spiel angelegt wird.',
            'example' => 'ships, raceidle',
            'range' => '2–32 Zeichen: Kleinbuchstaben, Ziffern und Bindestriche, zuerst ein Buchstabe; für jedes Spiel ein anderer.',
            'tip' => 'Er lässt sich danach nicht mehr ändern, weil die Befehle (--game=slug), die Deploy-Skripte, das Audit-Protokoll und der Spielwechsler des Panels das Spiel so benennen. Im Assistenten wird er aus dem Namen vorgeschlagen; du kannst ihn korrigieren, bevor du weitergehst.',
        ],
        'name' => [
            'label' => 'Name des Spiels',
            'help' => 'Wie das Spiel heißt: im Browser-Tab, in der App-Leiste, unter dem Symbol auf dem Startbildschirm eines Handys (nur die ersten Wörter, die in 12 Buchstaben passen), in E-Mails und auf dem Startbildschirm.',
            'example' => 'Weltraumschiffe',
            'range' => 'Bis zu 64 Zeichen.',
        ],
        'domain' => [
            'label' => 'Domain',
            'help' => 'Die Adresse, unter der das Spiel antwortet, in Kleinbuchstaben, ohne http:// und ohne Pfad. Wer diese Adresse öffnet, landet in diesem Spiel. Ohne Domain ist das Spiel nur vom Panel aus erreichbar (außer es ist das einzige Spiel der Installation).',
            'example' => 'ships.example.com',
            'range' => 'Mindestens zwei Teile (spiel.example.com), bis zu 190 Zeichen; für jedes Spiel eine andere.',
            'tip' => 'Bevor ein Zertifikat ausgestellt werden kann, muss die Domain auf diesen Server zeigen (ein A-Eintrag, AAAA für IPv6). Öffne nach dem Eintragen oder Ändern die Seite „Domain und SSL“ des Spiels: Sie prüft das DNS und bittet den Server, die Adresse und das SSL-Zertifikat einzurichten. Ein Spiel im Entwurf antwortet auch dann nicht auf seiner Domain.',
        ],
        'locale' => [
            'label' => 'Sprache des Spiels',
            'help' => 'Die Sprache, in der neue Spieler das Spiel sehen, bis sie ihre eigene wählen; sie ist auch die Sprache der Seite und der App des Spiels, wenn die Sprache des Besuchers keine der Sprachen des Spiels ist.',
            'options' => [
                'pl' => ['label' => 'Polski', 'help' => 'Neue Spieler sehen das Spiel auf Polnisch, bis sie eine andere Sprache wählen.'],
                'en' => ['label' => 'English', 'help' => 'Neue Spieler sehen das Spiel auf Englisch, bis sie eine andere Sprache wählen.'],
                'fr' => ['label' => 'Français', 'help' => 'Neue Spieler sehen das Spiel auf Französisch, bis sie eine andere Sprache wählen.'],
                'de' => ['label' => 'Deutsch', 'help' => 'Neue Spieler sehen das Spiel auf Deutsch, bis sie eine andere Sprache wählen.'],
                'pt-BR' => ['label' => 'Português (Brasil)', 'help' => 'Neue Spieler sehen das Spiel auf brasilianischem Portugiesisch, bis sie eine andere Sprache wählen.'],
            ],
        ],
        'theme_color' => [
            'label' => 'Rahmenfarbe',
            'help' => 'Die Farbe des Rahmens um das Spiel: die Leiste von Browser und Handy, der Startbildschirm der installierten App und die Linkvorschau. Die Farben im Spiel selbst stellst du auf der Seite „Marke und Seite“ ein.',
            'example' => '#0e1013 (das Dunkel des Autospiels)',
            'range' => 'Sechs Hexadezimalziffern mit #, zum Beispiel #0e1013.',
        ],
        'icon_upload' => [
            'label' => 'Symbol',
            'help' => 'Ein quadratisches Bild (PNG, WebP oder JPEG), mindestens :min × :min Pixel, am besten 512: das Symbol der App auf dem Startbildschirm und im Browser-Tab. Ohne Symbol behält das Spiel das Symbol des Autospiels.',
            'range' => 'Quadratisch, mindestens :min px pro Seite, bis zu 2048 px und 1 MB.',
            'tip' => 'Eine gewählte Datei ersetzt das aktuelle Symbol erst, wenn das Spiel gespeichert wird; ein Bild, das nicht quadratisch ist oder weniger als :min px pro Seite hat, wird abgelehnt, und nichts wird gespeichert.',
        ],
        'icon_remove' => [
            'label' => 'Symbol entfernen',
            'help' => 'Beim Speichern kehrt das Spiel zum Symbol des Autospiels zurück.',
        ],
        'icon_maskable_upload' => [
            'label' => 'Symbol mit Rand',
            'help' => 'Dasselbe Bild mit breitem Rand gezeichnet, damit das Wichtige in den mittleren 80% bleibt: für Handys, die Symbole in Kreise oder abgerundete Quadrate schneiden. Optional; ohne es wird das Symbol oben unverändert verwendet. Quadratisch, mindestens :min px.',
        ],
        'icon_maskable_remove' => [
            'label' => 'Symbol mit Rand entfernen',
            'help' => 'Beim Speichern verwenden Handys wieder das einfache Symbol von oben.',
        ],
        'status' => [
            'label' => 'Status',
            'help' => 'Ob das Spiel Spieler hereinlässt. Ein Entwurf antwortet nicht auf seiner Domain; schalte das Spiel live, wenn sein Inhalt (ein Paket) und seine Domain bereit sind.',
            'tip' => 'Ein leeres Spiel braucht ein Paket, bevor es live geschaltet werden kann. Ein neues Spiel beginnt immer als Entwurf.',
            'options' => [
                'live' => ['label' => 'Live', 'help' => 'Das Spiel antwortet auf seiner Domain, und Spieler können Konten anlegen und spielen.'],
                'draft' => ['label' => 'Entwurf', 'help' => 'Das Spiel wird gerade gebaut: Es antwortet niemandem auf seiner Domain und lässt sich nur im Panel bearbeiten.'],
            ],
        ],
        'is_default' => [
            'label' => 'Standardspiel',
            'help' => 'Das Spiel, das antwortet, wo nichts sagt, welches gemeint ist: Befehle ohne --game und ein unbekannter Host in der Entwicklung. Hier wird es nicht geändert.',
            'tip' => 'Auf einem Produktionsserver antwortet eine Adresse, die kein Live-Spiel beansprucht, niemandem, nicht dem Standardspiel. Solange das Standardspiel kein Live-Paket hat, spielt es aus den Inhaltsdateien des Repositorys.',
        ],
    ],
];
