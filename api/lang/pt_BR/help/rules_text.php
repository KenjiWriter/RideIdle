<?php

return [
    'entries' => [
        'document' => [
            'label' => 'Documento',
            'help' => 'Qual documento mostrar como texto. Trocar o documento descarta o que foi digitado e não salvo.',
            'options' => [
                'starter' => ['label' => 'Regras do jogo', 'help' => 'Todos os números e regras do mundo (starter.json): pistas, preços, tempos, desgaste, mercado. O mesmo que o Estúdio de parâmetros, mas tudo como texto.'],
                'tutorial' => ['label' => 'Tutorial', 'help' => 'Os passos do tutorial e suas recompensas (tutorial.json); os mesmos dados que a página Tutorial edita.'],
            ],
        ],
        'json' => [
            'label' => 'Texto do documento (JSON)',
            'help' => 'O documento inteiro como JSON, para o que as outras páginas não mostram. Ele é julgado pelo mesmo esquema e pelas mesmas regras do mundo das outras páginas; salvar cria um rascunho que chega ao jogo com o próximo pacote.',
            'tip' => 'Um erro de sintaxe (uma vírgula faltando, por exemplo) é mostrado pelo próprio campo e nada é salvo; um erro de conteúdo (valor errado, campo faltando) é mostrado por uma notificação vermelha com o lugar e o motivo (os oito primeiros), também sem salvar. Um documento igual ao publicado não deixa rascunho.',
        ],
    ],
];
