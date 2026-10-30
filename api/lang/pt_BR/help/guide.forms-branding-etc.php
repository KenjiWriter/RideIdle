<?php

return [
    'entries' => [
        'branding' => [
            'label' => 'Como funciona o formulário de marca e página',
            'help' => 'Aqui você define o visual do app deste jogo: cores, fontes, uma folha de estilos própria, os blocos da página inicial e a prévia do link. Um campo vazio é o visual do app; um rascunho salvo chega ao jogo com o próximo pacote publicado.',
            'steps' => [
                'Comece pelas cores: mude algumas (fundo, destaque, texto) e deixe o resto vazio. As cores são escritas como #rrggbb.',
                'Escolha as fontes dos títulos, do texto e dos números, ou deixe a fonte do app.',
                'Adicione os blocos da página inicial de cima para baixo (Topo da página, Cartões, Texto, Imagem, Botões) e escreva os textos pelo menos em inglês e em polonês.',
                'Defina o título, a descrição e a imagem da prévia do link: é o que aparece quando alguém cola o endereço do jogo em um chat.',
                'Adicione seu próprio CSS por último, e só se as cores não bastarem; as regras que não passarem são avisadas ao salvar.',
                'Salve o rascunho e publique um pacote para os jogadores verem a mudança.',
            ],
            'example' => 'Um jogo de navios: o Destaque #2fb7c9 no lugar do âmbar, a fonte dos títulos “Com serifa do sistema”, um bloco Topo da página com o título “Frotas no mar” (em polonês “Floty na morzu”) e uma prévia do link com o mesmo título.',
        ],
        'vocabulary' => [
            'label' => 'Como funciona o Vocabulário',
            'help' => 'O Vocabulário muda as palavras do app para este jogo (nomes dos slots, menu, textos das telas) em cinco idiomas. Um campo vazio é o texto do app; um rascunho salvo chega ao jogo com o próximo pacote.',
            'steps' => [
                'Escolha uma área, ou digite uma palavra em Buscar e confirme com Enter; no máximo 30 mensagens aparecem de uma vez.',
                'Sob a chave de uma mensagem está o texto do app, que aparece como dica no campo vazio de um idioma.',
                'Escreva o novo texto nos idiomas que você tem; deixe sem mudança os nomes entre chaves, como {name}.',
                'Preencha todos os idiomas que importam para você: um idioma vazio mostrará o texto em inglês do pacote, não o texto do app nesse idioma.',
                'Salve antes de trocar de área, busca ou filtro: as alterações não salvas se perdem. As mensagens alteradas têm um ponto ● junto da chave.',
                'Você pode baixar um tema inteiro para um arquivo ou carregar um de um arquivo com os botões no alto da página; o carregado substitui todas as palavras do pacote no rascunho.',
            ],
            'example' => 'Um jogo de navios: um slot com a chave hull recebe a mensagem slot.hull, em inglês “Hull” e em polonês “Kadłub”.',
        ],
        'rules_text' => [
            'label' => 'Como funciona a edição das regras como texto',
            'help' => 'O caminho avançado: o documento inteiro das regras ou do tutorial como JSON, para o que as outras páginas não mostram. O documento é julgado como nas outras páginas e salvo como rascunho, que chega ao jogo com o próximo pacote.',
            'steps' => [
                'Escolha o documento: Regras do jogo ou Tutorial.',
                'Mude só o necessário e mantenha as chaves, vírgulas e aspas. A maioria dos números é mais fácil de mudar no Estúdio de parâmetros.',
                'Salve. Um erro de sintaxe é mostrado pelo campo, um erro de conteúdo por uma notificação vermelha com o lugar e o motivo; nos dois casos nada é salvo.',
                'Remover uma cidade, uma pista ou um passo do tutorial que os jogadores usam é recusado.',
                'Trocar o documento no campo Documento descarta o texto não salvo.',
            ],
            'example' => 'Nas Regras do jogo, mude “price_cents_per_liter” em “fuel” de 200 para 250 (são centavos: US$ 2,50 por litro), salve e confira no Estúdio de parâmetros que a seção Combustível mostra US$ 2,50.',
        ],
        'studio' => [
            'label' => 'Como funciona o Estúdio de parâmetros',
            'help' => 'O Estúdio muda os números do jogo, uma seção de cada vez: preços, tempos, recompensas, desgaste. Os números estão na unidade em que se pensa (%, s, $, l); um rascunho salvo chega ao jogo com o próximo pacote.',
            'steps' => [
                'Deixe o modo simples ligado (o ritmo e o dinheiro do jogo); desligue-o quando precisar de todos os números.',
                'Escolha uma seção; a frase abaixo dela diz o que mudar os números dela faz no jogo.',
                'Ajuste um número com o controle deslizante, ou desligue Controles deslizantes e digite o valor exato. Ao lado de um número você vê o valor dele e, em amarelo, o do pacote ao vivo quando for diferente; a seta devolve o do pacote ao vivo.',
                'Salve antes de trocar de seção, de modo ou de controles: as alterações não salvas se perdem.',
                'Use a Prévia do equilíbrio para ver como o rascunho salvo muda o ritmo do jogo em relação ao pacote ao vivo.',
            ],
            'example' => 'Na seção Combustível, um litro custa US$ 2,00 (200 centavos no documento). Coloque US$ 2,50, salve e veja na Prévia do equilíbrio como o ritmo do jogo muda.',
        ],
        'model' => [
            'label' => 'Como funciona o Modelo do jogo',
            'help' => 'O modelo diz do que um carro é feito: os slots em que as peças entram (com o papel que as fórmulas conhecem) e as estatísticas que as telas mostram. Um rascunho salvo chega ao jogo com o próximo pacote.',
            'steps' => [
                'Adicione um slot: dê a ele uma chave (letras minúsculas, dígitos, _) e escolha um papel, ou deixe “Sem papel”.',
                'Defina a ordem dos slots arrastando: a garagem e a loja os listam nessa ordem.',
                'Oculte um slot se a garagem não deve listá-lo.',
                'Coloque as estatísticas na ordem das telas, ou deixe de fora as que o jogo não deve mostrar.',
                'Escreva o nome de um novo slot no Vocabulário como slot.chave (inglês e polonês), para o jogo não mostrar a chave pura.',
                'Salve; uma recusa diz o que ainda cita o slot de que você quer se livrar.',
            ],
            'example' => 'Um jogo de navios: um slot hull com o papel Pneus (é ele que decide a aderência), reactor com o papel Motor e thrusters com o papel Sobrealimentação; os nomes “Casco”, “Reator” e “Propulsores” escritos no Vocabulário como slot.hull, slot.reactor e slot.thrusters.',
        ],
    ],
];
