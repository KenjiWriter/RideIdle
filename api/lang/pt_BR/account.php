<?php

return [

    'recovery' => [
        'invalid' => 'Este código de recuperação não é válido.',
        'locked' => 'Muitas tentativas malsucedidas. Tente novamente mais tarde.',
    ],

    'email' => [
        'sent' => 'Enviamos um link para :email. Ele só funciona uma vez e vale por :minutes minutos.',
        'already_linked' => 'Esta conta já tem um e-mail.',
    ],

    'login_link' => [
        'sent' => 'Se este e-mail pertence a uma garagem, um link está a caminho. Ele só funciona uma vez e vale por :minutes minutos.',
    ],

    'oauth' => [
        'unavailable' => 'Este tipo de login não está disponível.',
        'ticket_invalid' => 'Este login expirou ou já foi usado. Comece de novo.',
        'no_account' => 'Nenhuma garagem está vinculada a esta conta :provider. Comece a jogar como convidado e vincule a conta na sua garagem.',
        'wrong_account' => 'Este login foi iniciado para outra garagem. Comece de novo na garagem que você quer vincular.',
    ],

    'token' => [
        'invalid' => 'Este link não é válido ou expirou.',
    ],

    'identity' => [
        'taken' => 'Este login já pertence a outra garagem. Entre nessa garagem ou use outro.',
        'provider_linked' => 'Esta conta já tem um login desse tipo.',
    ],

    'name' => [
        'attribute' => 'nome do piloto',
        'taken' => 'Este nome de piloto já está em uso.',
    ],

];
