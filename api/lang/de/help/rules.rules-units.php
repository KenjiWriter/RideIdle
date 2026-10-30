<?php

return [
    'entries' => [
        '/units/lap/metres' => [
            'label' => 'Einheit der Runde: wie viele Meter',
            'help' => 'Wie viele Meter eine Einheit hat, in der dieses Spiel die Länge der Runde einer Strecke zählt: 1 für Meter, 1852 für die Seemeile, 1609,344 für die Landmeile. Die Zahlen der Regeln und der Datenbank bleiben in Metern; die Einheit sagt, in was die Länge einer Strecke geschrieben und gezeigt wird.',
            'example' => 'Das Autospiel: 1 (ein Meter). Ein Schiffsspiel: 1852 (eine Seemeile).',
            'range' => 'Eine Zahl über 0 (aus dem Schema).',
            'tip' => 'Eine Änderung verschiebt weder Zeit noch Kraftstoff: Sie ändert nur, was der Spieler sieht und was der Betreiber schreibt.',
        ],
        '/units/travel/metres' => [
            'label' => 'Einheit der Reise: wie viele Meter',
            'help' => 'Wie viele Meter eine Einheit hat, in der dieses Spiel die Straßen zwischen den Städten, den Kilometerstand eines Autos, das Wartungsintervall, die Lebensdauer der Teile und den Kilometerstand der Autos an der Börse zählt: 1000 für Kilometer, 1852 für die Seemeile, 1609,344 für die Landmeile. Die Zahlen der Regeln und der Datenbank bleiben in Kilometern; die Einheit sagt, worin sie gezeigt und geschrieben werden.',
            'example' => 'Das Autospiel: 1000 (ein Kilometer). Ein Schiffsspiel: 1852 (eine Seemeile).',
            'range' => 'Eine Zahl über 0 (aus dem Schema).',
            'tip' => 'Eine Straße der Regeln zählt in ganzen Kilometern, 20 Seemeilen sind im Dokument also 37 km (37,04 gerundet) und 19,98 Meilen auf dem Bildschirm.',
        ],
        '/units/speed/kmh' => [
            'label' => 'Einheit des Tempos: wie viele km/h',
            'help' => 'Wie viele km/h eine Einheit des Tempos in diesem Spiel sind: 1 für km/h, 1,852 für den Knoten, 1,609344 für Meilen pro Stunde. Die Geschwindigkeiten in den Regeln und in den Paketen bleiben in km/h; die Einheit sagt, worin sie gezeigt und geschrieben werden.',
            'example' => 'Das Autospiel: 1 (km/h). Ein Schiffsspiel: 1,852 (ein Knoten).',
            'range' => 'Eine Zahl über 0 (aus dem Schema).',
        ],
        '/units/lap/decimals' => [
            'label' => 'Runde: Dezimalstellen',
            'help' => 'Wie viele Dezimalstellen die Zahl hat, wenn der Spieler sie in dieser Einheit sieht (0 ist eine ganze Zahl). Das ändert nur die Anzeige; die Felder der Formulare haben immer so viele, wie nötig sind, damit das Geschriebene auf denselben Meter zurückkommt.',
            'example' => 'Eine Runde in Seemeilen: 2 Dezimalstellen (1,4 sm); eine Straße in Seemeilen: 0 (20 sm).',
            'range' => 'Von 0 bis 4 (aus dem Schema).',
        ],
        '/units/travel/decimals' => [
            'label' => 'Reise: Dezimalstellen',
            'help' => 'Wie viele Dezimalstellen die Zahl hat, wenn der Spieler sie in dieser Einheit sieht (0 ist eine ganze Zahl). Das ändert nur die Anzeige; die Felder der Formulare haben immer so viele, wie nötig sind, damit das Geschriebene auf denselben Meter zurückkommt.',
            'example' => 'Eine Runde in Seemeilen: 2 Dezimalstellen (1,4 sm); eine Straße in Seemeilen: 0 (20 sm).',
            'range' => 'Von 0 bis 4 (aus dem Schema).',
        ],
        '/units/speed/decimals' => [
            'label' => 'Tempo: Dezimalstellen',
            'help' => 'Wie viele Dezimalstellen die Zahl hat, wenn der Spieler sie in dieser Einheit sieht (0 ist eine ganze Zahl). Das ändert nur die Anzeige; die Felder der Formulare haben immer so viele, wie nötig sind, damit das Geschriebene auf denselben Meter zurückkommt.',
            'example' => 'Eine Runde in Seemeilen: 2 Dezimalstellen (1,4 sm); eine Straße in Seemeilen: 0 (20 sm).',
            'range' => 'Von 0 bis 4 (aus dem Schema).',
        ],
        '/units/fuel_economy/per' => [
            'label' => 'Verbrauch pro wie viele Reiseeinheiten',
            'help' => 'Pro wie viele Reiseeinheiten der Spieler den Kraftstoffverbrauch sieht: 100 ergibt „l/100 km“, 1 ergibt „l pro Seemeile“. Das ist nur die Anzeige; die Kraftstoffzahlen des Spiels ändern sich nicht.',
            'example' => '100 im Autospiel, 1 in einem Schiffsspiel.',
            'range' => 'Eine von 1, 10, 100 (aus dem Schema).',
        ],
        '/track_model/advisor/free_speed_kmh/asphalt' => [
            'label' => 'Freies Tempo: Asphalt (km/h)',
            'help' => 'Das Tempo, von dem der Schwierigkeitsrechner im Streckenformular für den Belag „Asphalt“ ausgeht: das des Referenzautos auf einer geraden, leeren Strecke. Das Spiel liest diese Zahl nicht: nur die Schaltfläche „Tempo vorschlagen“ tut es.',
            'example' => 'Asphalt 180 km/h.',
            'range' => 'Von 20 bis 300 km/h (aus dem Schema).',
            'tip' => 'Eine Änderung verschiebt keine bestehende Strecke; sie ändert nur, was der Rechner beim nächsten Mal vorschlägt.',
        ],
        '/track_model/advisor/free_speed_kmh/gravel' => [
            'label' => 'Freies Tempo: Schotter (km/h)',
            'help' => 'Das Tempo, von dem der Schwierigkeitsrechner im Streckenformular für den Belag „Schotter“ ausgeht: das des Referenzautos auf einer geraden, leeren Strecke. Das Spiel liest diese Zahl nicht: nur die Schaltfläche „Tempo vorschlagen“ tut es.',
            'example' => 'Schotter 130 km/h.',
            'range' => 'Von 20 bis 300 km/h (aus dem Schema).',
            'tip' => 'Eine Änderung verschiebt keine bestehende Strecke; sie ändert nur, was der Rechner beim nächsten Mal vorschlägt.',
        ],
        '/track_model/advisor/corner_loss_kmh' => [
            'label' => 'Tempoverlust pro Kurve je Kilometer (km/h)',
            'help' => 'Wie viele km/h der Schwierigkeitsrechner für jede scharfe Kurve je Kilometer der Strecke abzieht. Das Spiel liest diese Zahl nicht: nur die Schaltfläche „Tempo vorschlagen“ tut es.',
            'example' => '10 km/h: Eine Strecke mit 4 Kurven je Kilometer verliert 40 km/h.',
            'range' => 'Von 0 bis 100 km/h (aus dem Schema).',
        ],
        '/track_model/advisor/climb_loss_kmh' => [
            'label' => 'Tempoverlust je 10 m Steigung pro Kilometer (km/h)',
            'help' => 'Wie viele km/h der Schwierigkeitsrechner für jede 10 Meter Steigung je Kilometer der Strecke abzieht. Das Spiel liest diese Zahl nicht: nur die Schaltfläche „Tempo vorschlagen“ tut es.',
            'example' => '5 km/h: Eine Strecke, die 20 m je Kilometer ansteigt, verliert 10 km/h.',
            'range' => 'Von 0 bis 100 km/h (aus dem Schema).',
        ],
        '/track_model/advisor/traffic_loss_kmh' => [
            'label' => 'Tempoverlust je Verkehrsstufe (km/h)',
            'help' => 'Wie viele km/h der Schwierigkeitsrechner für jede Stufe von Verkehr und Engstellen abzieht (0 ist eine leere Strecke, 3 ist eng und voll). Das Spiel liest diese Zahl nicht: nur die Schaltfläche „Tempo vorschlagen“ tut es.',
            'example' => '5 km/h: Verkehr der Stufe 2 zieht 10 km/h ab.',
            'range' => 'Von 0 bis 100 km/h (aus dem Schema).',
        ],
    ],
];
