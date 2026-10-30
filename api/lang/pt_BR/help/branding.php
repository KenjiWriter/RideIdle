<?php

return [
    'entries' => [
        'color_bg' => [
            'label' => 'Fundo da página',
            'help' => 'A cor de fundo de todo o app e dos campos de digitação. Em geral a cor mais escura do tema; o fundo da página em si é essa cor um pouco escurecida.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
            'tip' => 'Os vãos escuros e as sombras sob os painéis também são calculados a partir dessa cor; mudá-la desloca todo o “fundo” do tema.',
        ],
        'color_surface' => [
            'label' => 'Superfície (cartões)',
            'help' => 'A cor dos cartões e painéis: das telas de garagem, loja e oficina e dos cartões planos da página inicial. Deve ser um pouco mais clara que o fundo.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_surface_2' => [
            'label' => 'Superfície, nível 2',
            'help' => 'Uma superfície mais clara: a parte de cima de painéis e blocos, os cabeçalhos de tabelas e o fundo iluminado do palco onde o carro fica.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_surface_3' => [
            'label' => 'Superfície, nível 3',
            'help' => 'A superfície mais clara: placas dos botões, chips, a barra superior do jogo e as células vazias da barra de combustível.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_line' => [
            'label' => 'Linha',
            'help' => 'Linhas e bordas finas: as divisões entre as linhas das tabelas e os contornos de cartões e barras.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_line_2' => [
            'label' => 'Linha mais forte',
            'help' => 'Bordas mais visíveis: campos de digitação, botões secundários, etiquetas e as bordas das placas.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_ink' => [
            'label' => 'Texto principal',
            'help' => 'A cor de títulos, números e texto comum. Precisa se destacar bem do fundo e das superfícies, porque a legibilidade de todo o app depende dela.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
            'tip' => 'Meio transparente, essa cor também é o contorno do nome do carro desenhado atrás dele no palco.',
        ],
        'color_muted' => [
            'label' => 'Texto atenuado',
            'help' => 'Texto de segundo plano: descrições, parágrafos das páginas, linhas de tabelas e o ícone de engrenagem da barra superior.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_faint' => [
            'label' => 'Texto fraco',
            'help' => 'O texto mais discreto: as legendas pequenas acima dos campos, as dicas em campos vazios e os slots vazios. Não reduza o contraste a ponto de ficar ilegível.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
            'tip' => 'Alguns detalhes pequenos têm cores fixas (por exemplo os rótulos cinza do menu inferior, a linha sob o título de uma tela e o telefone do tutorial); as cores desta página não os mudam.',
        ],
        'color_amber' => [
            'label' => 'Destaque (âmbar por padrão)',
            'help' => 'A cor principal de destaque, para tudo o que pode ser tocado ou está realçado: o botão principal, o emblema de nível, as barras de progresso, a aba ativa do menu, a borda de um cartão em destaque, as faixas de aviso e a moeda.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
            'tip' => 'Os tons claro e escuro do botão, sua borda e seu brilho são todos calculados a partir dessa única cor. “Amber” é só o nome do papel: a cor pode ser qualquer uma.',
        ],
        'color_amber_ink' => [
            'label' => 'Texto sobre o destaque',
            'help' => 'A cor das letras e ícones sobre o destaque: o texto do botão principal, o número do nível, os rótulos nas placas. Precisa contrastar bastante com a cor de destaque.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_amber_bg' => [
            'label' => 'Fundo do destaque',
            'help' => 'Um tom bem escuro do destaque: o fundo da linha “Você” na tabela da corrida e a segunda cor das faixas de aviso da página inicial.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_cyan' => [
            'label' => 'Segundo destaque (ciano por padrão)',
            'help' => 'A cor do que está em andamento: a barra de combustível da tela do tanque, as barras e anéis dos serviços da oficina, a borda de um painel “em andamento”, a gema do RideCoin e a placa de classe do carro na barra superior.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
            'tip' => 'Ela também tinge de leve o fundo do palco onde o carro fica.',
        ],
        'color_cyan_bg' => [
            'label' => 'Tom escuro do segundo destaque',
            'help' => 'A cor do texto e dos ícones nas placas do segundo destaque: a placa de classe do carro na barra superior e o relógio de um serviço que espera na fila. Apesar do nome “bg”, não é um fundo.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_ok' => [
            'label' => 'Sucesso (verde)',
            'help' => 'A cor do que deu certo: o preenchimento da barra de combustível na barra superior, o botão “+” do tanque, o sinal de mais quando uma estatística sobe, a borda de uma peça instalada e as etiquetas “OK”.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_danger' => [
            'label' => 'Erro e aviso (vermelho)',
            'help' => 'A cor de erros e alarmes: mensagens de erro, pouco combustível, uma peça gasta, botões de excluir e o sinal de menos quando uma estatística cai.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_r1' => [
            'label' => 'Raridade 1: comum',
            'help' => 'A cor da menor raridade das peças (“Comum” no jogo): o nome da raridade, os quadradinhos, a borda do bloco da peça e a marca da peça no carro.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
            'tip' => 'É também a cor das letras das abas inativas (Carro | Frota, Loja | Bolsa | Revendedor).',
        ],
        'color_r2' => [
            'label' => 'Raridade 2: incomum',
            'help' => 'A cor da raridade “Incomum”: o nome da raridade, os quadradinhos, a borda do bloco da peça e a marca da peça no carro.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_r3' => [
            'label' => 'Raridade 3: rara',
            'help' => 'A cor da raridade “Rara”: o nome da raridade, os quadradinhos, a borda do bloco da peça e a marca da peça no carro.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_r4' => [
            'label' => 'Raridade 4: épica',
            'help' => 'A cor da raridade “Épica”: o nome da raridade, os quadradinhos, a borda do bloco da peça e a marca da peça no carro.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
        ],
        'color_r5' => [
            'label' => 'Raridade 5: lendária',
            'help' => 'A cor da maior raridade, “Lendária”: o nome da raridade, os quadradinhos, a borda do bloco da peça e a marca da peça no carro.',
            'range' => '#rrggbb: seis dígitos hexadecimais. Campo vazio: a cor do app (:default).',
            'tip' => 'Por padrão tem a mesma cor do destaque, então as peças lendárias brilham como botões. Mude-a separadamente se o destaque for outro.',
        ],
        'font_display' => [
            'label' => 'Fonte dos títulos',
            'help' => 'A fonte de títulos, botões, nomes de carros e números grandes (no app é estreita, pesada e em maiúsculas). Campo vazio: a fonte do app.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'A fonte estreita e forte do app, padrão dos títulos. O arquivo vem com o app.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'A fonte de texto legível do app, padrão do texto comum. O arquivo vem com o app.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'Uma fonte de largura fixa, padrão dos números. O arquivo vem com o app.'],
                'system-sans' => ['label' => 'Sem serifa do sistema', 'help' => 'A fonte sem serifa do dispositivo do jogador (por exemplo Segoe UI ou Roboto); muda um pouco de um dispositivo para outro.'],
                'system-serif' => ['label' => 'Com serifa do sistema', 'help' => 'A fonte com serifa do dispositivo do jogador (Georgia ou Times New Roman).'],
                'system-mono' => ['label' => 'Largura fixa do sistema', 'help' => 'A fonte de largura fixa do dispositivo do jogador (Consolas, Courier New ou parecida).'],
            ],
        ],
        'font_sans' => [
            'label' => 'Fonte do texto',
            'help' => 'A fonte do texto comum: descrições, parágrafos, mensagens e campos de digitação. Campo vazio: a fonte do app.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'A fonte estreita e forte do app, padrão dos títulos. O arquivo vem com o app.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'A fonte de texto legível do app, padrão do texto comum. O arquivo vem com o app.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'Uma fonte de largura fixa, padrão dos números. O arquivo vem com o app.'],
                'system-sans' => ['label' => 'Sem serifa do sistema', 'help' => 'A fonte sem serifa do dispositivo do jogador (por exemplo Segoe UI ou Roboto); muda um pouco de um dispositivo para outro.'],
                'system-serif' => ['label' => 'Com serifa do sistema', 'help' => 'A fonte com serifa do dispositivo do jogador (Georgia ou Times New Roman).'],
                'system-mono' => ['label' => 'Largura fixa do sistema', 'help' => 'A fonte de largura fixa do dispositivo do jogador (Consolas, Courier New ou parecida).'],
            ],
        ],
        'font_mono' => [
            'label' => 'Fonte dos números',
            'help' => 'A fonte de números e legendas pequenas: os chips da barra superior, os rótulos acima dos campos e as etiquetas. Campo vazio: a fonte do app.',
            'options' => [
                'barlow-condensed' => ['label' => 'Barlow Condensed', 'help' => 'A fonte estreita e forte do app, padrão dos títulos. O arquivo vem com o app.'],
                'barlow' => ['label' => 'Barlow', 'help' => 'A fonte de texto legível do app, padrão do texto comum. O arquivo vem com o app.'],
                'jetbrains-mono' => ['label' => 'JetBrains Mono', 'help' => 'Uma fonte de largura fixa, padrão dos números. O arquivo vem com o app.'],
                'system-sans' => ['label' => 'Sem serifa do sistema', 'help' => 'A fonte sem serifa do dispositivo do jogador (por exemplo Segoe UI ou Roboto); muda um pouco de um dispositivo para outro.'],
                'system-serif' => ['label' => 'Com serifa do sistema', 'help' => 'A fonte com serifa do dispositivo do jogador (Georgia ou Times New Roman).'],
                'system-mono' => ['label' => 'Largura fixa do sistema', 'help' => 'A fonte de largura fixa do dispositivo do jogador (Consolas, Courier New ou parecida).'],
            ],
        ],
        'css' => [
            'label' => 'Folha de estilos própria (CSS)',
            'help' => 'Regras CSS que vêm depois da folha de estilos do app e têm a última palavra, então podem mudar o visual de qualquer elemento. A folha não pode carregar nada de fora: são recusados @import, @font-face, @namespace, image-set(), src(), image(), element(), cross-fade(), paint(), attr(), expression(), javascript:, behavior:, -moz-binding, qualquer url() que não seja uma imagem do jogo (/api/v1/media/nome) e uma barra invertida fora de um texto entre aspas.',
            'example' => '.ttl { text-shadow: none; } tira a sombra sob os títulos das telas.',
            'range' => 'No máximo 20.000 bytes (uma letra fora do ASCII ocupa de 2 a 4 bytes).',
            'tip' => 'A folha é lida como um navegador a lê: comentários e textos entre aspas são ignorados, então content: "\201C" é permitido. Uma folha que não passa não é salva, e a mensagem dá o motivo de cada coisa recusada; nunca é aceita só em parte. Uma imagem em url() já precisa estar no armazém de imagens do jogo (ela é enviada no painel), e seu nome tem 64 caracteres hexadecimais com a extensão png, webp ou jpg.',
        ],
        'blocks' => [
            'label' => 'Blocos da página',
            'help' => 'A página no endereço do domínio do jogo, de cima para baixo. Adicione blocos e arraste-os para mudar a ordem; são no máximo 12. Sem nenhum bloco, o jogo mostra a página inicial do próprio app.',
            'tip' => 'Os tipos de bloco: Topo da página (sobretítulo, título, subtítulo, uma imagem e os botões de início), Cartões (de 1 a 6), Texto (um título e parágrafos), Imagem (com descrição e legenda) e Botões (um título, uma frase e os botões de início). O app adiciona sozinho os botões “Criar piloto” e “Continuar”, e um bloco ao qual falta o indispensável (um título, por exemplo) é ignorado.',
        ],
        'items' => [
            'label' => 'Cartões',
            'help' => 'De 1 a 6 cartões em um bloco, lado a lado (um sob o outro em tela estreita). Cada um tem uma marca (uma legenda pequena acima do título, opcional), um título e um texto.',
        ],
        'text_eyebrow' => [
            'label' => 'Sobretítulo',
            'help' => 'Uma linha pequena em maiúsculas acima do título principal. Escreva-a nos idiomas em que o jogo deve falar; inglês e polonês são obrigatórios assim que algum idioma tem texto no campo, e um idioma sem texto mostra o inglês.',
            'example' => 'Jogo de navegador · idle · corridas (a página inicial do próprio app).',
            'range' => 'Até :limit caracteres em cada idioma.',
        ],
        'text_title' => [
            'label' => 'Título',
            'help' => 'O título: no bloco Topo da página é o título grande da página (obrigatório), em um cartão o título do cartão (obrigatório), e nos blocos Texto e Botões o título acima do conteúdo (opcional). Inglês e polonês são obrigatórios quando o campo tem texto; um idioma sem texto mostra o inglês.',
            'example' => 'Uma oficina que corre enquanto você está fora (a página inicial do próprio app).',
            'range' => 'Até :limit caracteres em cada idioma.',
        ],
        'text_subtitle' => [
            'label' => 'Subtítulo',
            'help' => 'A frase sob o título no bloco Topo da página, logo acima dos botões de início. Inglês e polonês são obrigatórios quando o campo tem texto; um idioma sem texto mostra o inglês.',
            'range' => 'Até :limit caracteres em cada idioma.',
        ],
        'text_alt' => [
            'label' => 'Descrição da imagem',
            'help' => 'Uma descrição da imagem para quem não a vê e para o caso de ela não carregar. Obrigatória no bloco Imagem, opcional no bloco Topo da página.',
            'example' => 'A oficina em Złomowo ao entardecer e três carcaças no pátio.',
            'range' => 'Até :limit caracteres em cada idioma.',
        ],
        'text_kicker' => [
            'label' => 'Marca do cartão',
            'help' => 'Uma linha pequena em maiúsculas acima do título de um cartão. Opcional; inglês e polonês são obrigatórios quando o campo tem texto, e um idioma sem texto mostra o inglês.',
            'example' => 'Escolha, Crescimento, Idle (os cartões da página inicial do próprio app).',
            'range' => 'Até :limit caracteres em cada idioma.',
        ],
        'text_text' => [
            'label' => 'Texto',
            'help' => 'Um texto curto: em um cartão a descrição sob o título (obrigatória), no bloco Botões a frase sob o título (opcional). É texto simples, nunca HTML. Um idioma sem texto mostra o inglês.',
            'range' => 'Até :limit caracteres em cada idioma.',
        ],
        'text_body' => [
            'label' => 'Conteúdo',
            'help' => 'O conteúdo do bloco Texto. Uma linha em branco começa um novo parágrafo; tudo é texto simples, nunca HTML. Inglês e polonês são obrigatórios; um idioma sem texto mostra o inglês.',
            'range' => 'Até :limit caracteres em cada idioma.',
        ],
        'text_caption' => [
            'label' => 'Legenda',
            'help' => 'A legenda sob a imagem no bloco Imagem. Opcional; inglês e polonês são obrigatórios quando o campo tem texto, e um idioma sem texto mostra o inglês.',
            'range' => 'Até :limit caracteres em cada idioma.',
        ],
        'text_description' => [
            'label' => 'Descrição da prévia do link',
            'help' => 'A frase sob o título na prévia que um chat ou rede social faz do endereço do jogo. Há uma só prévia: ela usa o texto no idioma do jogo (das configurações dele) ou o inglês, se esse idioma não tiver, então os demais idiomas não são lidos. Campo vazio: o nome do jogo.',
            'range' => 'Até :limit caracteres em cada idioma.',
        ],
        'share_title' => [
            'label' => 'Título da prévia do link',
            'help' => 'O título que um chat ou rede social mostra quando alguém cola o endereço do jogo. É um texto só, sem idiomas. Campo vazio: o nome do jogo.',
            'range' => 'Até 70 caracteres.',
        ],
        'image_upload' => [
            'label' => 'Imagem',
            'help' => 'A imagem do bloco. Escolha um arquivo: ao salvar o rascunho, ele vai para o armazém de imagens e seu nome é gravado no bloco; sem escolha, a imagem atual permanece.',
            'range' => 'PNG, WebP ou JPEG, até 1 MB, cada lado de :min a :max pixels.',
            'tip' => 'No bloco Topo da página a imagem é esticada na largura toda como uma faixa de 220 px de altura (340 px em tela larga) e cortada, então deixe o importante no meio. No bloco Imagem ela aparece inteira, na largura toda.',
        ],
        'share_image_upload' => [
            'label' => 'Imagem da prévia',
            'help' => 'A imagem da prévia do link, em geral 1200 × 630 pixels. Escolha um arquivo: ao salvar o rascunho, ele vai para o armazém de imagens; sem escolha, a imagem atual permanece.',
            'range' => 'PNG, WebP ou JPEG, até 1 MB, cada lado de :min a :max pixels.',
            'tip' => 'Sem uma imagem própria, a prévia usa o ícone do jogo; um jogo sem ícone fica sem imagem na prévia.',
        ],
        'image_remove' => [
            'label' => 'Tirar a imagem',
            'help' => 'Ative e salve o rascunho para que o bloco (ou a prévia do link) deixe de ter imagem. Um arquivo escolhido no campo ao lado tem prioridade. Só aparece quando há uma imagem definida.',
            'tip' => 'O bloco Imagem exige uma imagem, então salvar sem ela é recusado.',
        ],
    ],
];
