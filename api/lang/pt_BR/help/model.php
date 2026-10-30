<?php

return [
    'entries' => [
        'slots' => [
            'label' => 'Slots',
            'help' => 'Os lugares do carro onde as peças entram, na ordem em que a garagem e a loja os listam. De 1 a 24 slots; arraste uma linha para mudar a ordem.',
            'range' => 'De 1 a 24 slots.',
            'tip' => 'Um slot que alguma peça ou regra ainda cita não pode ser removido: o salvamento é recusado, com a lista dos lugares que o usam. Uma publicação também não tira um slot em que jogadores têm peças; por isso mudar a chave de um slot em uso fica bloqueado.',
        ],
        'key' => [
            'label' => 'Chave do slot',
            'help' => 'O nome que o slot tem nas peças, nas regras e nos carros dos jogadores. O nome mostrado no jogo (por exemplo “Pneus”) vem do Vocabulário como slot.chave; para uma chave nova, é preciso escrevê-lo lá (inglês e polonês).',
            'example' => 'tires, suspension, brakes, engine, boost, gearbox, exhaust, cooling, tank, ecu (o modelo do jogo de carros).',
            'range' => 'Até 32 caracteres: letras minúsculas, dígitos e _, começando por uma letra, sem repetições.',
        ],
        'role' => [
            'label' => 'Papel do slot',
            'help' => 'Diz às fórmulas do jogo o que esse slot é neste jogo, qualquer que seja a chave: um slot com o papel “Pneus” decide a aderência mesmo que se chame “hull”. Um papel pertence a um só slot, e o jogo precisa ter um slot com o papel de pneus. Um slot sem papel guarda peças cujas estatísticas as fórmulas já leem (como o escapamento).',
            'tip' => 'As peças de um slot com o papel de pneus precisam ter um tipo de pneu, e as de um slot com o papel de motor um motor (arranjo e cilindrada); nos outros slots as peças não têm esses campos.',
            'options' => [
                'tires' => ['label' => 'Pneus', 'help' => 'Aderência, dirigibilidade e frenagem começam aqui, e o tipo de pneu escolhe a coluna da matriz de pisos. Papel obrigatório.'],
                'suspension' => ['label' => 'Suspensão', 'help' => 'A dirigibilidade e a altura livre do solo (capacidade off-road) do carro.'],
                'brakes' => ['label' => 'Freios', 'help' => 'A frenagem do carro.'],
                'engine' => ['label' => 'Motor', 'help' => 'A potência e a massa do carro; uma peça nesse slot substitui o motor de fábrica.'],
                'boost' => ['label' => 'Sobrealimentação', 'help' => 'O ganho de potência, o atraso e a carga sobre o motor.'],
                'gearbox' => ['label' => 'Câmbio', 'help' => 'O limite da velocidade máxima e a largada.'],
                'cooling' => ['label' => 'Arrefecimento', 'help' => 'O intercooler e o radiador: deles depende quanto a sobrealimentação perde.'],
                'tank' => ['label' => 'Tanque', 'help' => 'A capacidade do tanque, ou seja, até onde o carro vai.'],
            ],
        ],
        'hidden' => [
            'label' => 'Oculto na garagem',
            'help' => 'Um slot oculto ainda pode guardar peças e tem seu lugar nas regras, mas a garagem não o lista (no jogo de carros é o slot “ecu”). Um slot oculto não precisa de nome no Vocabulário.',
        ],
        'stats' => [
            'label' => 'Estatísticas',
            'help' => 'As estatísticas de um carro que as telas de garagem e oficina mostram, na ordem da lista. Uma estatística deixada de fora não é mostrada, e não dá para acrescentar uma nova, porque as fórmulas é que fazem as estatísticas.',
            'tip' => 'A lista muda só o que se vê e a nota geral do carro (a média das barras). Nenhuma fórmula de corrida a lê; deixar uma estatística de fora não muda como o carro anda.',
        ],
        'stat' => [
            'label' => 'Estatística',
            'help' => 'Qual estatística mostrar neste lugar da lista. Cada uma só pode aparecer uma vez.',
            'options' => [
                'accel' => ['label' => 'Aceleração', 'help' => 'A potência do carro por tonelada: quanto maior, mais rápido o carro arranca e ganha velocidade.'],
                'top_speed' => ['label' => 'Vel. máxima', 'help' => 'Cresce com a potência (com a raiz cúbica do ganho de potência) e não passa do limite do câmbio.'],
                'grip' => ['label' => 'Aderência', 'help' => 'Vem dos pneus: com que firmeza o carro se agarra à pista.'],
                'handling' => ['label' => 'Dirigibilidade', 'help' => 'Dos pneus e da suspensão, multiplicada pelo fator de massa: um carro pesado faz pior as curvas.'],
                'braking' => ['label' => 'Frenagem', 'help' => 'Dos pneus e dos freios, multiplicada pelo fator de massa: um carro pesado freia pior.'],
                'clearance' => ['label' => 'Off-road', 'help' => 'Altura livre do solo: começa pela carroceria e é calculada em média com a suspensão.'],
            ],
        ],
    ],
];
