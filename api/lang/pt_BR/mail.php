<?php

return [

    'account_link' => [
        'claim' => [
            'subject' => 'Confirme seu e-mail para proteger sua garagem',
            'intro' => 'Alguém pediu para proteger a garagem de :driver com este e-mail. Se foi você, confirme abaixo.',
            'action' => 'Confirmar e-mail',
        ],
        'login' => [
            'subject' => 'Seu link para entrar no RideIdle',
            'intro' => 'Use o botão abaixo para entrar na garagem de :driver.',
            'action' => 'Entrar',
        ],
        'existing' => [
            'subject' => 'Este e-mail já tem uma garagem',
            'intro' => 'Alguém tentou proteger uma nova garagem com este e-mail, mas ele já pertence à garagem de :driver. Se foi você e quer usar essa garagem neste dispositivo, entre abaixo.',
            'action' => 'Entrar',
        ],
        'expires' => 'O link funciona uma vez e por :minutes minutos.',
        'ignore' => 'Se não foi você, ignore esta mensagem: nada muda sem o link.',
        'fallback' => 'Se o botão não funcionar, copie este endereço para o navegador:',
    ],

];
