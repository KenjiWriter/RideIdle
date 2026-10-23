<?php

return [

    'recovery' => [
        'invalid' => 'Ten kod odzyskiwania jest nieprawidłowy.',
        'locked' => 'Zbyt wiele nieudanych prób. Spróbuj ponownie później.',
    ],

    'email' => [
        'sent' => 'Wysłaliśmy link na :email. Działa jeden raz, przez :minutes minut.',
        'already_linked' => 'To konto ma już e-mail.',
    ],

    'login_link' => [
        'sent' => 'Jeśli ten e-mail należy do garażu, link jest w drodze. Działa jeden raz, przez :minutes minut.',
    ],

    'oauth' => [
        'unavailable' => 'Ten sposób logowania jest niedostępny.',
        'ticket_invalid' => 'To logowanie wygasło albo zostało już użyte. Zacznij je od nowa.',
        'no_account' => 'Żaden garaż nie jest połączony z tym kontem :provider. Zacznij grać jako gość i połącz konto w swoim garażu.',
        'wrong_account' => 'To logowanie zostało rozpoczęte dla innego garażu. Zacznij je od nowa z garażu, który chcesz połączyć.',
    ],

    'delete' => [
        'name_mismatch' => 'Wpisz imię kierowcy dokładnie tak, jak jest zapisane, żeby usunąć konto.',
    ],

    'token' => [
        'invalid' => 'Ten link jest nieprawidłowy albo wygasł.',
    ],

    'identity' => [
        'taken' => 'To logowanie należy już do innego garażu. Zaloguj się do tego garażu albo użyj innego sposobu.',
        'provider_linked' => 'To konto ma już logowanie tego rodzaju.',
    ],

    'name' => [
        'attribute' => 'imię kierowcy',
        'taken' => 'To imię kierowcy jest już zajęte.',
    ],

];
