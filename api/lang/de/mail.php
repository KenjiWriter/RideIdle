<?php

return [

    'account_link' => [
        'claim' => [
            'subject' => 'Bestätige deine E-Mail, um deine Garage zu sichern',
            'intro' => 'Jemand hat darum gebeten, die Garage von :driver mit dieser E-Mail zu sichern. Wenn du das warst, bestätige es unten.',
            'action' => 'E-Mail bestätigen',
        ],
        'login' => [
            'subject' => 'Dein Link zur Anmeldung bei RideIdle',
            'intro' => 'Nutze die Schaltfläche unten, um dich bei der Garage von :driver anzumelden.',
            'action' => 'Anmelden',
        ],
        'existing' => [
            'subject' => 'Diese E-Mail hat schon eine Garage',
            'intro' => 'Jemand wollte mit dieser E-Mail eine neue Garage sichern, aber sie gehört bereits zur Garage von :driver. Wenn du das warst und diese Garage auf diesem Gerät nutzen willst, melde dich unten an.',
            'action' => 'Anmelden',
        ],
        'expires' => 'Der Link funktioniert einmal und :minutes Minuten lang.',
        'ignore' => 'Wenn du das nicht warst, ignoriere diese Nachricht: Ohne den Link ändert sich nichts.',
        'fallback' => 'Wenn die Schaltfläche nicht funktioniert, kopiere diese Adresse in deinen Browser:',
    ],

];
