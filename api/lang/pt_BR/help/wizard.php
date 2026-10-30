<?php

return [
    'entries' => [
        'start' => [
            'label' => 'Conteúdo para começar',
            'help' => 'Com o que o novo jogo começa. O jogo é criado como rascunho de qualquer maneira; o primeiro pacote vai ao ar na hora quando há conteúdo para começar.',
            'tip' => 'Se o conteúdo não puder ser colocado (o jogo copiado não tem pacote no ar, ou o conteúdo não passa nas verificações), o jogo é criado mesmo assim, sem conteúdo, e o painel avisa; nada se perde.',
            'options' => [
                'template' => ['label' => 'O jogo de carros (modelo)', 'help' => 'Carros, peças, pistas, regras, tutorial e textos do jogo de carros do repositório viram o primeiro pacote, no ar na hora; o mais fácil é começar de algo que já funciona e mudá-lo.'],
                'copy' => ['label' => 'Uma cópia de outro jogo', 'help' => 'O pacote que o jogo escolhido joga agora vira o primeiro pacote do novo (sem os jogadores, as contas e os rascunhos não publicados dele).'],
                'none' => ['label' => 'Sem conteúdo', 'help' => 'Um jogo vazio. Ele precisa de um pacote antes de poder ir ao ar.'],
            ],
        ],
        'copy_from' => [
            'label' => 'Qual jogo copiar',
            'help' => 'O jogo cujo pacote no ar vira o primeiro pacote do novo. Só se copia o pacote que esse jogo joga agora: não os rascunhos, jogadores ou contas dele.',
            'tip' => 'Se o jogo escolhido não tem pacote no ar, o novo jogo é criado sem conteúdo e o painel avisa.',
        ],
        'request_domain' => [
            'label' => 'Configurar o domínio e o SSL agora',
            'help' => 'Quando o jogo for criado, pedir ao servidor que configure o domínio (a entrada do servidor web) e o certificado SSL. O servidor atende o pedido em até um minuto, e você pode acompanhá-lo na página “Domínio e SSL”.',
            'tip' => 'O domínio já deve apontar para este servidor (use “Verificar o DNS” acima), e a instalação precisa de um e-mail para o Let’s Encrypt (ACME_EMAIL); sem eles, o interruptor não aparece ou o pedido é recusado, e o jogo é criado mesmo assim. Você pode pedir de novo depois na página “Domínio e SSL”.',
        ],
    ],
];
