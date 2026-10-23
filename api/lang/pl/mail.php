<?php

return [

    'account_link' => [
        'claim' => [
            'subject' => 'Potwierdź e-mail, żeby zabezpieczyć garaż',
            'intro' => 'Ktoś poprosił o zabezpieczenie garażu kierowcy :driver tym adresem e-mail. Jeśli to Ty, potwierdź to poniżej.',
            'action' => 'Potwierdź e-mail',
        ],
        'add' => [
            'subject' => 'Potwierdź e-mail, żeby dodać go do garażu',
            'intro' => 'Ktoś poprosił o dodanie tego adresu e-mail jako sposobu logowania do garażu kierowcy :driver. Jeśli to Ty, potwierdź to poniżej.',
            'action' => 'Potwierdź e-mail',
        ],
        'login' => [
            'subject' => 'Twój link do logowania w RideIdle',
            'intro' => 'Użyj przycisku poniżej, żeby zalogować się do garażu kierowcy :driver.',
            'action' => 'Zaloguj się',
        ],
        'existing' => [
            'subject' => 'Ten e-mail ma już garaż',
            'intro' => 'Ktoś próbował zabezpieczyć nowy garaż tym adresem e-mail, ale należy on już do garażu kierowcy :driver. Jeśli to Ty i chcesz używać tego garażu na tym urządzeniu, zaloguj się poniżej.',
            'action' => 'Zaloguj się',
        ],
        'expires' => 'Link działa jeden raz i przez :minutes minut.',
        'ignore' => 'Jeśli to nie Ty, zignoruj tę wiadomość: bez linku nic się nie zmieni.',
        'fallback' => 'Jeśli przycisk nie działa, skopiuj ten adres do przeglądarki:',
    ],

];
