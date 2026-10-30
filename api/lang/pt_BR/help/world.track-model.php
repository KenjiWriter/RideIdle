<?php

return [
    'entries' => [
        'speed_kmh' => [
            'label' => 'Velocidade da pista',
            'help' => 'A velocidade média do carro de referência nesta pista, com tudo o que o atrasa já contado: as curvas, o piso, as subidas e o trânsito. A volta de referência é o comprimento dividido por essa velocidade; o combustível, o prêmio e o XP de uma volta decorrem do tempo dela. Um carro de verdade anda mais rápido ou mais devagar conforme o encaixe na pista.',
            'example' => 'Pista urbana de Złomowo: 124,8 km/h (2600 m em 75 s); Circuito do Autódromo: 144 km/h; Pedreira de Kruszyn (cascalho): cerca de 91 km/h.',
            'range' => 'De 20 a 300 km/h, no máximo quatro casas decimais.',
            'tip' => 'Não sabe o que informar? O botão “Sugerir velocidade” calcula a partir das curvas, das subidas e do trânsito. Uma pista mais lenta significa uma volta mais longa e mais combustível por quilômetro; o prêmio por hora fica o que você definir.',
        ],
        'pay_per_hour' => [
            'label' => 'Prêmio por hora ($)',
            'help' => 'Os dólares que uma hora de pilotagem do carro de referência nesta pista rende com tempo seco, antes que o tier do carro, a posição entre os bots e o clima o alterem. Você informa dólares; o jogo guarda centavos. O prêmio de uma volta decorre desse número e do tempo da volta, então uma pista mais longa não paga menos por hora.',
            'example' => '$744 (pista urbana de Złomowo: $15,50 por uma volta de 75 s), $600 (Corrida do ferro-velho), $1.192,50 (Circuito do Autódromo).',
            'range' => 'No mínimo $0,01.',
            'tip' => 'O ganho por hora é o prêmio por hora menos o combustível por hora: é isso que as verificações de equilíbrio comparam entre as pistas, então um novo prêmio muda qual pista é a melhor em um nível. Chuva, temporal e neve pagam 7% a menos (hoje).',
        ],
        'xp_per_hour' => [
            'label' => 'XP por hora',
            'help' => 'A experiência que uma hora de pilotagem nesta pista dá com tempo seco: ela sobe o nível do piloto, que abre novas cidades, pistas e peças. O XP de uma volta decorre desse número e do tempo da volta, arredondado para um inteiro. Um carro mais rápido recebe proporcionalmente menos por volta, então em uma hora todo carro dá o mesmo XP.',
            'example' => '960 XP (pista urbana de Złomowo, 20 XP por volta), 900 XP (Circuito do Autódromo e os sprints).',
            'range' => 'De 0 a 3600, no máximo duas casas decimais.',
            'tip' => 'Em uma pista muito curta, o arredondamento do XP da volta para inteiro pode mudar o resultado em até um quinto; com tempo molhado são 7% a menos.',
        ],
    ],
];
