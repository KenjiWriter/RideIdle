<?php

return [
    'entries' => [
        'vehicle' => [
            'label' => 'Como funciona o formulário do carro',
            'help' => 'Aqui você cria um carro novo ou muda um existente. Salvar gera um rascunho: ele é conferido com o esquema e só é salvo se passar, mas chega aos jogadores apenas com o próximo pacote que você publicar.',
            'example' => 'Rdzak GT-4: hatch, tração dianteira, combustão, R4 1,8 l com regulagem 1,0, 97 kW, 1000 kg, 175 km/h, altura livre 20, carga 120 kg, 4 lugares, tanque de 45 l, tier 2, nível 3, preço $21.500, fontes: sucata inicial e bolsa.',
            'steps' => [
                'Digite o slug (identificador permanente, escolhido uma vez) e o nome; inglês e polonês são obrigatórios.',
                'Escolha a classe, a tração e a motorização; para um carro a combustão, informe o motor de fábrica (arranjo, cilindrada, regulagem) e o tanque.',
                'Digite potência, massa, velocidade máxima, altura livre, carga e lugares; potência e massa são o que mais muda a condução.',
                'Escolha o tier: o nível do jogador precisa ser 3 × tier − 3 (no mínimo 1). Defina o preço em dólares e marque as fontes: sucata inicial, bolsa, revendedor.',
                'Salve o rascunho; erros do esquema aparecem numa notificação. Depois publique um pacote: ele passa pelas barreiras do simulador, que vigiam o equilíbrio.',
            ],
        ],
        'part' => [
            'label' => 'Como funciona o formulário da peça',
            'help' => 'Aqui você cria uma peça nova ou muda uma existente. Salvar gera um rascunho conferido com o esquema, que chega ao jogo com o próximo pacote publicado; a peça só entra na loja quando está na lista da loja nas regras (Regras como texto, shop.parts).',
            'example' => 'Pneus de rua T2: slot Pneus, tipo De rua, tier 2, preço $570, aderência 100, dirigibilidade 98, frenagem 100. Turbo Ósemka T2: slot Sobrealimentação, tier 2, preço $4.200, potência 1,25, atraso 3, consumo 1,15, carga 8, pelo menos 4 cilindros.',
            'steps' => [
                'Digite o slug e o nome nos idiomas; inglês e polonês são obrigatórios.',
                'Escolha o slot: o papel dele diz o que a peça faz. Para pneus escolha o tipo, para um motor o arranjo e a cilindrada.',
                'Escolha o tier e informe o preço em dólares; os preços da maioria das peças são comparados com a curva da economia (±20%).',
                'Adicione estatísticas como linhas de «um nome e um número» (por exemplo aderência 100, potência 1,25); multiplicadores têm 1 como «sem mudança».',
                'Se precisar, abra «O que exige e a que serve»: mínimo de cilindros, sobrealimentação, motorização, classes.',
                'Salve o rascunho e publique um pacote; o efeito da peça pode ser calculado antes na página Prévia da peça.',
            ],
        ],
    ],
];
