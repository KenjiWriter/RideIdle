<?php

return [
    'entries' => [
        '/missions/reset_hour' => [
            'label' => 'Hora em que o dia de missões começa',
            'help' => 'A hora (no fuso horário do jogo) em que o dia de missões começa, e com ele o novo conjunto diário. A semana começa na segunda-feira a essa hora e o mês no dia primeiro a essa hora. O período anterior também termina então: o que o jogador cumpriu e não recolheu, o servidor recolhe por ele, e o resto se perde.',
            'example' => '4: quem joga à noite e depois da meia-noite tem um único dia de missões e recebe o novo conjunto às 4h da manhã.',
            'range' => '0 – 23 (a hora).',
        ],
        '/missions/periods/daily/bonus_coins' => [
            'label' => 'Bônus pelo conjunto completo: dia (moedas)',
            'help' => 'Quantas moedas premium (RideCoin) o jogador ganha por cumprir todas as missões do conjunto do dia. São as únicas moedas grátis que as missões dão; as recompensas das missões avulsas não têm moedas. Este número define, portanto, todas as moedas grátis deste período, e o esquema não permite mais de 5.',
            'example' => '2 moedas: cumprindo os três períodos sempre, são 2, 8 e 25 moedas, cerca de 119 por mês; a conta VIP custa 150 moedas e um pacote de 50 moedas custa 4,99 zł.',
            'range' => '0 – 5 moedas.',
            'tip' => 'Os jogadores sentem um bônus menor como uma recompensa tirada, então comece baixo: aumentar depois é fácil.',
        ],
        '/missions/periods/weekly/bonus_coins' => [
            'label' => 'Bônus pelo conjunto completo: semana (moedas)',
            'help' => 'Quantas moedas premium (RideCoin) o jogador ganha por cumprir todas as missões do conjunto da semana. São as únicas moedas grátis que as missões dão; as recompensas das missões avulsas não têm moedas. Este número define, portanto, todas as moedas grátis deste período, e o esquema não permite mais de 20.',
            'example' => '8 moedas: cumprindo os três períodos sempre, são 2, 8 e 25 moedas, cerca de 119 por mês; a conta VIP custa 150 moedas e um pacote de 50 moedas custa 4,99 zł.',
            'range' => '0 – 20 moedas.',
            'tip' => 'Os jogadores sentem um bônus menor como uma recompensa tirada, então comece baixo: aumentar depois é fácil.',
        ],
        '/missions/periods/monthly/bonus_coins' => [
            'label' => 'Bônus pelo conjunto completo: mês (moedas)',
            'help' => 'Quantas moedas premium (RideCoin) o jogador ganha por cumprir todas as missões do conjunto do mês. São as únicas moedas grátis que as missões dão; as recompensas das missões avulsas não têm moedas. Este número define, portanto, todas as moedas grátis deste período, e o esquema não permite mais de 60.',
            'example' => '25 moedas: cumprindo os três períodos sempre, são 2, 8 e 25 moedas, cerca de 119 por mês; a conta VIP custa 150 moedas e um pacote de 50 moedas custa 4,99 zł.',
            'range' => '0 – 60 moedas.',
            'tip' => 'Os jogadores sentem um bônus menor como uma recompensa tirada, então comece baixo: aumentar depois é fácil.',
        ],
        '/missions/periods/daily/cash_ppm' => [
            'label' => 'Dinheiro pelo conjunto: dia (% do tanque)',
            'help' => 'Quanto dinheiro todas as missões do conjunto do dia pagam juntas, como parte do que o melhor carro do jogador ganha com um tanque cheio, descontado o combustível (100% é um tanque inteiro). O conjunto divide o valor entre suas missões conforme as participações, então paga o mesmo quaisquer que sejam as missões sorteadas. É um extra sobre o ganho dos carros e não conta na economia do jogo: as barreiras e o simulador não o veem.',
            'example' => '6%: um jogador cujo tanque rende $ 1.000 recebe cerca de $ 60 pelo conjunto inteiro.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/weekly/cash_ppm' => [
            'label' => 'Dinheiro pelo conjunto: semana (% do tanque)',
            'help' => 'Quanto dinheiro todas as missões do conjunto da semana pagam juntas, como parte do que o melhor carro do jogador ganha com um tanque cheio, descontado o combustível (100% é um tanque inteiro). O conjunto divide o valor entre suas missões conforme as participações, então paga o mesmo quaisquer que sejam as missões sorteadas. É um extra sobre o ganho dos carros e não conta na economia do jogo: as barreiras e o simulador não o veem.',
            'example' => '20%: um jogador cujo tanque rende $ 1.000 recebe cerca de $ 200 pelo conjunto inteiro.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/monthly/cash_ppm' => [
            'label' => 'Dinheiro pelo conjunto: mês (% do tanque)',
            'help' => 'Quanto dinheiro todas as missões do conjunto do mês pagam juntas, como parte do que o melhor carro do jogador ganha com um tanque cheio, descontado o combustível (100% é um tanque inteiro). O conjunto divide o valor entre suas missões conforme as participações, então paga o mesmo quaisquer que sejam as missões sorteadas. É um extra sobre o ganho dos carros e não conta na economia do jogo: as barreiras e o simulador não o veem.',
            'example' => '60%: um jogador cujo tanque rende $ 1.000 recebe cerca de $ 600 pelo conjunto inteiro.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/daily/xp_ppm' => [
            'label' => 'XP pelo conjunto: dia (% do tanque)',
            'help' => 'Quanto XP do jogador (o nível do piloto) todas as missões do conjunto do dia pagam juntas, como parte do XP que o melhor carro do jogador junta com um tanque cheio. O nível do jogador abre pistas, cidades e peças, e o tutorial diz que os níveis devem ser trabalho das voltas, então mantenha este número pequeno: cada ponto muda o ritmo do jogo todo.',
            'example' => '5%: um jogador cujo tanque dá 1.000 XP recebe cerca de 50 XP pelo conjunto inteiro.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/weekly/xp_ppm' => [
            'label' => 'XP pelo conjunto: semana (% do tanque)',
            'help' => 'Quanto XP do jogador (o nível do piloto) todas as missões do conjunto da semana pagam juntas, como parte do XP que o melhor carro do jogador junta com um tanque cheio. O nível do jogador abre pistas, cidades e peças, e o tutorial diz que os níveis devem ser trabalho das voltas, então mantenha este número pequeno: cada ponto muda o ritmo do jogo todo.',
            'example' => '15%: um jogador cujo tanque dá 1.000 XP recebe cerca de 150 XP pelo conjunto inteiro.',
            'range' => '0 – 1000%.',
        ],
        '/missions/periods/monthly/xp_ppm' => [
            'label' => 'XP pelo conjunto: mês (% do tanque)',
            'help' => 'Quanto XP do jogador (o nível do piloto) todas as missões do conjunto do mês pagam juntas, como parte do XP que o melhor carro do jogador junta com um tanque cheio. O nível do jogador abre pistas, cidades e peças, e o tutorial diz que os níveis devem ser trabalho das voltas, então mantenha este número pequeno: cada ponto muda o ritmo do jogo todo.',
            'example' => '40%: um jogador cujo tanque dá 1.000 XP recebe cerca de 400 XP pelo conjunto inteiro.',
            'range' => '0 – 1000%.',
        ],
    ],
];
