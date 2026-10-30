<?php

return [
    'entries' => [
        'branding' => [
            'label' => 'So funktioniert das Formular für Marke und Seite',
            'help' => 'Hier legst du das Aussehen der App dieses Spiels fest: Farben, Schriften, ein eigenes Stylesheet, die Blöcke der Startseite und die Linkvorschau. Ein leeres Feld ist das Aussehen der App; ein gespeicherter Entwurf kommt mit dem nächsten veröffentlichten Paket ins Spiel.',
            'steps' => [
                'Beginne mit den Farben: Ändere ein paar (Hintergrund, Akzent, Text) und lass den Rest leer. Farben werden als #rrggbb geschrieben.',
                'Wähle die Schriften für Überschriften, Text und Zahlen oder lass die Schrift der App.',
                'Füge die Blöcke der Startseite von oben nach unten hinzu (Seitenkopf, Karten, Text, Bild, Schaltflächen) und schreibe die Texte mindestens auf Englisch und Polnisch.',
                'Lege Titel, Beschreibung und Bild der Linkvorschau fest: Das sieht man, wenn jemand die Adresse des Spiels in einen Chat einfügt.',
                'Füge eigenes CSS zuletzt hinzu und nur, wenn die Farben nicht reichen; Regeln, die nicht bestehen, werden beim Speichern gemeldet.',
                'Speichere den Entwurf und veröffentliche ein Paket, damit die Spieler die Änderung sehen.',
            ],
            'example' => 'Ein Spiel über Schiffe: der Akzent #2fb7c9 statt Bernstein, die Schrift der Überschriften „System-Serif“, ein Block Seitenkopf mit dem Titel „Flotten auf See“ (auf Polnisch „Floty na morzu“) und eine Linkvorschau mit demselben Titel.',
        ],
        'vocabulary' => [
            'label' => 'So funktioniert der Wortschatz',
            'help' => 'Der Wortschatz ändert die Wörter der App für dieses Spiel (Namen der Slots, Menü, Texte der Bildschirme) in fünf Sprachen. Ein leeres Feld ist der Text der App; ein gespeicherter Entwurf kommt mit dem nächsten Paket ins Spiel.',
            'steps' => [
                'Wähle einen Bereich oder tippe ein Wort in Suchen und bestätige mit der Eingabetaste; höchstens 30 Meldungen werden auf einmal gezeigt.',
                'Unter dem Schlüssel einer Meldung steht der Text der App, und in einem leeren Feld einer Sprache ist er als Hinweis zu sehen.',
                'Schreibe den neuen Text in den Sprachen, die du hast; lass die Namen in geschweiften Klammern, wie {name}, unverändert.',
                'Fülle jede Sprache aus, die dir wichtig ist: Eine leere Sprache zeigt den englischen Text des Pakets, nicht den Text der App in dieser Sprache.',
                'Speichere, bevor du Bereich, Suche oder Filter wechselst: Nicht gespeicherte Änderungen gehen verloren. Geänderte Meldungen haben einen Punkt ● am Schlüssel.',
                'Mit den Schaltflächen oben auf der Seite kannst du ein ganzes Theme in eine Datei laden oder aus einer Datei einlesen; ein eingelesenes ersetzt im Entwurf alle Wörter des Pakets.',
            ],
            'example' => 'Ein Spiel über Schiffe: Ein Slot mit dem Schlüssel hull bekommt die Meldung slot.hull, auf Englisch „Hull“ und auf Polnisch „Kadłub“.',
        ],
        'rules_text' => [
            'label' => 'So funktioniert das Bearbeiten der Regeln als Text',
            'help' => 'Der fortgeschrittene Weg: das ganze Dokument der Regeln oder des Tutorials als JSON, für das, was die anderen Seiten nicht zeigen. Das Dokument wird wie auf den anderen Seiten geprüft und als Entwurf gespeichert, der mit dem nächsten Paket ins Spiel kommt.',
            'steps' => [
                'Wähle das Dokument: Spielregeln oder Tutorial.',
                'Ändere nur, was nötig ist, und behalte die Klammern, Kommas und Anführungszeichen bei. Die meisten Zahlen lassen sich im Parameter-Studio bequemer ändern.',
                'Speichere. Einen Syntaxfehler zeigt das Feld, einen Fehler im Inhalt eine rote Meldung mit Stelle und Grund; in beiden Fällen wird nichts gespeichert.',
                'Das Entfernen einer Stadt, einer Strecke oder eines Tutorialschritts, die Spieler nutzen, wird abgelehnt.',
                'Ein Wechsel des Dokuments im Feld Dokument verwirft den nicht gespeicherten Text.',
            ],
            'example' => 'Ändere in den Spielregeln „price_cents_per_liter“ in „fuel“ von 200 auf 250 (das sind Cent: 2,50 $ pro Liter), speichere und prüfe im Parameter-Studio, dass der Abschnitt Kraftstoff 2,50 $ zeigt.',
        ],
        'studio' => [
            'label' => 'So funktioniert das Parameter-Studio',
            'help' => 'Das Studio ändert die Zahlen des Spiels, einen Abschnitt auf einmal: Preise, Zeiten, Belohnungen, Verschleiß. Die Zahlen stehen in der Einheit, in der man denkt (%, s, $, l); ein gespeicherter Entwurf kommt mit dem nächsten Paket ins Spiel.',
            'steps' => [
                'Lass den einfachen Modus an (das Tempo und das Geld des Spiels); schalte ihn aus, wenn du jede Zahl brauchst.',
                'Wähle einen Abschnitt; der Satz darunter sagt, was eine Änderung seiner Zahlen im Spiel bewirkt.',
                'Stelle eine Zahl mit dem Schieberegler ein oder schalte Schieberegler aus und tippe den genauen Wert. Neben einer Zahl siehst du ihren Wert und, gelb, den Wert des Live-Pakets, wenn er anders ist; der Pfeil stellt den Live-Wert wieder her.',
                'Speichere, bevor du Abschnitt, Modus oder Schieberegler wechselst: Nicht gespeicherte Änderungen gehen verloren.',
                'Nutze die Balance-Vorschau, um zu sehen, wie der gespeicherte Entwurf das Tempo des Spiels gegenüber dem Live-Paket ändert.',
            ],
            'example' => 'Im Abschnitt Kraftstoff kostet ein Liter 2,00 $ (200 Cent im Dokument). Stelle 2,50 $ ein, speichere und sieh in der Balance-Vorschau nach, wie sich das Tempo des Spiels ändert.',
        ],
        'model' => [
            'label' => 'So funktioniert das Spielmodell',
            'help' => 'Das Modell sagt, woraus ein Auto besteht: die Slots, in die Teile kommen (mit der Rolle, die die Formeln kennen), und die Werte, die die Bildschirme zeigen. Ein gespeicherter Entwurf kommt mit dem nächsten Paket ins Spiel.',
            'steps' => [
                'Füge einen Slot hinzu: Gib ihm einen Schlüssel (Kleinbuchstaben, Ziffern, _) und wähle eine Rolle oder lass „Ohne Rolle“.',
                'Lege die Reihenfolge der Slots durch Ziehen fest: In dieser Reihenfolge listen Garage und Shop sie auf.',
                'Verstecke einen Slot, wenn die Garage ihn nicht auflisten soll.',
                'Bringe die Werte in die Reihenfolge der Bildschirme oder lass die weg, die das Spiel nicht zeigen soll.',
                'Schreibe den Namen eines neuen Slots im Wortschatz als slot.schlüssel (Englisch und Polnisch), damit das Spiel nicht den nackten Schlüssel zeigt.',
                'Speichere; eine Ablehnung sagt, was den Slot, den du loswerden willst, noch nennt.',
            ],
            'example' => 'Ein Spiel über Schiffe: ein Slot hull mit der Rolle Reifen (er bestimmt die Haftung), reactor mit der Rolle Motor und thrusters mit der Rolle Aufladung; die Namen „Rumpf“, „Reaktor“ und „Manövertriebwerke“ im Wortschatz als slot.hull, slot.reactor und slot.thrusters eingetragen.',
        ],
    ],
];
