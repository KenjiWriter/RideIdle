<?php

return [
    'entries' => [
        '/units/lap/metres' => [
            'label' => 'Unidade da volta: quantos metros',
            'help' => 'Quantos metros tem a unidade em que este jogo conta a extensão da volta de uma pista: 1 para metros, 1852 para a milha náutica, 1609,344 para a milha terrestre. Os números das regras e do banco de dados ficam em metros; a unidade diz em que a extensão de uma pista é escrita e mostrada.',
            'example' => 'O jogo de carros: 1 (um metro). Um jogo de navios: 1852 (uma milha náutica).',
            'range' => 'Um número acima de 0 (do esquema).',
            'tip' => 'Mudar isso não move nenhum tempo nem combustível: só muda o que o jogador vê e o que o operador escreve.',
        ],
        '/units/travel/metres' => [
            'label' => 'Unidade de viagem: quantos metros',
            'help' => 'Quantos metros tem a unidade em que este jogo conta as estradas entre as cidades, o odômetro de um carro, o intervalo da revisão, a vida das peças e a quilometragem dos carros da bolsa: 1000 para quilômetros, 1852 para a milha náutica, 1609,344 para a milha terrestre. Os números das regras e do banco de dados ficam em quilômetros; a unidade diz em que mostrá-los e escrevê-los.',
            'example' => 'O jogo de carros: 1000 (um quilômetro). Um jogo de navios: 1852 (uma milha náutica).',
            'range' => 'Um número acima de 0 (do esquema).',
            'tip' => 'Uma estrada das regras é de quilômetros inteiros, então 20 milhas náuticas são 37 km no documento (37,04 arredondado) e 19,98 milhas na tela.',
        ],
        '/units/speed/kmh' => [
            'label' => 'Unidade de velocidade: quantos km/h',
            'help' => 'Quantos km/h vale uma unidade de velocidade neste jogo: 1 para km/h, 1,852 para o nó, 1,609344 para milhas por hora. As velocidades das regras e dos pacotes ficam em km/h; a unidade diz em que mostrá-las e escrevê-las.',
            'example' => 'O jogo de carros: 1 (km/h). Um jogo de navios: 1,852 (um nó).',
            'range' => 'Um número acima de 0 (do esquema).',
        ],
        '/units/lap/decimals' => [
            'label' => 'Volta: casas decimais',
            'help' => 'Quantas casas decimais tem o número quando o jogador o vê nesta unidade (0 é um número inteiro). Isso só muda como as coisas são mostradas; os campos dos formulários têm sempre as casas necessárias para que o escrito volte ao mesmo metro.',
            'example' => 'Uma volta em milhas náuticas: 2 casas (1,4 nm); uma estrada em milhas náuticas: 0 (20 nm).',
            'range' => 'De 0 a 4 (do esquema).',
        ],
        '/units/travel/decimals' => [
            'label' => 'Viagem: casas decimais',
            'help' => 'Quantas casas decimais tem o número quando o jogador o vê nesta unidade (0 é um número inteiro). Isso só muda como as coisas são mostradas; os campos dos formulários têm sempre as casas necessárias para que o escrito volte ao mesmo metro.',
            'example' => 'Uma volta em milhas náuticas: 2 casas (1,4 nm); uma estrada em milhas náuticas: 0 (20 nm).',
            'range' => 'De 0 a 4 (do esquema).',
        ],
        '/units/speed/decimals' => [
            'label' => 'Velocidade: casas decimais',
            'help' => 'Quantas casas decimais tem o número quando o jogador o vê nesta unidade (0 é um número inteiro). Isso só muda como as coisas são mostradas; os campos dos formulários têm sempre as casas necessárias para que o escrito volte ao mesmo metro.',
            'example' => 'Uma volta em milhas náuticas: 2 casas (1,4 nm); uma estrada em milhas náuticas: 0 (20 nm).',
            'range' => 'De 0 a 4 (do esquema).',
        ],
        '/units/fuel_economy/per' => [
            'label' => 'Consumo por quantas unidades de viagem',
            'help' => 'Por quantas unidades de viagem o jogador vê o consumo de combustível: 100 dá “l/100 km”, 1 dá “l por milha náutica”. É só o que se mostra; os números de combustível do jogo não mudam.',
            'example' => '100 no jogo de carros, 1 em um jogo de navios.',
            'range' => 'Um de 1, 10, 100 (do esquema).',
        ],
        '/track_model/advisor/free_speed_kmh/asphalt' => [
            'label' => 'Velocidade livre: asfalto (km/h)',
            'help' => 'A velocidade de que o calculador de dificuldade do formulário de pista parte para o piso “asfalto”: a do carro de referência em uma pista reta e vazia. O jogo não lê este número: só o botão “Sugerir velocidade” lê.',
            'example' => 'Asfalto 180 km/h.',
            'range' => 'De 20 a 300 km/h (do esquema).',
            'tip' => 'Mudar isso não move nenhuma pista que já existe; só muda o que o calculador sugere da próxima vez.',
        ],
        '/track_model/advisor/free_speed_kmh/gravel' => [
            'label' => 'Velocidade livre: cascalho (km/h)',
            'help' => 'A velocidade de que o calculador de dificuldade do formulário de pista parte para o piso “cascalho”: a do carro de referência em uma pista reta e vazia. O jogo não lê este número: só o botão “Sugerir velocidade” lê.',
            'example' => 'Cascalho 130 km/h.',
            'range' => 'De 20 a 300 km/h (do esquema).',
            'tip' => 'Mudar isso não move nenhuma pista que já existe; só muda o que o calculador sugere da próxima vez.',
        ],
        '/track_model/advisor/corner_loss_kmh' => [
            'label' => 'Velocidade perdida por curva por quilômetro (km/h)',
            'help' => 'Quantos km/h o calculador de dificuldade tira por cada curva fechada por quilômetro da pista. O jogo não lê este número: só o botão “Sugerir velocidade” lê.',
            'example' => '10 km/h: uma pista com 4 curvas por quilômetro perde 40 km/h.',
            'range' => 'De 0 a 100 km/h (do esquema).',
        ],
        '/track_model/advisor/climb_loss_kmh' => [
            'label' => 'Velocidade perdida por 10 m de subida por quilômetro (km/h)',
            'help' => 'Quantos km/h o calculador de dificuldade tira por cada 10 metros de subida por quilômetro da pista. O jogo não lê este número: só o botão “Sugerir velocidade” lê.',
            'example' => '5 km/h: uma pista que sobe 20 m por quilômetro perde 10 km/h.',
            'range' => 'De 0 a 100 km/h (do esquema).',
        ],
        '/track_model/advisor/traffic_loss_kmh' => [
            'label' => 'Velocidade perdida por grau de trânsito (km/h)',
            'help' => 'Quantos km/h o calculador de dificuldade tira por cada grau de trânsito e de trechos estreitos (0 é uma pista vazia, 3 é apertada e cheia). O jogo não lê este número: só o botão “Sugerir velocidade” lê.',
            'example' => '5 km/h: trânsito de grau 2 tira 10 km/h.',
            'range' => 'De 0 a 100 km/h (do esquema).',
        ],
    ],
];
