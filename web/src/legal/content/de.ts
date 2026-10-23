import type { LegalTexts } from '../types'

const texts: LegalTexts = {
  terms: {
    title: 'Nutzungsbedingungen',
    intro:
      'Diese Bedingungen gelten für RideIdle, ein Idle-Rennspiel, das in deinem Browser läuft. Lies sie zusammen mit der Datenschutzerklärung. Der Betreiber des Spiels steht oben auf dieser Seite.',
    sections: [
      {
        id: 'scope',
        heading: 'Für wen diese Bedingungen gelten',
        paragraphs: [
          'Wenn du einen Fahrer anlegst, dich anmeldest oder RideIdle auf andere Weise nutzt, stimmst du diesen Bedingungen zu. Wenn du nicht einverstanden bist, nutze das Spiel bitte nicht.',
          'Wenn ein Teil dieser Bedingungen wegen zwingenden Rechts an deinem Wohnort nicht für dich gelten kann, gilt der Rest trotzdem.',
        ],
      },
      {
        id: 'age',
        heading: 'Mindestalter',
        paragraphs: [
          'Du musst mindestens {minAge} Jahre alt sein, um RideIdle zu nutzen. Wenn du dein Konto sicherst, bitten wir dich, dein Alter zu bestätigen, und wir speichern den Zeitpunkt dieser Bestätigung.',
          'Wenn wir erfahren, dass eine jüngere Person ein Konto hat, können wir es löschen.',
        ],
      },
      {
        id: 'account',
        heading: 'Dein Fahrer und dein Konto',
        paragraphs: [
          'Du startest als Gast: Du wählst einen Fahrernamen, und das Spiel legt ein Gastkonto an, ohne nach persönlichen Daten zu fragen. Ein Gastkonto wird mit allem, was darin ist, nach {guestDays} Tagen ohne Aktivität gelöscht.',
          'Du kannst dein Konto mit einer E-Mail-Adresse, Google, Facebook oder Apple sichern. Ein gesichertes Konto läuft nicht ab und lässt sich auf einem anderen Gerät öffnen. Du kannst auch einen Wiederherstellungscode behalten. Wer deinen Wiederherstellungscode oder einen Anmeldelink hat, kann in dein Konto gelangen. Behalte sie deshalb für dich.',
          'Wähle einen Fahrernamen, der nicht beleidigend ist und sich nicht als jemand anderes ausgibt. Einen Namen, der gegen diese Regel verstößt, können wir ändern oder entfernen.',
          'Du bist für alles verantwortlich, was unter deinem Konto geschieht. Mache beim Sichern wahrheitsgemäße Angaben.',
        ],
      },
      {
        id: 'fair-play',
        heading: 'Faires Spiel',
        paragraphs: [
          'Der Server entscheidet jedes Ergebnis im Spiel. Spiele das Spiel so, wie es gedacht ist.',
          'Betrüge nicht, nutze keine Bots oder Skripte, die für dich spielen, nutze Fehler im Spiel nicht absichtlich aus, versuche nicht, in fremde Konten zu gelangen, und störe den Dienst für andere nicht. Wenn du einen Fehler findest, melde ihn uns, statt ihn zu nutzen.',
          'Wenn ein Fehler oder unfaires Spielen jemandem Geld, Erfahrung oder etwas anderes verschafft hat, das ihm nicht zustand, können wir das korrigieren.',
        ],
      },
      {
        id: 'virtual-items',
        heading: 'Virtuelle Gegenstände',
        paragraphs: [
          'Geld, Erfahrung, Autos und Teile im Spiel sind Teil des Spiels. Sie haben außerhalb davon keinen Wert, lassen sich nicht in echtes Geld umtauschen und werden nicht dein Eigentum.',
          'RideIdle ist derzeit kostenlos und hat keine kostenpflichtigen Funktionen. Wenn sich das ändert, sagen wir es dir, und für Käufe gelten dann gesonderte Bedingungen.',
        ],
      },
      {
        id: 'service',
        heading: 'Das Spiel kann sich ändern',
        paragraphs: [
          'RideIdle wird noch entwickelt. Wir können Funktionen hinzufügen, ändern oder entfernen, und das Spiel kann zeitweise nicht verfügbar sein.',
          'Dein Fortschritt wird auf unseren Servern gespeichert, aber wir können nicht versprechen, dass er nie verloren geht oder verändert wird. Wenn es nötig ist, um einen Fehler zu beheben, können wir Kontostände oder den Fortschritt anpassen und, wo es angemessen ist, den Grund nennen.',
        ],
      },
      {
        id: 'ending',
        heading: 'Ende der Nutzung',
        paragraphs: [
          'Du kannst jederzeit aufhören zu spielen. Wenn du dein Konto löschen lassen möchtest, schreibe an die Kontaktadresse oben auf dieser Seite.',
          'Wir können ein Konto sperren oder löschen, das gegen diese Bedingungen verstößt. Gastkonten, die {guestDays} Tage lang inaktiv sind, löschen wir.',
        ],
      },
      {
        id: 'liability',
        heading: 'Unsere Haftung',
        paragraphs: [
          'Wir haften so, wie es das Gesetz verlangt. Nichts in diesen Bedingungen schränkt ein Recht ein, das dir gesetzlich zusteht und nicht eingeschränkt werden kann, zum Beispiel als Verbraucher, oder unsere Haftung für Vorsatz, grobe Fahrlässigkeit oder Schäden an Leben oder Gesundheit.',
          'Darüber hinaus und soweit das Gesetz es erlaubt, haften wir nicht für indirekte Verluste, etwa verlorenen Spielfortschritt, weil das Spiel kostenlos ist und zur Unterhaltung angeboten wird.',
        ],
      },
      {
        id: 'law',
        heading: 'Recht',
        paragraphs: ['Die Verbraucherschutzvorschriften des Landes, in dem du lebst, auf die man nicht verzichten kann, gelten weiterhin für dich.'],
      },
      {
        id: 'changes',
        heading: 'Änderungen dieser Bedingungen',
        paragraphs: [
          'Wir können diese Bedingungen ändern, zum Beispiel wenn sich das Spiel ändert. Das Datum oben zeigt die Version. Wenn eine Änderung wichtig ist, sagen wir es im Spiel. Wenn du das Spiel nach einer Änderung weiter nutzt, gilt die neue Version für dich; wenn du nicht einverstanden bist, kannst du aufhören zu spielen und uns bitten, dein Konto zu löschen.',
        ],
      },
      {
        id: 'contact',
        heading: 'Kontakt',
        paragraphs: ['Fragen zu diesen Bedingungen: Schreibe an die Kontaktadresse oben auf dieser Seite.'],
      },
    ],
  },
  privacy: {
    title: 'Datenschutzerklärung',
    intro:
      'Diese Erklärung beschreibt, welche personenbezogenen Daten RideIdle verwendet, wozu, wie lange und was du dazu tun kannst. Wir haben sie in einfachen Worten geschrieben; wenn etwas unklar ist, frage uns.',
    sections: [
      {
        id: 'controller',
        heading: 'Wer verantwortlich ist',
        paragraphs: [
          'Der Betreiber, der oben auf dieser Seite genannt ist, entscheidet, wozu und wie deine Daten verwendet werden (der Verantwortliche). Du erreichst ihn unter der dort genannten Kontaktadresse, auch mit jeder Frage oder Anfrage zu deinen Daten.',
        ],
      },
      {
        id: 'data',
        heading: 'Welche Daten wir verwenden',
        paragraphs: [
          'Als Gast: den Fahrernamen, den du wählst, die Sprache des Spiels, deinen Fortschritt (Autos, Fahrten, Geld, Erfahrung und deren Verlauf), den Zeitpunkt deiner letzten Aktivität und ein Gerätetoken. Das Token ist ein zufälliges Geheimnis in deinem Browser; wir speichern nur einen Fingerabdruck (Hash) davon.',
          'Wenn du dein Konto sicherst: deine E-Mail-Adresse oder die Kennung, die Google, Facebook oder Apple uns für dein Konto gibt, zusammen mit der E-Mail-Adresse, die sie melden. Das Spiel zeigt eine Adresse nur in gekürzter Form, etwa t***@example.com.',
          'Wir speichern außerdem, wann die Bedingungen akzeptiert und das Alter bestätigt wurden und welche Version der Bedingungen akzeptiert wurde. Wenn du einen Wiederherstellungscode erstellst, speichern wir nur einen Fingerabdruck davon.',
          'Für Anmeldelinks und Anmeldungen über einen Anbieter speichern wir einen kurzlebigen Eintrag: einen Fingerabdruck des Geheimnisses, deine E-Mail-Adresse und den Zweck. Er wird kurz nach Ablauf oder Verwendung gelöscht.',
          'Um Missbrauch zu verhindern, zählen wir Anfragen nach IP-Adresse und nach einem Fingerabdruck der E-Mail-Adresse, höchstens eine Stunde lang. Unsere Server und unser Hosting können außerdem technische Protokolle speichern, etwa die IP-Adresse und den Zeitpunkt einer Anfrage.',
          'Wir verwenden keine Werbe-, Analyse- oder Tracking-Werkzeuge und verkaufen deine Daten nicht.',
        ],
      },
      {
        id: 'why',
        heading: 'Wozu wir sie verwenden und auf welcher Grundlage',
        paragraphs: [
          'Um dir das Spiel und dein Konto zu geben: den Fahrer, deinen Fortschritt, die Anmeldung und die E-Mails, die du anforderst, zum Beispiel einen Anmeldelink. Grundlage: Es ist nötig, um das Spiel bereitzustellen, das du verlangt hast (Artikel 6 Absatz 1 Buchstabe b DSGVO).',
          'Um das Spiel sicher und fair zu halten, Missbrauch und Betrug zu verhindern und Fehler zu beheben. Grundlage: unser berechtigtes Interesse an einem sicheren Spiel (Artikel 6 Absatz 1 Buchstabe f DSGVO).',
          'Um die Akzeptanz der Bedingungen und die Altersbestätigung nachweisen zu können. Grundlage: unser berechtigtes Interesse, das nachweisen zu können (Artikel 6 Absatz 1 Buchstabe f DSGVO).',
        ],
      },
      {
        id: 'recipients',
        heading: 'Wer sie erhält',
        paragraphs: [
          'Unternehmen, die uns beim Betrieb des Spiels helfen: Hosting und Infrastruktur sowie der Dienst, der E-Mails zustellt. Sie dürfen die Daten nur nach unseren Weisungen verwenden.',
          'Google, Facebook (Meta) oder Apple, nur wenn du dich für die Anmeldung über sie entscheidest. Wir senden ihnen nichts über dein Spiel. Sie teilen uns deine Kennung und E-Mail-Adresse mit und verwenden deine Daten nach ihren eigenen Datenschutzerklärungen.',
          'Wir geben deine Daten an niemanden sonst weiter, außer wenn das Gesetz es verlangt.',
        ],
      },
      {
        id: 'transfers',
        heading: 'Außerhalb deines Landes',
        paragraphs: [
          'Einige dieser Unternehmen können Daten außerhalb des Europäischen Wirtschaftsraums oder außerhalb deines Landes verarbeiten. In diesem Fall stützen wir uns auf die Garantien, die das Gesetz verlangt, zum Beispiel Standardvertragsklauseln.',
        ],
      },
      {
        id: 'retention',
        heading: 'Wie lange wir sie speichern',
        paragraphs: [
          'Ein Gastkonto wird mit seinen Spieldaten nach {guestDays} Tagen ohne Aktivität gelöscht.',
          'Ein gesichertes Konto wird gespeichert, solange es besteht. Du kannst uns jederzeit bitten, es zu löschen.',
          'Anmeldelinks und Anmeldeeinträge werden kurz nach Ablauf oder Verwendung gelöscht. Anfragezähler zur Missbrauchsvermeidung bestehen höchstens eine Stunde. Technische Protokolle bewahren wir nur so lange auf, wie die Sicherheit es erfordert.',
        ],
      },
      {
        id: 'rights',
        heading: 'Deine Rechte',
        paragraphs: [
          'Du kannst Auskunft über deine Daten verlangen, ihre Berichtigung, ihre Löschung, die Einschränkung ihrer Verarbeitung und ihre Übertragung in einem gängigen Format. Du kannst außerdem der Verwendung widersprechen, die auf unserem berechtigten Interesse beruht.',
          'Schreibe an die Kontaktadresse oben auf dieser Seite. Wir antworten innerhalb eines Monats. Um dein Konto zu schützen, können wir dich bitten nachzuweisen, dass es deins ist, zum Beispiel durch einen Link, der an die E-Mail-Adresse des Kontos gesendet wird. Derzeit werden Anfragen per E-Mail bearbeitet.',
          'Du kannst dich außerdem bei der Datenschutzaufsichtsbehörde deines Landes beschweren.',
        ],
      },
      {
        id: 'storage',
        heading: 'Was in deinem Browser bleibt',
        paragraphs: [
          'RideIdle verwendet keine Cookies. Es speichert einige Dinge im lokalen Speicher deines Browsers: das Gerätetoken, die gewählte Sprache, eine Markierung des zuletzt angezeigten Zustands deiner Fahrt, die Information, dass das Fenster, das dich bittet, deine Garage zu sichern, verschoben wurde, und, für einige Minuten während einer Anmeldung über einen Anbieter, ein einmaliges Geheimnis. Das Spiel braucht sie zum Funktionieren, und sie werden nicht verwendet, um dich zu verfolgen.',
          'Wenn du die Daten deines Browsers löschst, wirst du abgemeldet. Ein Gastkonto ohne Wiederherstellungscode lässt sich nicht mehr öffnen.',
        ],
      },
      {
        id: 'age',
        heading: 'Alter',
        paragraphs: ['Das Spiel ist für Personen, die mindestens {minAge} Jahre alt sind. Wenn du denkst, dass eine jüngere Person ein Konto hat, schreibe uns, und wir löschen es.'],
      },
      {
        id: 'security',
        heading: 'Sicherheit',
        paragraphs: [
          'Wir speichern die Geheimnisse des Spiels (Wiederherstellungscodes, Anmeldelinks, Gerätetokens) nur als Fingerabdrücke und begrenzen, wie oft sie ausprobiert werden können. Kein System ist vollkommen sicher. Behalte deinen Wiederherstellungscode und deine Anmeldelinks deshalb für dich.',
        ],
      },
      {
        id: 'changes',
        heading: 'Änderungen dieser Erklärung',
        paragraphs: ['Wir können diese Erklärung ändern. Das Datum oben zeigt die Version. Wenn eine Änderung wichtig ist, sagen wir es im Spiel.'],
      },
      {
        id: 'contact',
        heading: 'Kontakt',
        paragraphs: ['Fragen oder Anfragen zu deinen Daten: Schreibe an die Kontaktadresse oben auf dieser Seite.'],
      },
    ],
  },
}

export default texts
