<?php

return [
    'entries' => [
        '/tracks/*/speed_kmh' => [
            'label' => 'Velocidade da pista (km/h)',
            'help' => 'A velocidade média do carro de referência nesta pista, com tudo o que o atrasa já contado: as curvas, o piso, as subidas e o trânsito. A volta de referência é o comprimento da pista dividido por essa velocidade, e o combustível, o prêmio e o XP de uma volta decorrem do tempo dessa volta.',
            'example' => 'Pista urbana de Złomowo: 124,8 km/h (2600 m em 75 s), Circuito do Autódromo: 144 km/h (3200 m em 80 s), Pedreira de Kruszyn: 90,947 km/h (2400 m em 95 s).',
            'range' => 'De 20 a 300 km/h, no máximo quatro casas decimais (do esquema).',
            'tip' => 'Quanto mais lenta a pista (cascalho, sinuosa, de montanha), mais longa a volta e mais combustível por quilômetro, porque o motor trabalha por mais tempo. O prêmio por hora fica o que você definir; mudar a velocidade não muda o que uma hora rende, só quantos quilômetros o carro faz em uma hora (e assim o desgaste das peças e a revisão). Um carro de verdade anda mais rápido ou mais devagar conforme o seu encaixe.',
        ],
        '/tracks/*/pay_cents_per_hour' => [
            'label' => 'Prêmio por hora ($)',
            'help' => 'Os dólares que uma hora de pilotagem do carro de referência nesta pista rende com tempo seco, antes que o tier do carro, a posição entre os bots e o clima o alterem. O prêmio de uma volta é esse número vezes o tempo da volta de referência, então uma pista mais longa não paga menos por hora.',
            'example' => 'Pista urbana de Złomowo: $744 por hora ($15,50 por uma volta de 75 s), Corrida do ferro-velho: $600, Circuito do Autódromo: $1.192,50.',
            'range' => 'No mínimo $0,01 (do esquema).',
            'tip' => 'É a principal alavanca da economia de uma pista: o ganho por hora é o prêmio por hora menos o combustível por hora. As âncoras da economia dão a renda líquida de uma hora para o nível da pista; aqui você informa o valor bruto.',
        ],
        '/tracks/*/xp_per_hour' => [
            'label' => 'XP por hora',
            'help' => 'O XP que uma hora de pilotagem nesta pista dá ao piloto com tempo seco. O XP de uma volta é esse número vezes o tempo da volta de referência, arredondado para um número inteiro. Um carro mais rápido recebe proporcionalmente menos por volta, então em uma hora todo carro dá o mesmo.',
            'example' => 'Pista urbana de Złomowo: 960 XP por hora (20 XP por volta), Circuito do Autódromo: 900.',
            'range' => 'De 0 a 3600, no máximo duas casas decimais (do esquema).',
            'tip' => 'Em uma pista muito curta, o arredondamento para XP inteiro por volta muda o resultado em dez por cento ou mais: o validador avisa quando o arredondamento tira mais de um décimo.',
        ],
        '/track_model/fuel_ml_per_s/sprint' => [
            'label' => 'Combustível por segundo de volta: sprint',
            'help' => 'Quanto combustível o motor de um carro de sede normal queima a cada segundo de uma volta em uma pista do tipo “sprint”. O combustível de uma volta do carro de referência é esse número vezes o tempo da volta, e um carro mais sedento queima mais.',
            'example' => 'Sprint 12 ml (0,012 l) por segundo: a Corrida do ferro-velho (12 s) queima 144 ml.',
            'range' => 'De 1 a 1000 ml por segundo (do esquema).',
            'tip' => 'Mudar este número move de uma vez o combustível de todas as pistas do tipo. Um tanque dura a capacidade ÷ este número, em segundos: 45 l a 12 ml dão cerca de uma hora, a 32 ml cerca de 23 minutos.',
        ],
        '/track_model/fuel_ml_per_s/standard' => [
            'label' => 'Combustível por segundo de volta: padrão',
            'help' => 'Quanto combustível o motor de um carro de sede normal queima a cada segundo de uma volta em uma pista do tipo “padrão”. O combustível de uma volta do carro de referência é esse número vezes o tempo da volta, e um carro mais sedento queima mais.',
            'example' => 'Padrão 20 ml por segundo: a pista urbana de Złomowo (75 s) queima 1,5 l por volta.',
            'range' => 'De 1 a 1000 ml por segundo (do esquema).',
            'tip' => 'Mudar este número move de uma vez o combustível de todas as pistas do tipo. Um tanque dura a capacidade ÷ este número, em segundos: 45 l a 12 ml dão cerca de uma hora, a 32 ml cerca de 23 minutos.',
        ],
        '/track_model/fuel_ml_per_s/endurance' => [
            'label' => 'Combustível por segundo de volta: endurance',
            'help' => 'Quanto combustível o motor de um carro de sede normal queima a cada segundo de uma volta em uma pista do tipo “endurance”. O combustível de uma volta do carro de referência é esse número vezes o tempo da volta, e um carro mais sedento queima mais.',
            'example' => 'Endurance 32 ml por segundo: o Anel viário de Złomowo (300 s) queima 9,6 l por volta.',
            'range' => 'De 1 a 1000 ml por segundo (do esquema).',
            'tip' => 'Mudar este número move de uma vez o combustível de todas as pistas do tipo. Um tanque dura a capacidade ÷ este número, em segundos: 45 l a 12 ml dão cerca de uma hora, a 32 ml cerca de 23 minutos.',
        ],
    ],
];
