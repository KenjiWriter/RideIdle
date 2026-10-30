<?php

return [
    'entries' => [
        'slug' => [
            'label' => 'Slug (identificador)',
            'help' => 'O identificador permanente do carro ou da peça: letras minúsculas e números ligados por hifens. Você o escolhe uma única vez, ao criar; depois ele não pode mais ser mudado e nunca é reutilizado, nem depois de a definição ser arquivada.',
            'example' => 'rdzak-gt-4, turbo-osemka-t2.',
            'range' => 'No máximo 64 caracteres: a–z, 0–9 e hifens simples entre as palavras.',
            'tip' => 'O pacote, as regras (lista da loja, pneus de fábrica), o tutorial e os carros dos jogadores se referem a um carro ou peça pelo slug. Por isso o nome exibido pode mudar, mas o slug não.',
        ],
        'name' => [
            'label' => 'Nome',
            'help' => 'O nome que os jogadores veem, um por idioma do jogo. Inglês e polonês são obrigatórios; um idioma deixado vazio mostra o texto em inglês.',
            'example' => 'Rdzak GT-4; Pneus de rua (pt-BR) e Street tires (en).',
            'range' => 'No máximo 200 caracteres em cada idioma.',
        ],
        'description' => [
            'label' => 'Descrição',
            'help' => 'Um texto curto para o jogador, mostrado hoje na tela em que se escolhe o carro-sucata inicial. É opcional; um carro sem descrição simplesmente não tem uma.',
            'range' => 'No máximo 200 caracteres em cada idioma.',
        ],
        'image_upload' => [
            'label' => 'Enviar imagem',
            'help' => 'Uma imagem do carro ou da peça, mostrada nos seus cartões onde o jogo não tem uma própria. Sem imagem, a definição fica com a aparência de antes.',
            'range' => 'PNG, WebP ou JPEG, arquivo de até 1 MB, cada lado de :min a :max pixels.',
            'tip' => 'O arquivo fica guardado no banco de dados sob a soma de verificação dos seus bytes, e a definição só informa o nome dele. Ele chega ao jogo com o próximo pacote, e um pacote salvo como arquivo o leva junto.',
        ],
        'image_remove' => [
            'label' => 'Remover imagem',
            'help' => 'Ative e salve o rascunho para que o carro ou a peça não tenha mais imagem. A chave só aparece quando há uma imagem definida.',
        ],
    ],
];
