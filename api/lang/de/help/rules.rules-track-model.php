<?php

return [
    'entries' => [
        '/tracks/*/speed_kmh' => [
            'label' => 'Tempo der Strecke (km/h)',
            'help' => 'Die Durchschnittsgeschwindigkeit des Referenzautos auf dieser Strecke, mit allem, was es bremst: den Kurven, dem Belag, den Steigungen und dem Verkehr. Die Referenzrunde ist die Länge der Strecke geteilt durch dieses Tempo, und Kraftstoff, Verdienst und XP einer Runde ergeben sich aus der Zeit dieser Runde.',
            'example' => 'Stadtstrecke Złomowo: 124,8 km/h (2600 m in 75 s), Rennstrecke Autodrom: 144 km/h (3200 m in 80 s), Steinbruch Kruszyn: 90,947 km/h (2400 m in 95 s).',
            'range' => 'Von 20 bis 300 km/h, höchstens vier Dezimalstellen (aus dem Schema).',
            'tip' => 'Je langsamer die Strecke (Schotter, kurvig, Gebirge), desto länger die Runde und desto mehr Kraftstoff pro Kilometer, weil der Motor länger arbeitet. Der Verdienst pro Stunde bleibt so, wie du ihn einstellst; eine Änderung des Tempos ändert also nicht, was eine Stunde einbringt, sondern nur, wie viele Kilometer das Auto in einer Stunde fährt (und damit Teileverschleiß und Wartung). Ein echtes Auto fährt die Strecke je nach Passung schneller oder langsamer.',
        ],
        '/tracks/*/pay_cents_per_hour' => [
            'label' => 'Verdienst pro Stunde ($)',
            'help' => 'Die Dollar, die eine Stunde Fahrt des Referenzautos auf dieser Strecke bei trockenem Wetter einbringt, bevor Stufe des Autos, Platz unter den Bots und Wetter sie verändern. Der Verdienst einer Runde ist diese Zahl mal die Zeit der Referenzrunde, eine längere Strecke zahlt pro Stunde also nicht weniger.',
            'example' => 'Stadtstrecke Złomowo: 744 $ pro Stunde (15,50 $ für eine Runde von 75 s), Schrottplatz-Sprint: 600 $, Rennstrecke Autodrom: 1.192,50 $.',
            'range' => 'Mindestens 0,01 $ (aus dem Schema).',
            'tip' => 'Das ist der wichtigste Hebel der Wirtschaft einer Strecke: Der Gewinn pro Stunde ist der Verdienst pro Stunde minus Kraftstoff pro Stunde. Die Anker der Wirtschaft nennen das Nettoeinkommen einer Stunde für die Stufe der Strecke; hier gibst du den Bruttowert ein.',
        ],
        '/tracks/*/xp_per_hour' => [
            'label' => 'XP pro Stunde',
            'help' => 'Die XP, die eine Stunde Fahrt auf dieser Strecke dem Fahrer bei trockenem Wetter gibt. Die XP einer Runde sind diese Zahl mal die Zeit der Referenzrunde, auf eine ganze Zahl gerundet. Ein schnelleres Auto bekommt proportional weniger pro Runde, in einer Stunde gibt also jedes Auto dasselbe.',
            'example' => 'Stadtstrecke Złomowo: 960 XP pro Stunde (20 XP pro Runde), Rennstrecke Autodrom: 900.',
            'range' => 'Von 0 bis 3600, höchstens zwei Dezimalstellen (aus dem Schema).',
            'tip' => 'Auf einer sehr kurzen Strecke verändert die Rundung auf ganze XP pro Runde das Ergebnis um zehn Prozent oder mehr: Der Prüfer warnt, wenn die Rundung mehr als ein Zehntel nimmt.',
        ],
        '/track_model/fuel_ml_per_s/*' => [
            'label' => 'Kraftstoff pro Sekunde einer Runde (Art der Strecke)',
            'help' => 'Wie viel Kraftstoff der Motor eines Autos mit normalem Durst in jeder Sekunde einer Runde verbrennt; der Name des Feldes sagt, für welche Art von Strecke: Sprint, Standard oder Langstrecke. Der Kraftstoff einer Runde des Referenzautos ist diese Zahl mal die Zeit der Runde, ein durstigeres Auto verbrennt mehr.',
            'example' => 'Sprint 12 ml (0,012 l) pro Sekunde, Standard 20 ml, Langstrecke 32 ml: Die Stadtstrecke Złomowo (75 s) verbrennt 1,5 l pro Runde.',
            'range' => 'Von 1 bis 1000 ml pro Sekunde (aus dem Schema).',
            'tip' => 'Wer eine dieser Zahlen ändert, verschiebt den Kraftstoff aller Strecken dieser Art auf einmal. Ein Tank hält Fassungsvermögen ÷ diese Zahl in Sekunden: 45 l bei 12 ml sind etwa eine Stunde, bei 32 ml etwa 23 Minuten.',
        ],
    ],
];
