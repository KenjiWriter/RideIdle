<?php

return [

    'account_link' => [
        'claim' => [
            'subject' => 'Confirm your e-mail to secure your garage',
            'intro' => 'Someone asked to secure the garage of :driver with this e-mail. If it was you, confirm it below.',
            'action' => 'Confirm e-mail',
        ],
        'add' => [
            'subject' => 'Confirm your e-mail to add it to your garage',
            'intro' => 'Someone asked to add this e-mail as a way to sign in to the garage of :driver. If it was you, confirm it below.',
            'action' => 'Confirm e-mail',
        ],
        'login' => [
            'subject' => 'Your link to sign in to RideIdle',
            'intro' => 'Use the button below to sign in to the garage of :driver.',
            'action' => 'Sign in',
        ],
        'existing' => [
            'subject' => 'This e-mail already has a garage',
            'intro' => 'Someone tried to secure a new garage with this e-mail, but it already belongs to the garage of :driver. If it was you and you want to use that garage on this device, sign in below.',
            'action' => 'Sign in',
        ],
        'expires' => 'The link works once and for :minutes minutes.',
        'ignore' => 'If it was not you, ignore this message: nothing changes without the link.',
        'fallback' => 'If the button does not work, copy this address into your browser:',
    ],

];
