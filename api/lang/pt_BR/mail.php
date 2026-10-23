<?php

return [

    'account_link' => [
        'claim' => [
            'subject' => 'Confirme seu e-mail para salvar sua garagem',
            'intro' => 'Alguém pediu para salvar a garagem de :driver com este e-mail. Se foi você, confirme abaixo.',
            'action' => 'Confirmar e-mail',
        ],
        'add' => [
            'subject' => 'Confirme seu e-mail para adicioná-lo à sua garagem',
            'intro' => 'Alguém pediu para adicionar este e-mail como login da garagem de :driver. Se foi você, confirme abaixo.',
            'action' => 'Confirmar e-mail',
        ],
        'login' => [
            'subject' => 'Seu link para entrar no RideIdle',
            'intro' => 'Use o botão abaixo para entrar na garagem de :driver.',
            'action' => 'Entrar',
        ],
        'existing' => [
            'subject' => 'Este e-mail já tem uma garagem',
            'intro' => 'Alguém tentou salvar uma nova garagem com este e-mail, mas ele já pertence à garagem de :driver. Se foi você e quer usar essa garagem neste aparelho, use o botão abaixo para entrar.',
            'action' => 'Entrar',
        ],
        'expires' => 'O link só funciona uma vez e vale por :minutes minutos.',
        'ignore' => 'Se não foi você, ignore esta mensagem: nada muda sem o link.',
        'fallback' => 'Se o botão não funcionar, copie e cole este endereço no navegador:',
    ],

];
