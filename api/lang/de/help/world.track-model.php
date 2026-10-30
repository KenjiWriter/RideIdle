<?php

return [
    'entries' => [
        'speed_kmh' => [
            'label' => 'Tempo der Strecke',
            'help' => 'Die Durchschnittsgeschwindigkeit des Referenzautos auf dieser Strecke, mit allem, was es bremst: den Kurven, dem Belag, den Steigungen und dem Verkehr. Die Referenzrunde ist die Länge geteilt durch dieses Tempo; Kraftstoff, Verdienst und XP einer Runde ergeben sich aus ihrer Zeit. Ein echtes Auto fährt je nach Passung zur Strecke schneller oder langsamer.',
            'example' => 'Stadtstrecke Złomowo: 124,8 km/h (2600 m in 75 s); Rennstrecke Autodrom: 144 km/h; Steinbruch Kruszyn (Schotter): etwa 91 km/h.',
            'range' => 'Von 20 bis 300 km/h, höchstens vier Dezimalstellen.',
            'tip' => 'Du weißt nicht, was du eintragen sollst? Die Schaltfläche „Tempo vorschlagen“ rechnet es aus Kurven, Steigungen und Verkehr aus. Eine langsamere Strecke bedeutet eine längere Runde und mehr Kraftstoff pro Kilometer; der Verdienst pro Stunde bleibt so, wie du ihn einstellst.',
        ],
        'pay_per_hour' => [
            'label' => 'Verdienst pro Stunde ($)',
            'help' => 'Die Dollar, die eine Stunde Fahrt des Referenzautos auf dieser Strecke bei trockenem Wetter einbringt, bevor Stufe des Autos, Platz unter den Bots und Wetter sie verändern. Du gibst Dollar ein; das Spiel speichert Cent. Der Verdienst einer Runde ergibt sich aus dieser Zahl und der Zeit der Runde, eine längere Strecke zahlt pro Stunde also nicht weniger.',
            'example' => '744 $ (Stadtstrecke Złomowo: 15,50 $ für eine Runde von 75 s), 600 $ (Schrottplatz-Sprint), 1.192,50 $ (Rennstrecke Autodrom).',
            'range' => 'Mindestens 0,01 $.',
            'tip' => 'Der Gewinn pro Stunde ist der Verdienst pro Stunde minus Kraftstoff pro Stunde: Das vergleichen die Balanceprüfungen zwischen den Strecken, ein neuer Verdienst ändert also, welche Strecke auf einer Stufe die beste ist. Regen, Starkregen und Schnee zahlen 7 % weniger (derzeit).',
        ],
        'xp_per_hour' => [
            'label' => 'XP pro Stunde',
            'help' => 'Die Erfahrung, die eine Stunde Fahrt auf dieser Strecke bei trockenem Wetter gibt: Sie hebt die Stufe des Fahrers, die neue Städte, Strecken und Teile öffnet. Die XP einer Runde ergeben sich aus dieser Zahl und der Zeit der Runde, auf eine ganze Zahl gerundet. Ein schnelleres Auto bekommt proportional weniger pro Runde, in einer Stunde gibt also jedes Auto dieselben XP.',
            'example' => '960 XP (Stadtstrecke Złomowo, 20 XP pro Runde), 900 XP (Rennstrecke Autodrom und die Sprints).',
            'range' => 'Von 0 bis 3600, höchstens zwei Dezimalstellen.',
            'tip' => 'Auf einer sehr kurzen Strecke kann die Rundung der XP einer Runde auf ganze Zahlen das Ergebnis um bis zu ein Fünftel ändern; bei nassem Wetter sind es 7 % weniger.',
        ],
        'advise_corners' => [
            'label' => 'Kurven',
            'help' => 'Wie viele scharfe Kurven die Strecke je Reiseeinheit hat (in diesem Spiel: :unit). Jede nimmt dem Rechner etwas Tempo; wie viel, sagt das Studio („Tempoverlust pro Kurve“).',
            'example' => 'Autodrom: etwa 3,6 je Kilometer; Stadtstrecke Złomowo: 4,5; Bergserpentinen: 8 und mehr.',
            'range' => 'Von 0 aufwärts.',
        ],
        'advise_climb' => [
            'label' => 'Steigung',
            'help' => 'Wie viele Meter die Strecke je Reiseeinheit ansteigt (in diesem Spiel: :unit). Je 10 Meter nehmen dem Rechner etwas Tempo (Studio: „Tempoverlust je 10 m Steigung“).',
            'example' => 'Flache Strecke: 0; Bergstraße: 20–40 m je Kilometer.',
            'range' => 'Von 0 aufwärts.',
        ],
        'advise_traffic' => [
            'label' => 'Verkehr und Engstellen',
            'help' => 'Wie stark Verkehr, Überholen und Engstellen das Auto bremsen: von 0 (leere Strecke) bis 3 (eng und voll). Jede Stufe nimmt dem Rechner etwas Tempo.',
            'example' => 'Autodrom: 0; Stadtstraße: 2; Schrottplatz: 3.',
            'range' => '0, 1, 2 oder 3.',
            'options' => [
                0 => ['label' => 'Leere Strecke', 'help' => 'Nichts stört.'],
                1 => ['label' => 'Etwas Verkehr', 'help' => 'Ab und zu muss das Auto bremsen.'],
                2 => ['label' => 'Dichter Verkehr', 'help' => 'Das Auto bremst oft für andere.'],
                3 => ['label' => 'Eng und voll', 'help' => 'Engstellen und ständiger Verkehr.'],
            ],
        ],
    ],
];
