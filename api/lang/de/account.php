<?php

return [

    'recovery' => [
        'invalid' => 'Dieser Wiederherstellungscode ist ungültig.',
        'locked' => 'Zu viele fehlgeschlagene Versuche. Versuche es später erneut.',
    ],

    'email' => [
        'sent' => 'Wir haben einen Link an :email gesendet. Er funktioniert einmal und :minutes Minuten lang.',
        'already_linked' => 'Dieses Konto hat bereits eine E-Mail.',
    ],

    'login_link' => [
        'sent' => 'Wenn diese E-Mail zu einer Garage gehört, ist ein Link unterwegs. Er funktioniert einmal und :minutes Minuten lang.',
    ],

    'oauth' => [
        'unavailable' => 'Diese Art der Anmeldung ist nicht verfügbar.',
        'ticket_invalid' => 'Diese Anmeldung ist abgelaufen oder wurde schon benutzt. Starte sie noch einmal.',
        'no_account' => 'Mit diesem :provider-Konto ist keine Garage verknüpft. Spiele zuerst als Gast und verknüpfe das Konto in deiner Garage.',
        'wrong_account' => 'Diese Anmeldung wurde für eine andere Garage gestartet. Starte sie noch einmal in der Garage, die du verknüpfen willst.',
    ],

    'token' => [
        'invalid' => 'Dieser Link ist ungültig oder abgelaufen.',
    ],

    'identity' => [
        'taken' => 'Diese Anmeldung gehört bereits zu einer anderen Garage. Melde dich bei dieser Garage an oder nutze eine andere.',
        'provider_linked' => 'Dieses Konto hat bereits eine Anmeldung dieser Art.',
    ],

    'name' => [
        'attribute' => 'Fahrername',
        'taken' => 'Dieser Fahrername ist bereits vergeben.',
    ],

];
