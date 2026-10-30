<?php

return [
    'entries' => [
        'slots' => [
            'label' => 'Slots',
            'help' => 'Die Plätze im Auto, in die Teile kommen, in der Reihenfolge, in der Garage und Shop sie auflisten. Von 1 bis 24 Slots; ziehe eine Zeile, um die Reihenfolge zu ändern.',
            'range' => 'Von 1 bis 24 Slots.',
            'tip' => 'Ein Slot, den noch ein Teil oder eine Regel nennt, lässt sich nicht entfernen: Das Speichern wird mit einer Liste der Stellen abgelehnt, die ihn nutzen. Auch eine Veröffentlichung nimmt keinen Slot heraus, in dem Spieler Teile haben; den Schlüssel eines genutzten Slots zu ändern ist deshalb blockiert.',
        ],
        'key' => [
            'label' => 'Slot-Schlüssel',
            'help' => 'Der Name, den der Slot in Teilen, Regeln und den Autos der Spieler trägt. Den im Spiel gezeigten Namen (zum Beispiel „Reifen“) liefert der Wortschatz als slot.schlüssel; für einen neuen Schlüssel musst du ihn dort eintragen (Englisch und Polnisch).',
            'example' => 'tires, suspension, brakes, engine, boost, gearbox, exhaust, cooling, tank, ecu (das Modell des Autospiels).',
            'range' => 'Bis zu 32 Zeichen: Kleinbuchstaben, Ziffern und _, beginnt mit einem Buchstaben, keine Wiederholungen.',
        ],
        'role' => [
            'label' => 'Rolle des Slots',
            'help' => 'Sagt den Formeln des Spiels, was dieser Slot in diesem Spiel ist, egal wie sein Schlüssel lautet: Ein Slot mit der Rolle „Reifen“ bestimmt die Haftung, auch wenn er „hull“ heißt. Eine Rolle gehört zu einem Slot, und das Spiel braucht einen Slot mit der Rolle Reifen. Ein Slot ohne Rolle enthält Teile, deren Werte die Formeln schon lesen (wie beim Auspuff).',
            'tip' => 'Teile in einem Slot mit der Rolle Reifen brauchen eine Reifenart, Teile in einem Slot mit der Rolle Motor einen Motor (Bauart und Hubraum); in anderen Slots haben Teile diese Felder nicht.',
            'options' => [
                'tires' => ['label' => 'Reifen', 'help' => 'Haftung, Fahrverhalten und Bremsen beginnen hier, und die Reifenart wählt die Spalte der Untergrundmatrix. Pflichtrolle.'],
                'suspension' => ['label' => 'Fahrwerk', 'help' => 'Fahrverhalten und Bodenfreiheit (Geländetauglichkeit) des Autos.'],
                'brakes' => ['label' => 'Bremsen', 'help' => 'Das Bremsen des Autos.'],
                'engine' => ['label' => 'Motor', 'help' => 'Leistung und Masse des Autos; ein Teil in diesem Slot ersetzt den Werksmotor.'],
                'boost' => ['label' => 'Aufladung', 'help' => 'Der Leistungszuwachs, die Verzögerung und die Last auf dem Motor.'],
                'gearbox' => ['label' => 'Getriebe', 'help' => 'Die Grenze der Höchstgeschwindigkeit und das Anfahren.'],
                'cooling' => ['label' => 'Kühlung', 'help' => 'Ladeluftkühler und Kühler: davon hängt ab, wie viel die Aufladung verliert.'],
                'tank' => ['label' => 'Tank', 'help' => 'Das Fassungsvermögen des Tanks, also wie weit das Auto kommt.'],
            ],
        ],
        'hidden' => [
            'label' => 'In der Garage versteckt',
            'help' => 'Ein versteckter Slot kann weiter Teile halten und hat seinen Platz in den Regeln, aber die Garage listet ihn nicht auf (im Autospiel ist das der Slot „ecu“). Ein versteckter Slot braucht keinen Namen im Wortschatz.',
        ],
        'stats' => [
            'label' => 'Werte',
            'help' => 'Die Werte eines Autos, die die Bildschirme von Garage und Werkstatt zeigen, in der Reihenfolge der Liste. Ein ausgelassener Wert wird nicht gezeigt, und einen neuen kann man nicht hinzufügen, weil die Formeln die Werte erzeugen.',
            'tip' => 'Die Liste ändert nur, was man sieht, und die Gesamtwertung des Autos (der Durchschnitt der Balken). Keine Rennformel liest sie; einen Wert wegzulassen ändert also nichts am Fahren.',
        ],
        'stat' => [
            'label' => 'Wert',
            'help' => 'Welchen Wert diese Stelle der Liste zeigt. Jeder kann nur einmal vorkommen.',
            'options' => [
                'accel' => ['label' => 'Beschleunigung', 'help' => 'Die Leistung des Autos pro Tonne: je größer, desto schneller fährt das Auto an und gewinnt Tempo.'],
                'top_speed' => ['label' => 'Höchsttempo', 'help' => 'Wächst mit der Leistung (mit der dritten Wurzel des Leistungszuwachses) und übersteigt nie die Grenze des Getriebes.'],
                'grip' => ['label' => 'Haftung', 'help' => 'Kommt von den Reifen: wie fest das Auto auf der Straße liegt.'],
                'handling' => ['label' => 'Fahrverhalten', 'help' => 'Aus Reifen und Fahrwerk, mit dem Massefaktor multipliziert: ein schweres Auto lenkt schlechter.'],
                'braking' => ['label' => 'Bremsen', 'help' => 'Aus Reifen und Bremsen, mit dem Massefaktor multipliziert: ein schweres Auto bremst schlechter.'],
                'clearance' => ['label' => 'Gelände', 'help' => 'Bodenfreiheit: beginnt bei der Karosserie und wird mit dem Fahrwerk gemittelt.'],
            ],
        ],
    ],
];
