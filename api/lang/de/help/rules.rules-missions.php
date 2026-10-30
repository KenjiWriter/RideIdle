<?php

return [
    'entries' => [
        '/missions/reset_hour' => [
            'label' => 'Stunde, zu der der Missionstag beginnt',
            'help' => 'Die Stunde (in der Zeitzone des Spiels), zu der der Missionstag und mit ihm das neue Tages-Set beginnt. Die Woche beginnt montags zu dieser Stunde und der Monat am Ersten zu dieser Stunde. Dann endet auch der Zeitraum davor: Was ein Spieler geschafft, aber nicht abgeholt hat, holt der Server für ihn ab, der Rest verfällt.',
            'example' => '4: Wer abends und nach Mitternacht spielt, hat einen Missionstag und bekommt das neue Set um 4:00 Uhr morgens.',
            'range' => '0 – 23 (die Stunde).',
        ],
        '/missions/periods/daily/bonus_coins' => [
            'label' => 'Bonus für das ganze Set: Tag (Münzen)',
            'help' => 'Wie viele Premium-Münzen (RideCoin) ein Spieler dafür bekommt, alle Missionen des Tages-Sets zu schaffen. Es sind die einzigen kostenlosen Münzen der Missionen; die Belohnungen einzelner Missionen enthalten keine. Diese Zahl bestimmt also alle kostenlosen Münzen dieses Zeitraums, und das Schema erlaubt nicht mehr als 5.',
            'example' => '2 Münzen: Wer alle drei Zeiträume jedes Mal schafft, erhält 2, 8 und 25 Münzen, etwa 119 im Monat; das VIP-Konto kostet 150 Münzen und ein Paket mit 50 Münzen 4,99 zł.',
            'range' => '0 – 5 Münzen.',
            'tip' => 'Spieler empfinden einen niedrigeren Bonus als weggenommene Belohnung, also fang niedrig an: Ihn später zu erhöhen ist leicht.',
        ],
        '/missions/periods/weekly/bonus_coins' => [
            'label' => 'Bonus für das ganze Set: Woche (Münzen)',
            'help' => 'Wie viele Premium-Münzen (RideCoin) ein Spieler dafür bekommt, alle Missionen des Wochen-Sets zu schaffen. Es sind die einzigen kostenlosen Münzen der Missionen; die Belohnungen einzelner Missionen enthalten keine. Diese Zahl bestimmt also alle kostenlosen Münzen dieses Zeitraums, und das Schema erlaubt nicht mehr als 20.',
            'example' => '8 Münzen: Wer alle drei Zeiträume jedes Mal schafft, erhält 2, 8 und 25 Münzen, etwa 119 im Monat; das VIP-Konto kostet 150 Münzen und ein Paket mit 50 Münzen 4,99 zł.',
            'range' => '0 – 20 Münzen.',
            'tip' => 'Spieler empfinden einen niedrigeren Bonus als weggenommene Belohnung, also fang niedrig an: Ihn später zu erhöhen ist leicht.',
        ],
        '/missions/periods/monthly/bonus_coins' => [
            'label' => 'Bonus für das ganze Set: Monat (Münzen)',
            'help' => 'Wie viele Premium-Münzen (RideCoin) ein Spieler dafür bekommt, alle Missionen des Monats-Sets zu schaffen. Es sind die einzigen kostenlosen Münzen der Missionen; die Belohnungen einzelner Missionen enthalten keine. Diese Zahl bestimmt also alle kostenlosen Münzen dieses Zeitraums, und das Schema erlaubt nicht mehr als 60.',
            'example' => '25 Münzen: Wer alle drei Zeiträume jedes Mal schafft, erhält 2, 8 und 25 Münzen, etwa 119 im Monat; das VIP-Konto kostet 150 Münzen und ein Paket mit 50 Münzen 4,99 zł.',
            'range' => '0 – 60 Münzen.',
            'tip' => 'Spieler empfinden einen niedrigeren Bonus als weggenommene Belohnung, also fang niedrig an: Ihn später zu erhöhen ist leicht.',
        ],
        '/missions/periods/daily/cash_ppm' => [
            'label' => 'Geld für das Set: Tag (% eines Tanks)',
            'help' => 'Wie viel Geld alle Missionen des Tages-Sets zusammen zahlen, als Teil dessen, was das beste Auto des Spielers mit einem vollen Tank nach Abzug des Kraftstoffs verdient (100 % ist ein ganzer Tank). Das Set teilt den Betrag nach ihren Anteilen auf seine Missionen auf, es zahlt also gleich viel, welche Missionen auch gezogen werden. Das kommt zum Verdienst der Autos hinzu und zählt nicht in der Spielwirtschaft: Die Schranken und der Simulator sehen es nicht.',
            'example' => '6 %: Ein Spieler, dessen Tank 1.000 $ verdient, bekommt für das ganze Set etwa 60 $.',
            'range' => '0 – 1000 %.',
        ],
        '/missions/periods/weekly/cash_ppm' => [
            'label' => 'Geld für das Set: Woche (% eines Tanks)',
            'help' => 'Wie viel Geld alle Missionen des Wochen-Sets zusammen zahlen, als Teil dessen, was das beste Auto des Spielers mit einem vollen Tank nach Abzug des Kraftstoffs verdient (100 % ist ein ganzer Tank). Das Set teilt den Betrag nach ihren Anteilen auf seine Missionen auf, es zahlt also gleich viel, welche Missionen auch gezogen werden. Das kommt zum Verdienst der Autos hinzu und zählt nicht in der Spielwirtschaft: Die Schranken und der Simulator sehen es nicht.',
            'example' => '20 %: Ein Spieler, dessen Tank 1.000 $ verdient, bekommt für das ganze Set etwa 200 $.',
            'range' => '0 – 1000 %.',
        ],
        '/missions/periods/monthly/cash_ppm' => [
            'label' => 'Geld für das Set: Monat (% eines Tanks)',
            'help' => 'Wie viel Geld alle Missionen des Monats-Sets zusammen zahlen, als Teil dessen, was das beste Auto des Spielers mit einem vollen Tank nach Abzug des Kraftstoffs verdient (100 % ist ein ganzer Tank). Das Set teilt den Betrag nach ihren Anteilen auf seine Missionen auf, es zahlt also gleich viel, welche Missionen auch gezogen werden. Das kommt zum Verdienst der Autos hinzu und zählt nicht in der Spielwirtschaft: Die Schranken und der Simulator sehen es nicht.',
            'example' => '60 %: Ein Spieler, dessen Tank 1.000 $ verdient, bekommt für das ganze Set etwa 600 $.',
            'range' => '0 – 1000 %.',
        ],
        '/missions/periods/daily/xp_ppm' => [
            'label' => 'XP für das Set: Tag (% eines Tanks)',
            'help' => 'Wie viel XP des Spielers (die Stufe des Fahrers) alle Missionen des Tages-Sets zusammen zahlen, als Teil der XP, die das beste Auto des Spielers mit einem vollen Tank sammelt. Die Stufe des Spielers öffnet Strecken, Städte und Teile, und das Tutorial sagt, dass die Stufen die Arbeit der Runden sein sollen, also halte diese Zahl klein: Jeder Punkt verschiebt das Tempo des ganzen Spiels.',
            'example' => '5 %: Ein Spieler, dessen Tank 1.000 XP bringt, bekommt für das ganze Set etwa 50 XP.',
            'range' => '0 – 1000 %.',
        ],
        '/missions/periods/weekly/xp_ppm' => [
            'label' => 'XP für das Set: Woche (% eines Tanks)',
            'help' => 'Wie viel XP des Spielers (die Stufe des Fahrers) alle Missionen des Wochen-Sets zusammen zahlen, als Teil der XP, die das beste Auto des Spielers mit einem vollen Tank sammelt. Die Stufe des Spielers öffnet Strecken, Städte und Teile, und das Tutorial sagt, dass die Stufen die Arbeit der Runden sein sollen, also halte diese Zahl klein: Jeder Punkt verschiebt das Tempo des ganzen Spiels.',
            'example' => '15 %: Ein Spieler, dessen Tank 1.000 XP bringt, bekommt für das ganze Set etwa 150 XP.',
            'range' => '0 – 1000 %.',
        ],
        '/missions/periods/monthly/xp_ppm' => [
            'label' => 'XP für das Set: Monat (% eines Tanks)',
            'help' => 'Wie viel XP des Spielers (die Stufe des Fahrers) alle Missionen des Monats-Sets zusammen zahlen, als Teil der XP, die das beste Auto des Spielers mit einem vollen Tank sammelt. Die Stufe des Spielers öffnet Strecken, Städte und Teile, und das Tutorial sagt, dass die Stufen die Arbeit der Runden sein sollen, also halte diese Zahl klein: Jeder Punkt verschiebt das Tempo des ganzen Spiels.',
            'example' => '40 %: Ein Spieler, dessen Tank 1.000 XP bringt, bekommt für das ganze Set etwa 400 XP.',
            'range' => '0 – 1000 %.',
        ],
    ],
];
