<?php

return [
    'entries' => [
        'vehicle' => [
            'label' => 'So funktioniert das Auto-Formular',
            'help' => 'Hier legst du ein neues Auto an oder änderst ein vorhandenes. Speichern erzeugt einen Entwurf: Er wird gegen das Schema geprüft und nur gespeichert, wenn er besteht, kommt aber erst mit dem nächsten Paket, das du veröffentlichst, zu den Spielern.',
            'example' => 'Rdzak GT-4: Kleinwagen, Frontantrieb, Verbrenner, R4 1,8 l mit Abstimmung 1,0, 97 kW, 1000 kg, 175 km/h, Bodenfreiheit 20, Zuladung 120 kg, 4 Sitze, Tank 45 l, Tier 2, Stufe 3, Preis 21.500 $, Quellen: Start-Wrack und Börse.',
            'steps' => [
                'Gib den Slug (feste Kennung, einmal gewählt) und den Namen ein; Englisch und Polnisch sind Pflicht.',
                'Wähle Klasse, Antrieb und Antriebsart; bei einem Verbrenner gib den Serienmotor (Bauart, Hubraum, Abstimmung) und den Tank an.',
                'Trage Leistung, Masse, Höchstgeschwindigkeit, Bodenfreiheit, Zuladung und Sitzplätze ein; Leistung und Masse verändern das Fahren am stärksten.',
                'Wähle das Tier: Die Spielerstufe muss 3 × Tier − 3 sein (mindestens 1). Setze den Preis in Dollar und hake die Quellen an: Start-Wrack, Börse, Händler.',
                'Speichere den Entwurf; Fehler aus dem Schema zeigt eine Benachrichtigung. Veröffentliche dann ein Paket: Es durchläuft die Schranken des Simulators, die die Balance bewachen.',
            ],
        ],
        'part' => [
            'label' => 'So funktioniert das Teile-Formular',
            'help' => 'Hier legst du ein neues Teil an oder änderst ein vorhandenes. Speichern erzeugt einen gegen das Schema geprüften Entwurf, der mit dem nächsten veröffentlichten Paket ins Spiel kommt; ins Shop-Angebot gelangt ein Teil erst, wenn es in den Regeln auf der Shop-Liste steht (Regeln als Text, shop.parts).',
            'example' => 'Straßenreifen T2: Slot Reifen, Art Straßenreifen, Tier 2, Preis 570 $, Haftung 100, Fahrverhalten 98, Bremsen 100. Turbo Ósemka T2: Slot Aufladung, Tier 2, Preis 4.200 $, Leistung 1,25, Verzögerung 3, Verbrauch 1,15, Last 8, mindestens 4 Zylinder.',
            'steps' => [
                'Gib Slug und Namen in den Sprachen ein; Englisch und Polnisch sind Pflicht.',
                'Wähle den Slot: Seine Rolle sagt, was das Teil tut. Bei Reifen wähle die Art, bei einem Motor Bauart und Hubraum.',
                'Wähle das Tier und gib den Preis in Dollar an; die Preise der meisten Teile werden mit der Kurve der Wirtschaft verglichen (±20 %).',
                'Füge Statistiken als Zeilen aus „Name und Zahl“ hinzu (zum Beispiel Haftung 100, Leistung 1,25); Faktoren haben 1 als „keine Änderung“.',
                'Klappe bei Bedarf „Was es braucht und wozu es passt“ auf: Mindestzylinder, Aufladung, Antriebsart, Klassen.',
                'Speichere den Entwurf und veröffentliche ein Paket; die Wirkung des Teils kannst du vorher auf der Seite Teile-Vorschau berechnen.',
            ],
        ],
    ],
];
