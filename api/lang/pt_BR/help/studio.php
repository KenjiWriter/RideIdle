<?php

return [
    'entries' => [
        'simple' => [
            'label' => 'Modo simples',
            'help' => 'Ligado: só os números que definem o ritmo e o dinheiro do jogo (preços, recompensa por volta, tempos da oficina, níveis), uma pequena parte dos cerca de 750. Desligado: todos os números da seção, inclusive os que raramente se mexem.',
            'tip' => 'O modo simples vem ligado quando a página abre. Alternar redesenha o formulário a partir do rascunho salvo; por isso salve as alterações antes de alternar.',
        ],
        'section' => [
            'label' => 'Seção',
            'help' => 'Os números do jogo são divididos em seções; uma aparece de cada vez, com a quantidade de números entre parênteses (no modo simples, só os simples) e uma frase sobre o que mudar os números dela faz no jogo. Salve antes de trocar de seção: o que não foi salvo se perde.',
            'options' => [
                'fuel' => ['label' => 'Combustível', 'help' => 'O preço do litro de combustível; o custo de cada volta depende dele.'],
                'repair' => ['label' => 'Reparo na estrada', 'help' => 'O preço do reparo depois de uma pane na pista.'],
                'breakdown' => ['label' => 'Panes', 'help' => 'A chance de pane em uma volta e o quanto o desgaste a aumenta.'],
                'tow' => ['label' => 'Reboque', 'help' => 'Quanto dura o reboque depois de uma pane e o combustível que sobra para o trajeto.'],
                'car' => ['label' => 'Carro: XP, níveis, massa', 'help' => 'Com que rapidez os carros sobem de nível, como a massa afeta a dirigibilidade e quanto pagam os carros das classes mais altas.'],
                'engines' => ['label' => 'Motores', 'help' => 'A potência, a massa e os requisitos dos motores.'],
                'drivetrain' => ['label' => 'Tração', 'help' => 'Como a tração dianteira, a traseira e a integral seguram o piso, e seus limites de potência.'],
                'garage' => ['label' => 'Garagens', 'help' => 'Quantos carros o jogador guarda em uma cidade e quanto custa começar; os preços das garagens e das carcaças do pátio.'],
                'market' => ['label' => 'Mercado: bolsa, revendedor, depósitos, caixas', 'help' => 'Os preços dos carros usados, a revenda, as chances nos depósitos e a frequência com que a bolsa se renova.'],
                'clock' => ['label' => 'Relógio do jogo', 'help' => 'Quanto mais devagar se anda à noite em pistas sem iluminação.'],
                'player' => ['label' => 'Jogador: XP e níveis', 'help' => 'Com que rapidez o jogador sobe de nível, o que abre as cidades, pistas e peças.'],
                'start' => ['label' => 'Início', 'help' => 'A vantagem de um jogador novo na primeira volta.'],
                'bots' => ['label' => 'Bots', 'help' => 'Os tempos dos rivais em relação à pista de referência e os multiplicadores da recompensa por posição.'],
                'shop' => ['label' => 'Loja', 'help' => 'As caixas e a raridade das peças: chances, força e preços.'],
                'affixes' => ['label' => 'Afixos', 'help' => 'A força, as chances e os efeitos dos afixos das peças, inclusive os que dependem do piso.'],
                'wear' => ['label' => 'Desgaste e revisão', 'help' => 'A vida das peças em quilômetros, o custo do reparo e da revisão.'],
                'premium' => ['label' => 'Moeda premium', 'help' => 'Os preços dos pacotes de RideCoin, a aceleração com moedas e o preço do advogado.'],
                'workshop' => ['label' => 'Oficina', 'help' => 'Os tempos de instalação, melhoria e fusão, os preços de baias e mecânicos, o tamanho da fila.'],
                'tire_matrix' => ['label' => 'Matriz de pneus', 'help' => 'A aderência de cada tipo de pneu em cada piso.'],
                'weather' => ['label' => 'Clima', 'help' => 'Como o clima afeta uma volta.'],
                'regions' => ['label' => 'Cidades (números)', 'help' => 'A posição das cidades no mapa e seus requisitos.'],
                'roads' => ['label' => 'Estradas', 'help' => 'As distâncias entre as cidades em quilômetros e, com elas, o tempo e o custo de uma viagem.'],
                'travel' => ['label' => 'Viagens e aceleração', 'help' => 'A velocidade e o combustível de uma viagem, e a condução acelerada com a polícia e as multas.'],
                'tracks' => ['label' => 'Pistas: tempos, recompensas, pesos', 'help' => 'O comprimento, o tempo de volta, o combustível, a recompensa e o XP de uma pista e os pesos das estatísticas dela; muda diretamente o ganho por hora.'],
            ],
        ],
        'sliders' => [
            'label' => 'Controles deslizantes',
            'help' => 'Ligado: um número cujos limites o esquema informa tem um controle deslizante com o valor numa dica; os demais são sempre campos. Desligado: todos os números são campos em que você digita o valor exato.',
            'tip' => 'O controle deslizante anda em passos do número e não serve para intervalos muito largos (mais de 2000 passos), onde há um campo. Alternar redesenha o formulário a partir do rascunho salvo; por isso salve as alterações antes de alternar.',
        ],
    ],
];
