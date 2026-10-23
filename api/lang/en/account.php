<?php

return [

    'recovery' => [
        'invalid' => 'This recovery code is not valid.',
        'locked' => 'Too many failed attempts. Try again later.',
    ],

    'email' => [
        'sent' => 'We sent a link to :email. It works once, for :minutes minutes.',
        'already_linked' => 'This account already has an e-mail.',
    ],

    'login_link' => [
        'sent' => 'If this e-mail belongs to a garage, a link is on its way. It works once, for :minutes minutes.',
    ],

    'oauth' => [
        'unavailable' => 'This way of signing in is not available.',
        'ticket_invalid' => 'This sign-in has expired or was already used. Start it again.',
        'no_account' => 'No garage is linked to this :provider account. Start playing as a guest and link the account in your garage.',
        'wrong_account' => 'This sign-in was started for another garage. Start it again from the garage you want to link.',
    ],

    'delete' => [
        'name_mismatch' => 'Type your driver name exactly as it is written to delete the account.',
    ],

    'token' => [
        'invalid' => 'This link is not valid or has expired.',
    ],

    'identity' => [
        'taken' => 'This sign-in already belongs to another garage. Sign in to that garage instead, or use another one.',
        'provider_linked' => 'This account already has a sign-in of this kind.',
    ],

    'name' => [
        'attribute' => 'driver name',
        'taken' => 'This driver name is already taken.',
    ],

];
