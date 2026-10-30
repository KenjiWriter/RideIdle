<?php

return [
    'entries' => [
        'color_bg' => [
            'label' => 'Seitenhintergrund',
            'help' => 'Die Hintergrundfarbe der ganzen App und der Eingabefelder. Meist die dunkelste Farbe des Themes; der Seitenhintergrund selbst ist diese Farbe, leicht abgedunkelt.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
            'tip' => 'Auch die dunklen Mulden und die Schatten unter den Panels werden aus dieser Farbe berechnet; ändert man sie, verschiebt sich also der ganze „Unterbau“ des Themes.',
        ],
        'color_surface' => [
            'label' => 'Fläche (Karten)',
            'help' => 'Die Farbe von Karten und Panels: der Bildschirme von Garage, Shop und Werkstatt und der flachen Karten auf der Startseite. Sie sollte etwas heller sein als der Hintergrund.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_surface_2' => [
            'label' => 'Fläche, Stufe 2',
            'help' => 'Eine hellere Fläche: der obere Teil von Panels und Kacheln, die Kopfzeilen von Tabellen und der beleuchtete Hintergrund der Bühne, auf der das Auto steht.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_surface_3' => [
            'label' => 'Fläche, Stufe 3',
            'help' => 'Die hellste Fläche: Tastenplatten, Chips, die obere Leiste des Spiels und die leeren Felder der Tankanzeige.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_line' => [
            'label' => 'Linie',
            'help' => 'Dünne Linien und Rahmen: die Trennlinien zwischen Tabellenzeilen und die Umrisse von Karten und Balken.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_line_2' => [
            'label' => 'Stärkere Linie',
            'help' => 'Besser sichtbare Rahmen: Eingabefelder, zweitrangige Schaltflächen, Marken und die Kanten von Platten.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_ink' => [
            'label' => 'Haupttext',
            'help' => 'Die Farbe von Titeln, Zahlen und normalem Text. Sie muss sich deutlich von Hintergrund und Flächen abheben, denn davon hängt die Lesbarkeit der ganzen App ab.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
            'tip' => 'Halbtransparent ist diese Farbe auch der Umriss des Autonamens, der auf der Bühne hinter dem Auto gezeichnet wird.',
        ],
        'color_muted' => [
            'label' => 'Gedämpfter Text',
            'help' => 'Text der zweiten Ebene: Beschreibungen, Absätze der Seiten, Tabellenzeilen und das Zahnrad-Symbol in der oberen Leiste.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_faint' => [
            'label' => 'Blasser Text',
            'help' => 'Der leiseste Text: die kleinen Beschriftungen über den Feldern, Hinweise in leeren Feldern und leere Slots. Senke den Kontrast nicht so weit, dass er unlesbar wird.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
            'tip' => 'Einige kleine Elemente haben feste Farben (zum Beispiel die grauen Beschriftungen des unteren Menüs, die Linie unter einem Bildschirmtitel und das Telefon des Tutorials); die Farben dieser Seite ändern sie nicht.',
        ],
        'color_amber' => [
            'label' => 'Akzent (standardmäßig Bernstein)',
            'help' => 'Die Hauptakzentfarbe für alles, was man drücken kann oder was hervorgehoben ist: die Hauptschaltfläche, das Level-Abzeichen, Fortschrittsbalken, der aktive Menü-Tab, der Rahmen einer hervorgehobenen Karte, Warnstreifen und die Münze.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
            'tip' => 'Die hellen und dunklen Töne der Schaltfläche, ihr Rand und ihr Schein werden aus dieser einen Farbe berechnet. „Amber“ ist nur der Name der Rolle: Die Farbe kann beliebig sein.',
        ],
        'color_amber_ink' => [
            'label' => 'Text auf dem Akzent',
            'help' => 'Die Farbe von Schrift und Symbolen auf dem Akzent: der Text der Hauptschaltfläche, die Levelzahl, die Beschriftungen auf Platten. Sie muss stark mit der Akzentfarbe kontrastieren.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_amber_bg' => [
            'label' => 'Akzenthintergrund',
            'help' => 'Ein sehr dunkler Ton des Akzents: der Hintergrund der Zeile „Du“ in der Renntabelle und die zweite Farbe der Warnstreifen auf der Startseite.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_cyan' => [
            'label' => 'Zweiter Akzent (standardmäßig Cyan)',
            'help' => 'Die Farbe dessen, was läuft: die Tankanzeige auf dem Tankbildschirm, die Balken und Ringe der Werkstattaufträge, der Rahmen eines Panels „in Arbeit“, der RideCoin-Edelstein und das Klassenschild des Autos in der oberen Leiste.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
            'tip' => 'Sie tönt außerdem den Hintergrund der Bühne, auf der das Auto steht, leicht ein.',
        ],
        'color_cyan_bg' => [
            'label' => 'Dunkler Ton des zweiten Akzents',
            'help' => 'Die Farbe von Text und Symbolen auf Platten des zweiten Akzents: das Klassenschild des Autos in der oberen Leiste und die Uhr eines Auftrags, der in der Warteschlange steht. Trotz des Namens „bg“ ist sie kein Hintergrund.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_ok' => [
            'label' => 'Erfolg (grün)',
            'help' => 'Die Farbe dessen, was geklappt hat: die Füllung der Tankanzeige in der oberen Leiste, die „+“-Schaltfläche am Tank, das Pluszeichen, wenn ein Wert steigt, der Rahmen eines eingebauten Teils und die „OK“-Marken.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_danger' => [
            'label' => 'Fehler und Warnung (rot)',
            'help' => 'Die Farbe von Fehlern und Alarmen: Fehlermeldungen, wenig Kraftstoff, ein abgenutztes Teil, Löschen-Schaltflächen und das Minuszeichen, wenn ein Wert sinkt.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_r1' => [
            'label' => 'Seltenheit 1: gewöhnlich',
            'help' => 'Die Farbe der niedrigsten Seltenheit von Teilen („Gewöhnlich“ im Spiel): der Name der Seltenheit, die Quadrate, der Rahmen der Teilekachel und die Markierung des Teils am Auto.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
            'tip' => 'Sie ist auch die Farbe der Beschriftung inaktiver Tabs (Auto | Flotte, Shop | Börse | Händler).',
        ],
        'color_r2' => [
            'label' => 'Seltenheit 2: ungewöhnlich',
            'help' => 'Die Farbe der Seltenheit „Ungewöhnlich“: der Name der Seltenheit, die Quadrate, der Rahmen der Teilekachel und die Markierung des Teils am Auto.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_r3' => [
            'label' => 'Seltenheit 3: selten',
            'help' => 'Die Farbe der Seltenheit „Selten“: der Name der Seltenheit, die Quadrate, der Rahmen der Teilekachel und die Markierung des Teils am Auto.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_r4' => [
            'label' => 'Seltenheit 4: episch',
            'help' => 'Die Farbe der Seltenheit „Episch“: der Name der Seltenheit, die Quadrate, der Rahmen der Teilekachel und die Markierung des Teils am Auto.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
        ],
        'color_r5' => [
            'label' => 'Seltenheit 5: legendär',
            'help' => 'Die Farbe der höchsten Seltenheit, „Legendär“: der Name der Seltenheit, die Quadrate, der Rahmen der Teilekachel und die Markierung des Teils am Auto.',
            'range' => '#rrggbb: sechs Hexziffern. Leeres Feld: die Farbe der App (:default).',
            'tip' => 'Standardmäßig hat sie dieselbe Farbe wie der Akzent, sodass legendäre Teile wie Schaltflächen leuchten. Ändere sie einzeln, wenn der Akzent anders sein soll.',
        ],
        'font_display' => [
            'label' => 'Schrift der Überschriften',
            'help' => 'Die Schrift von Titeln, Schaltflächen, Autonamen und großen Zahlen (in der App ist sie schmal, kräftig und in Großbuchstaben gesetzt). Leeres Feld: die Schrift der App.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'Die schmale, kräftige Schrift der App, Standard für Überschriften. Die Datei wird mit der App geliefert.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'Die gut lesbare Textschrift der App, Standard für normalen Text. Die Datei wird mit der App geliefert.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'Eine Schrift mit gleich breiten Zeichen, Standard für Zahlen. Die Datei wird mit der App geliefert.'],
                'system-sans' => ['label' => 'System-Sans-Serif', 'help' => 'Die serifenlose Schrift des Geräts des Spielers (zum Beispiel Segoe UI oder Roboto); sie sieht auf jedem Gerät etwas anders aus.'],
                'system-serif' => ['label' => 'System-Serif', 'help' => 'Die Serifenschrift des Geräts des Spielers (Georgia oder Times New Roman).'],
                'system-mono' => ['label' => 'System-Monospace', 'help' => 'Die Schrift mit gleich breiten Zeichen des Geräts des Spielers (Consolas, Courier New oder ähnlich).'],
            ],
        ],
        'font_sans' => [
            'label' => 'Schrift des Textes',
            'help' => 'Die Schrift des normalen Textes: Beschreibungen, Absätze, Meldungen und Eingabefelder. Leeres Feld: die Schrift der App.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'Die schmale, kräftige Schrift der App, Standard für Überschriften. Die Datei wird mit der App geliefert.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'Die gut lesbare Textschrift der App, Standard für normalen Text. Die Datei wird mit der App geliefert.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'Eine Schrift mit gleich breiten Zeichen, Standard für Zahlen. Die Datei wird mit der App geliefert.'],
                'system-sans' => ['label' => 'System-Sans-Serif', 'help' => 'Die serifenlose Schrift des Geräts des Spielers (zum Beispiel Segoe UI oder Roboto); sie sieht auf jedem Gerät etwas anders aus.'],
                'system-serif' => ['label' => 'System-Serif', 'help' => 'Die Serifenschrift des Geräts des Spielers (Georgia oder Times New Roman).'],
                'system-mono' => ['label' => 'System-Monospace', 'help' => 'Die Schrift mit gleich breiten Zeichen des Geräts des Spielers (Consolas, Courier New oder ähnlich).'],
            ],
        ],
        'font_mono' => [
            'label' => 'Schrift der Zahlen',
            'help' => 'Die Schrift von Zahlen und kleinen Beschriftungen: die Chips in der oberen Leiste, die Bezeichnungen über den Feldern und die Marken. Leeres Feld: die Schrift der App.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'Die schmale, kräftige Schrift der App, Standard für Überschriften. Die Datei wird mit der App geliefert.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'Die gut lesbare Textschrift der App, Standard für normalen Text. Die Datei wird mit der App geliefert.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'Eine Schrift mit gleich breiten Zeichen, Standard für Zahlen. Die Datei wird mit der App geliefert.'],
                'system-sans' => ['label' => 'System-Sans-Serif', 'help' => 'Die serifenlose Schrift des Geräts des Spielers (zum Beispiel Segoe UI oder Roboto); sie sieht auf jedem Gerät etwas anders aus.'],
                'system-serif' => ['label' => 'System-Serif', 'help' => 'Die Serifenschrift des Geräts des Spielers (Georgia oder Times New Roman).'],
                'system-mono' => ['label' => 'System-Monospace', 'help' => 'Die Schrift mit gleich breiten Zeichen des Geräts des Spielers (Consolas, Courier New oder ähnlich).'],
            ],
        ],
        'css' => [
            'label' => 'Eigenes Stylesheet (CSS)',
            'help' => 'CSS-Regeln, die nach dem Stylesheet der App kommen und das letzte Wort haben, sodass sie das Aussehen jedes Elements ändern können. Das Stylesheet darf nichts von außen laden: abgelehnt werden @import, @font-face, @namespace, image-set(), src(), image(), element(), cross-fade(), paint(), attr(), expression(), javascript:, behavior:, -moz-binding, jedes url() außer einem Bild des Spiels (/api/v1/media/name) und ein Backslash außerhalb eines Strings in Anführungszeichen.',
            'example' => '.ttl { text-shadow: none; } nimmt den Schatten unter den Titeln der Bildschirme weg.',
            'range' => 'Höchstens 20.000 Bytes (ein Buchstabe außerhalb von ASCII braucht 2 bis 4 Bytes).',
            'tip' => 'Das Stylesheet wird so gelesen wie von einem Browser: Kommentare und Strings in Anführungszeichen werden übersprungen, daher ist content: "\201C" erlaubt. Ein Stylesheet, das nicht besteht, wird nicht gespeichert, und die Meldung nennt den Grund für jede abgelehnte Sache; nie wird nur ein Teil angenommen. Ein Bild in url() muss bereits im Bilderspeicher des Spiels liegen (es wird im Panel hochgeladen), und sein Name besteht aus 64 Hexzeichen mit der Endung png, webp oder jpg.',
        ],
        'blocks' => [
            'label' => 'Blöcke der Seite',
            'help' => 'Die Seite unter der Adresse der Domain des Spiels, von oben nach unten. Füge Blöcke hinzu und ziehe sie, um die Reihenfolge zu ändern; es sind höchstens 12. Ohne einen einzigen Block zeigt das Spiel die eigene Startseite der App.',
            'tip' => 'Die Arten von Blöcken: Seitenkopf (Überzeile, Titel, Untertitel, ein Bild und die Start-Schaltflächen), Karten (1 bis 6), Text (ein Titel und Absätze), Bild (mit Beschreibung und Bildunterschrift) und Schaltflächen (ein Titel, ein Satz und die Start-Schaltflächen). Die Schaltflächen „Fahrer erstellen“ und „Weiter“ fügt die App selbst hinzu, und ein Block, dem etwas Notwendiges fehlt (etwa ein Titel), wird übergangen.',
        ],
        'items' => [
            'label' => 'Karten',
            'help' => 'Von 1 bis 6 Karten in einem Block, nebeneinander (auf einem schmalen Bildschirm untereinander). Jede hat eine Marke (eine kleine Beschriftung über dem Titel, optional), einen Titel und einen Text.',
        ],
        'text_eyebrow' => [
            'label' => 'Überzeile',
            'help' => 'Eine kleine Zeile in Großbuchstaben über dem Haupttitel. Schreibe sie in den Sprachen, die das Spiel sprechen soll; Englisch und Polnisch sind Pflicht, sobald irgendeine Sprache Text im Feld hat, und eine Sprache ohne Text zeigt Englisch.',
            'example' => 'Browserspiel · Idle · Rennen (die eigene Startseite der App).',
            'range' => 'Bis zu :limit Zeichen in jeder Sprache.',
        ],
        'text_title' => [
            'label' => 'Titel',
            'help' => 'Die Überschrift: Im Block Seitenkopf ist es der große Titel der Seite (Pflicht), auf einer Karte der Titel der Karte (Pflicht), in den Blöcken Text und Schaltflächen die Überschrift über dem Inhalt (optional). Englisch und Polnisch sind Pflicht, wenn das Feld Text hat; eine Sprache ohne Text zeigt Englisch.',
            'example' => 'Eine Werkstatt, die fährt, während du weg bist (die eigene Startseite der App).',
            'range' => 'Bis zu :limit Zeichen in jeder Sprache.',
        ],
        'text_subtitle' => [
            'label' => 'Untertitel',
            'help' => 'Der Satz unter dem Titel im Block Seitenkopf, direkt über den Start-Schaltflächen. Englisch und Polnisch sind Pflicht, wenn das Feld Text hat; eine Sprache ohne Text zeigt Englisch.',
            'range' => 'Bis zu :limit Zeichen in jeder Sprache.',
        ],
        'text_alt' => [
            'label' => 'Bildbeschreibung',
            'help' => 'Eine Beschreibung des Bildes für Menschen, die es nicht sehen, und für den Fall, dass das Bild nicht lädt. Im Block Bild Pflicht, im Block Seitenkopf optional.',
            'example' => 'Die Werkstatt in Złomowo in der Dämmerung und drei Wracks im Hof.',
            'range' => 'Bis zu :limit Zeichen in jeder Sprache.',
        ],
        'text_kicker' => [
            'label' => 'Marke der Karte',
            'help' => 'Eine kleine Zeile in Großbuchstaben über dem Titel einer Karte. Optional; Englisch und Polnisch sind Pflicht, wenn das Feld Text hat, und eine Sprache ohne Text zeigt Englisch.',
            'example' => 'Abstimmung, Wachstum, Idle (die Karten der eigenen Startseite der App).',
            'range' => 'Bis zu :limit Zeichen in jeder Sprache.',
        ],
        'text_text' => [
            'label' => 'Text',
            'help' => 'Ein kurzer Text: auf einer Karte die Beschreibung unter dem Titel (Pflicht), im Block Schaltflächen der Satz unter dem Titel (optional). Es ist reiner Text, nie HTML. Eine Sprache ohne Text zeigt Englisch.',
            'range' => 'Bis zu :limit Zeichen in jeder Sprache.',
        ],
        'text_body' => [
            'label' => 'Inhalt',
            'help' => 'Der Inhalt des Blocks Text. Eine Leerzeile beginnt einen neuen Absatz; alles ist reiner Text, nie HTML. Englisch und Polnisch sind Pflicht; eine Sprache ohne Text zeigt Englisch.',
            'range' => 'Bis zu :limit Zeichen in jeder Sprache.',
        ],
        'text_caption' => [
            'label' => 'Bildunterschrift',
            'help' => 'Die Unterschrift unter dem Bild im Block Bild. Optional; Englisch und Polnisch sind Pflicht, wenn das Feld Text hat, und eine Sprache ohne Text zeigt Englisch.',
            'range' => 'Bis zu :limit Zeichen in jeder Sprache.',
        ],
        'text_description' => [
            'label' => 'Beschreibung der Linkvorschau',
            'help' => 'Der Satz unter dem Titel in der Vorschau, die ein Chat oder ein soziales Netzwerk aus der Adresse des Spiels macht. Es gibt eine Vorschau: Sie nimmt den Text in der Sprache des Spiels (aus seinen Einstellungen) oder Englisch, wenn diese Sprache keinen hat; die anderen Sprachen werden nicht gelesen. Leeres Feld: der Name des Spiels.',
            'range' => 'Bis zu :limit Zeichen in jeder Sprache.',
        ],
        'share_title' => [
            'label' => 'Titel der Linkvorschau',
            'help' => 'Der Titel, den ein Chat oder ein soziales Netzwerk zeigt, wenn jemand die Adresse des Spiels einfügt. Es ist ein einziger Text, ohne Sprachen. Leeres Feld: der Name des Spiels.',
            'range' => 'Bis zu 70 Zeichen.',
        ],
        'image_upload' => [
            'label' => 'Bild',
            'help' => 'Das Bild des Blocks. Wähle eine Datei: Beim Speichern des Entwurfs wird sie im Bilderspeicher abgelegt und ihr Name in den Block geschrieben; ohne Auswahl bleibt das aktuelle Bild.',
            'range' => 'PNG, WebP oder JPEG, bis 1 MB, jede Seite von :min bis :max Pixel.',
            'tip' => 'Im Block Seitenkopf wird das Bild als Band von 220 px Höhe (340 px auf einem breiten Bildschirm) auf die volle Breite gezogen und zugeschnitten; halte Wichtiges also in der Mitte. Im Block Bild wird es ganz in voller Breite gezeigt.',
        ],
        'share_image_upload' => [
            'label' => 'Bild der Vorschau',
            'help' => 'Das Bild in der Linkvorschau, meist 1200 × 630 Pixel. Wähle eine Datei: Beim Speichern des Entwurfs wird sie im Bilderspeicher abgelegt; ohne Auswahl bleibt das aktuelle Bild.',
            'range' => 'PNG, WebP oder JPEG, bis 1 MB, jede Seite von :min bis :max Pixel.',
            'tip' => 'Ohne eigenes Bild nimmt die Vorschau das Symbol des Spiels; ein Spiel ohne Symbol hat kein Bild in der Vorschau.',
        ],
        'image_remove' => [
            'label' => 'Bild entfernen',
            'help' => 'Schalte es ein und speichere den Entwurf, damit der Block (oder die Linkvorschau) kein Bild mehr hat. Eine im Feld daneben gewählte Datei hat Vorrang. Es erscheint nur, wenn ein Bild gesetzt ist.',
            'tip' => 'Der Block Bild braucht ein Bild; Speichern ohne wird abgelehnt.',
        ],
    ],
];
