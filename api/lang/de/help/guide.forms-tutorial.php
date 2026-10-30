<?php

return [
    'entries' => [
        'tutorial' => [
            'label' => 'So funktioniert das Tutorial',
            'help' => 'Das Tutorial führt einen neuen Fahrer mit Textnachrichten des Mentors (K.) durch die ersten Minuten, den ersten Tag und die erste Woche des Spiels. Änderungen werden zuerst als Entwurf gespeichert und kommen erst mit dem nächsten veröffentlichten Paket ins Spiel.',
            'steps' => [
                'Stelle oben „Die erste Fahrt“ ein: den Kraftstoff am Start, die Runde der ersten Panne, den längsten ersten Auftrag, den Betrag, den der Mentor deckt, und (optional) das Angebot an der Börse.',
                'Füge einen Schritt hinzu: Gib ihm einen festen Schlüssel, eine Phase und die Nummer des Mentors, und wähle dann, wann er beginnt und wann er endet (Fakten über den Fahrer, zum Beispiel lap_completed oder cash:450).',
                'Schreibe das Ziel für die Zielleiste, bis zu zwei Nachrichten des Mentors und die Worte der Antworttaste; ergänze bei Bedarf, worauf der Schritt wartet. Ein Schritt ohne Nachrichten wird ausgeführt, ohne gezeigt zu werden.',
                'Wähle, was der Bildschirm hervorhebt, und die Werkstattaufträge, die der Schritt verkürzt; stelle bei Bedarf ein Teil für den Start des Schritts und eine Belohnung für das Ende ein.',
                'Ändere keine Schlüssel und entferne keine Schritte, in denen Spieler sind: Die Veröffentlichung eines solchen Pakets wird abgelehnt.',
                'Speichere den Entwurf, sieh ihn dir auf der Seite „Änderungen zur Veröffentlichung“ an und fordere ein Paket auf der Seite „Veröffentlichungen“ an.',
            ],
            'example' => 'Schritt „exchange“ (Phase 3): Beginn cash:450 (er wartet, bis der Fahrer sich ein Auto leisten kann), Ende car_bought, er hebt die Börse hervor, und das Tutorial-Angebot (Rdzak GT-2, 25 Jahre, 280.000 km, Zustand 30 %, $450) wartet in seiner Stadt; nach dem Kauf gibt der Mentor $300 zurück.',
        ],
    ],
];
