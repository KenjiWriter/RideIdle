<?php

return [
    'entries' => [
        'world' => [
            'label' => 'Como funciona o formulário do mundo',
            'help' => 'Aqui você define os lugares em que os carros ganham dinheiro: cidades com o seu clima, as estradas entre elas e as pistas. O que você digita é salvo como rascunho das regras e só chega aos jogadores com o próximo pacote publicado.',
            'steps' => [
                'Aba Cidades: acrescente uma cidade com slug, nomes nos idiomas, um lugar no mapa e as chances do seu clima (somando 100%).',
                'Aba Estradas: ligue as cidades por estradas e informe a extensão em km; toda cidade deve ser alcançável a partir da primeira.',
                'Aba Pistas: acrescente uma pista numa cidade: piso, tipo, nível, extensão da volta, volta de referência, combustível, prêmio e XP.',
                'Defina o que a pista exige de um carro (porcentagens que somam 100) e o valor de referência de cada um desses atributos.',
                'Salve o rascunho; as mudanças chegam aos jogadores com o próximo pacote publicado (página Publicações).',
            ],
            'example' => 'Um sprint de cascalho em Kruszyn, como no jogo de carros: slug kruszyn-gravel-sprint, piso Cascalho, tipo Sprint, nível 3, 380 m, volta de referência 15 000 ms (15 s), combustível 180 ml, prêmio $2,92, XP 3; exige Aceleração 20, Aderência 30, Dirigibilidade 15, Frenagem 10, Off-road 25 (referências 65, 92, 71, 71, 80).',
        ],
        'game' => [
            'label' => 'Como funciona o formulário do jogo',
            'help' => 'Este é um jogo da instalação: o nome, o endereço, o idioma, a cor, os ícones e se está no ar. O jogo em si (carros, regras, textos) se edita nas outras páginas; este formulário é só a identidade dele.',
            'steps' => [
                'Mude o nome, o idioma ou a cor; a barra acima do formulário e a prévia mostram como o jogo vai ficar antes de você salvar.',
                'Acrescente ou mude o domínio e depois abra a página “Domínio e SSL”: o DNS deve apontar para este servidor antes que um certificado seja emitido.',
                'Envie um ícone quadrado (e, se quiser, um com margem para os celulares que recortam ícones).',
                'Defina o status como No ar quando o conteúdo e o domínio estiverem prontos; um rascunho não responde a ninguém.',
                'Salve. O slug e o jogo padrão não se mudam aqui.',
            ],
            'example' => 'Naves Espaciais em ships.example.com: idioma português, cor #1a2b4c, um ícone de 512 × 512, status No ar.',
        ],
        'wizard' => [
            'label' => 'Como o assistente cria um jogo',
            'help' => 'Um jogo se cria em três passos: o nome e o endereço, o conteúdo de que ele parte e o que o servidor faz com o domínio. Ele começa como rascunho, que não responde a ninguém até você colocá-lo no ar; slug, cores, ícones e aparência podem ser definidos depois.',
            'steps' => [
                'Nome e domínio: digite o nome (o slug é sugerido a partir dele), o domínio, se tiver, o idioma e a cor.',
                'Ponto de partida: escolha o jogo de carros como modelo, uma cópia de outro jogo ou nenhum conteúdo.',
                'Domínio e SSL: confira se o domínio aponta para este servidor e, se quiser, peça ao servidor que o configure já.',
                'Clique em “Criar o jogo”. O painel leva você à página “Domínio e SSL” do novo jogo e passa a trabalhar nele.',
                'Quando o conteúdo e o domínio estiverem prontos, defina o status como No ar ao editar o jogo.',
            ],
            'example' => 'Nome “Naves Espaciais”, domínio ships.example.com, idioma português, começar do jogo de carros, pedir o domínio: um jogo em rascunho com o pacote no ar do jogo de carros e um pedido esperando o servidor.',
        ],
    ],
];
