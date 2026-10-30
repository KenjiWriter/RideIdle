<?php

return [
    'entries' => [
        'simple' => [
            'label' => 'Einfacher Modus',
            'help' => 'An: nur die Zahlen, die das Tempo und das Geld des Spiels bestimmen (Preise, Belohnung für eine Runde, Zeiten der Werkstatt, Level), ein kleiner Teil der etwa 750. Aus: alle Zahlen des Abschnitts, auch die, die selten angefasst werden.',
            'tip' => 'Der einfache Modus ist beim Öffnen der Seite an. Beim Umschalten wird das Formular aus dem gespeicherten Entwurf neu aufgebaut; speichere deine Änderungen also vor dem Umschalten.',
        ],
        'section' => [
            'label' => 'Abschnitt',
            'help' => 'Die Zahlen des Spiels sind in Abschnitte geteilt; einer wird auf einmal gezeigt, mit der Zahl seiner Zahlen in Klammern (im einfachen Modus nur die einfachen) und einem Satz dazu, was eine Änderung seiner Zahlen im Spiel bewirkt. Speichere, bevor du den Abschnitt wechselst: Nicht Gespeichertes geht verloren.',
            'options' => [
                'fuel' => ['label' => 'Kraftstoff', 'help' => 'Der Preis eines Liters Kraftstoff; die Kosten jeder Runde hängen davon ab.'],
                'repair' => ['label' => 'Reparatur am Straßenrand', 'help' => 'Der Preis der Reparatur nach einer Panne auf der Strecke.'],
                'breakdown' => ['label' => 'Pannen', 'help' => 'Die Wahrscheinlichkeit einer Panne in einer Runde und wie stark Verschleiß sie erhöht.'],
                'tow' => ['label' => 'Abschleppen', 'help' => 'Wie lange das Abschleppen nach einer Panne dauert und der Kraftstoff, der für den Weg bleibt.'],
                'car' => ['label' => 'Auto: XP, Level, Masse', 'help' => 'Wie schnell Autos Level gewinnen, wie die Masse das Fahrverhalten beeinflusst und was Autos höherer Klassen einbringen.'],
                'engines' => ['label' => 'Motoren', 'help' => 'Leistung, Masse und Anforderungen der Motoren.'],
                'drivetrain' => ['label' => 'Antrieb', 'help' => 'Wie Front-, Heck- und Allradantrieb den Untergrund halten, und ihre Leistungsgrenzen.'],
                'garage' => ['label' => 'Garagen', 'help' => 'Wie viele Autos ein Spieler in einer Stadt hält und was der Start kostet; die Preise von Garagen und Wracks vom Hof.'],
                'market' => ['label' => 'Markt: Börse, Händler, Lager, Kisten', 'help' => 'Die Preise gebrauchter Autos, der Wiederverkauf, die Chancen in Lagerboxen und wie oft die Börse sich erneuert.'],
                'clock' => ['label' => 'Spieluhr', 'help' => 'Um wie viel langsamer nachts auf Strecken ohne Licht gefahren wird.'],
                'player' => ['label' => 'Spieler: XP und Level', 'help' => 'Wie schnell der Spieler Level gewinnt, die Städte, Strecken und Teile öffnen.'],
                'start' => ['label' => 'Start', 'help' => 'Der Vorsprung eines neuen Spielers in der ersten Runde.'],
                'bots' => ['label' => 'Bots', 'help' => 'Die Zeiten der Rivalen im Vergleich zur Referenzstrecke und die Multiplikatoren der Belohnung nach Platz.'],
                'shop' => ['label' => 'Shop', 'help' => 'Kisten und Seltenheit der Teile: Chancen, Stärke und Preise.'],
                'affixes' => ['label' => 'Affixe', 'help' => 'Stärke, Chancen und Effekte der Affixe von Teilen, auch derer, die vom Untergrund abhängen.'],
                'wear' => ['label' => 'Verschleiß und Service', 'help' => 'Die Lebensdauer der Teile in Kilometern, die Kosten von Reparatur und Service.'],
                'premium' => ['label' => 'Premiumwährung', 'help' => 'Die Preise der RideCoin-Pakete, das Beschleunigen mit Münzen, der Preis des Anwalts, das automatische Tanken (Ladungspakete), das VIP-Konto und der Pass für unbegrenztes Tanken.'],
                'workshop' => ['label' => 'Werkstatt', 'help' => 'Die Zeiten von Einbau, Aufwerten und Verschmelzen, die Preise von Plätzen und Mechanikern, die Länge der Warteschlange.'],
                'tire_matrix' => ['label' => 'Reifenmatrix', 'help' => 'Die Haftung jeder Reifenart auf jedem Untergrund.'],
                'weather' => ['label' => 'Wetter', 'help' => 'Wie das Wetter eine Runde beeinflusst.'],
                'regions' => ['label' => 'Städte (Zahlen)', 'help' => 'Die Lage der Städte auf der Karte und ihre Anforderungen.'],
                'roads' => ['label' => 'Straßen', 'help' => 'Die Entfernungen zwischen den Städten in Kilometern und damit Zeit und Kosten einer Reise.'],
                'travel' => ['label' => 'Reisen und Beschleunigung', 'help' => 'Tempo und Kraftstoff einer Reise sowie die beschleunigte Fahrt mit Polizei und Bußgeldern.'],
                'tracks' => ['label' => 'Strecken: Zeiten, Belohnungen, Gewichte', 'help' => 'Länge, Rundenzeit, Kraftstoff, Belohnung und XP einer Strecke sowie die Gewichte ihrer Werte; ändert direkt den Verdienst pro Stunde.'],
            ],
        ],
        'sliders' => [
            'label' => 'Schieberegler',
            'help' => 'An: Eine Zahl, deren Grenzen das Schema angibt, hat einen Schieberegler mit dem Wert als Tooltip; die anderen sind immer Felder. Aus: Alle Zahlen sind Felder, in die du den genauen Wert tippst.',
            'tip' => 'Ein Schieberegler bewegt sich in Schritten der Zahl und taugt nicht für sehr weite Bereiche (über 2000 Schritte), dort gibt es ein Feld. Beim Umschalten wird das Formular aus dem gespeicherten Entwurf neu aufgebaut; speichere deine Änderungen also vor dem Umschalten.',
        ],
    ],
];
