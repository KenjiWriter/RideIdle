<?php

return [
    'entries' => [
        'tutorial' => [
            'label' => 'Como funciona o tutorial',
            'help' => 'O tutorial conduz um piloto novo pelos primeiros minutos, pelo primeiro dia e pela primeira semana do jogo com mensagens de texto do mentor (K.). As mudanças são salvas primeiro como rascunho e só chegam ao jogo com o próximo pacote publicado.',
            'steps' => [
                'No alto, ajuste “A primeira saída”: o combustível na largada, a volta da primeira pane, o primeiro trabalho no máximo, a quantia que o mentor cobre e (opcionalmente) o anúncio na bolsa de carros.',
                'Adicione uma etapa: dê a ela uma chave fixa, uma fase e o número do mentor, e depois escolha quando ela começa e quando termina (fatos sobre o piloto, por exemplo lap_completed ou cash:450).',
                'Escreva a meta da barra de meta, até duas mensagens do mentor e as palavras do botão de resposta; se precisar, acrescente o que a etapa espera. Uma etapa sem mensagens é cumprida sem ser mostrada.',
                'Escolha o que a tela destaca e os trabalhos da oficina que a etapa encurta; se precisar, defina uma peça para o início da etapa e uma recompensa para o fim.',
                'Não mude chaves nem remova etapas em que há jogadores: a publicação de um pacote assim é recusada.',
                'Salve o rascunho, confira na página “Mudanças a publicar” e peça um pacote na página “Publicações”.',
            ],
            'example' => 'Etapa “exchange” (fase 3): começo cash:450 (espera o piloto poder pagar um carro), fim car_bought, destaca a bolsa de carros, e o anúncio do tutorial (Rdzak GT-2, 25 anos, 280.000 km, estado 30%, $450) espera na cidade dele; depois da compra o mentor devolve $300.',
        ],
    ],
];
