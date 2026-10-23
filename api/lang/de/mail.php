<?php

return [

    'account_link' => [
        'claim' => [
            'subject' => 'Bestätige deine E-Mail, um deine Garage zu sichern',
            'intro' => 'Jemand hat darum gebeten, die Garage von :driver mit dieser E-Mail-Adresse zu sichern. Wenn du das warst, bestätige es unten.',
            'action' => 'E-Mail bestätigen',
        ],
        'add' => [
            'subject' => 'Bestätige deine E-Mail, um sie deiner Garage hinzuzufügen',
            'intro' => 'Jemand hat darum gebeten, diese E-Mail-Adresse als Anmeldemethode für die Garage von :driver hinzuzufügen. Wenn du das warst, bestätige es unten.',
            'action' => 'E-Mail bestätigen',
        ],
        'login' => [
            'subject' => 'Dein Link zur Anmeldung bei RideIdle',
            'intro' => 'Nutze die Schaltfläche unten, um dich bei der Garage von :driver anzumelden.',
            'action' => 'Anmelden',
        ],
        'existing' => [
            'subject' => 'Diese E-Mail-Adresse hat schon eine Garage',
            'intro' => 'Jemand wollte mit dieser E-Mail-Adresse eine neue Garage sichern, aber diese E-Mail-Adresse gehört bereits zur Garage von :driver. Wenn du das warst und diese Garage auf diesem Gerät nutzen willst, melde dich unten an.',
            'action' => 'Anmelden',
        ],
        'expires' => 'Der Link ist nur einmal und :minutes Minuten lang gültig.',
        'ignore' => 'Wenn du das nicht warst, ignoriere diese Nachricht: Ohne den Link ändert sich nichts.',
        'fallback' => 'Wenn die Schaltfläche nicht funktioniert, kopiere diese Adresse in deinen Browser:',
    ],

];
