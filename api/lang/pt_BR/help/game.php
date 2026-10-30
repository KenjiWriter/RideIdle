<?php

return [
    'entries' => [
        'slug' => [
            'label' => 'Slug do jogo',
            'help' => 'O nome curto com que os comandos, o painel e o registro apontam para este jogo. Você o escolhe uma vez, quando o jogo é criado.',
            'example' => 'ships, raceidle',
            'range' => '2 a 32 caracteres: letras minúsculas, dígitos e hifens, começando por uma letra; diferente para cada jogo.',
            'tip' => 'Ele não pode ser mudado depois, porque os comandos (--game=slug), os scripts de implantação, o registro de auditoria e o seletor de jogos do painel chamam o jogo por ele. No assistente ele é sugerido a partir do nome; você pode corrigi-lo antes de seguir.',
        ],
        'name' => [
            'label' => 'Nome do jogo',
            'help' => 'Como o jogo se chama: na aba do navegador, na barra do app, sob o ícone na tela inicial do celular (só as primeiras palavras que cabem em 12 letras), em e-mails e na tela inicial.',
            'example' => 'Naves Espaciais',
            'range' => 'Até 64 caracteres.',
        ],
        'domain' => [
            'label' => 'Domínio',
            'help' => 'O endereço em que o jogo responde, em minúsculas, sem http:// e sem caminho. Quem abrir este endereço cai neste jogo. Sem domínio, o jogo só é acessível pelo painel (a menos que seja o único jogo da instalação).',
            'example' => 'ships.example.com',
            'range' => 'No mínimo duas partes (jogo.example.com), até 190 caracteres; diferente para cada jogo.',
            'tip' => 'Antes que um certificado possa ser emitido, o domínio deve apontar para este servidor (um registro A, AAAA para IPv6). Depois de definir ou mudar, abra a página “Domínio e SSL” do jogo: ela verifica o DNS e pede ao servidor que configure o endereço e o certificado SSL. Um jogo em rascunho não responde no seu domínio nem assim.',
        ],
        'locale' => [
            'label' => 'Idioma do jogo',
            'help' => 'O idioma em que os novos jogadores veem o jogo até escolherem o seu; é também o idioma da página e do app do jogo quando o idioma do visitante não é um dos do jogo.',
            'options' => [
                'pl' => ['label' => 'Polski', 'help' => 'Os novos jogadores veem o jogo em polonês até escolherem outro idioma.'],
                'en' => ['label' => 'English', 'help' => 'Os novos jogadores veem o jogo em inglês até escolherem outro idioma.'],
                'fr' => ['label' => 'Français', 'help' => 'Os novos jogadores veem o jogo em francês até escolherem outro idioma.'],
                'de' => ['label' => 'Deutsch', 'help' => 'Os novos jogadores veem o jogo em alemão até escolherem outro idioma.'],
                'pt-BR' => ['label' => 'Português (Brasil)', 'help' => 'Os novos jogadores veem o jogo em português do Brasil até escolherem outro idioma.'],
            ],
        ],
        'theme_color' => [
            'label' => 'Cor da moldura',
            'help' => 'A cor da moldura em volta do jogo: a barra do navegador e do celular, a tela inicial do app instalado e a prévia do link. As cores dentro do jogo você define na página “Marca e página”.',
            'example' => '#0e1013 (o escuro do jogo de carros)',
            'range' => 'Seis dígitos hexadecimais com #, por exemplo #0e1013.',
        ],
        'icon_upload' => [
            'label' => 'Ícone',
            'help' => 'Uma imagem quadrada (PNG, WebP ou JPEG), de pelo menos :min × :min pixels, de preferência 512: o ícone do app na tela inicial e na aba do navegador. Sem ele, o jogo fica com o ícone do jogo de carros.',
            'range' => 'Quadrada, de pelo menos :min px de lado, até 2048 px e 1 MB.',
            'tip' => 'Um arquivo escolhido só substitui o ícone atual quando o jogo é salvo; uma imagem que não é quadrada ou tem menos de :min px de lado é recusada e nada é salvo.',
        ],
        'icon_remove' => [
            'label' => 'Tirar o ícone',
            'help' => 'Ao salvar, o jogo volta ao ícone do jogo de carros.',
        ],
        'icon_maskable_upload' => [
            'label' => 'Ícone com margem',
            'help' => 'A mesma imagem desenhada com uma margem larga, para que o que importa fique nos 80% do meio: para os celulares que recortam ícones em círculos ou quadrados arredondados. Opcional; sem ele, o ícone acima é usado como está. Quadrada, de pelo menos :min px.',
        ],
        'icon_maskable_remove' => [
            'label' => 'Tirar o ícone com margem',
            'help' => 'Ao salvar, os celulares voltam a usar o ícone simples de cima.',
        ],
        'status' => [
            'label' => 'Status',
            'help' => 'Se o jogo deixa os jogadores entrarem. Um rascunho não responde no seu domínio; coloque o jogo no ar quando o conteúdo (um pacote) e o domínio estiverem prontos.',
            'tip' => 'Um jogo vazio precisa de um pacote antes de poder ir ao ar. Um jogo novo sempre começa como rascunho.',
            'options' => [
                'live' => ['label' => 'No ar', 'help' => 'O jogo responde no seu domínio, e os jogadores podem criar contas e jogar.'],
                'draft' => ['label' => 'Rascunho', 'help' => 'O jogo está sendo feito: não responde a ninguém no seu domínio e só dá para trabalhar nele pelo painel.'],
            ],
        ],
        'is_default' => [
            'label' => 'Jogo padrão',
            'help' => 'O jogo que responde onde nada diz qual é o pretendido: comandos sem --game e um host desconhecido em desenvolvimento. Ele não é mudado aqui.',
            'tip' => 'Num servidor de produção, um endereço que nenhum jogo no ar reivindica não responde a ninguém, e não ao jogo padrão. Enquanto o jogo padrão não tem pacote no ar, ele joga a partir dos arquivos de conteúdo do repositório.',
        ],
    ],
];
