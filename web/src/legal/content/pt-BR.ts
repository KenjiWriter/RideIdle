import type { LegalTexts } from '../types'

const texts: LegalTexts = {
  terms: {
    title: 'Termos de uso',
    intro:
      'Estes termos se aplicam ao RideIdle, um jogo de corrida idle que roda no seu navegador. Leia-os junto com a política de privacidade. O responsável pelo jogo está indicado no topo desta página.',
    sections: [
      {
        id: 'scope',
        heading: 'Para quem são estes termos',
        paragraphs: [
          'Ao criar um piloto, entrar ou usar o RideIdle de qualquer outra forma, você aceita estes termos. Se não concordar, não use o jogo.',
          'Se alguma parte destes termos não puder se aplicar a você por causa de lei imperativa do lugar onde você mora, o restante continua valendo.',
        ],
      },
      {
        id: 'age',
        heading: 'Idade mínima',
        paragraphs: [
          'Você precisa ter pelo menos {minAge} anos para usar o RideIdle. Quando você vincula sua conta, pedimos que confirme sua idade e registramos o momento dessa confirmação.',
          'Se soubermos que uma pessoa mais jovem tem uma conta, podemos excluí-la.',
        ],
      },
      {
        id: 'account',
        heading: 'Seu piloto e sua conta',
        paragraphs: [
          'Você começa com uma conta de convidado: escolhe um nome de piloto e o jogo cria a conta sem pedir dados pessoais. Uma conta de convidado é excluída, com tudo o que há nela, após {guestDays} dias sem atividade.',
          'Você pode vincular sua conta a um e-mail, Google, Facebook ou Apple. Uma conta vinculada não expira e pode ser aberta em outro aparelho. Você também pode ter um código de recuperação. Quem tiver seu código de recuperação ou um link de login pode entrar na sua conta, então guarde-os só para você.',
          'Escolha um nome de piloto que não seja ofensivo nem se passe por outra pessoa. Podemos alterar ou remover um nome que viole essa regra.',
          'Você é responsável pelo que acontece na sua conta. Dê informações verdadeiras ao vinculá-la.',
        ],
      },
      {
        id: 'fair-play',
        heading: 'Jogo limpo',
        paragraphs: [
          'O servidor decide cada resultado no jogo. Jogue do jeito que o jogo foi pensado.',
          'Não trapaceie, não use bots nem scripts que joguem por você, não explore erros do jogo de propósito, não tente entrar em contas de outras pessoas e não atrapalhe o serviço para os outros. Se encontrar um erro, avise-nos em vez de usá-lo.',
          'Se um erro ou um jogo desleal deu a alguém dinheiro do jogo, experiência ou outra coisa a que não tinha direito, podemos corrigir isso.',
        ],
      },
      {
        id: 'virtual-items',
        heading: 'Itens virtuais',
        paragraphs: [
          'O dinheiro do jogo, a experiência, os carros e as peças fazem parte do jogo. Não têm valor fora dele, não podem ser trocados por dinheiro de verdade e não se tornam sua propriedade.',
          'O RideIdle é gratuito no momento e não tem recursos pagos. Se isso mudar, avisaremos você, e termos separados valerão para as compras.',
        ],
      },
      {
        id: 'service',
        heading: 'O jogo pode mudar',
        paragraphs: [
          'O RideIdle ainda está em desenvolvimento. Podemos adicionar, alterar ou remover recursos, e o jogo pode ficar indisponível de vez em quando.',
          'Seu progresso fica guardado em nossos servidores, mas não podemos prometer que nunca será perdido nem alterado. Quando for necessário para corrigir um erro, podemos ajustar saldos ou o progresso e, quando for razoável, explicar o motivo.',
        ],
      },
      {
        id: 'ending',
        heading: 'Fim do uso',
        paragraphs: [
          'Você pode parar de jogar a qualquer momento. Para excluir sua conta, escreva para o endereço de contato indicado no topo desta página.',
          'Podemos suspender ou excluir uma conta que viole estes termos. Excluímos contas de convidado inativas por {guestDays} dias.',
        ],
      },
      {
        id: 'liability',
        heading: 'Nossa responsabilidade',
        paragraphs: [
          'Respondemos nos termos da lei. Nada nestes termos limita um direito que você tem por lei e que não pode ser limitado, por exemplo como consumidor, nem a nossa responsabilidade por dolo, culpa grave ou danos à vida ou à saúde.',
          'Fora isso, e na medida em que a lei permitir, não nos responsabilizamos por perdas indiretas, como a perda de progresso no jogo, porque o jogo é gratuito e oferecido para entretenimento.',
        ],
      },
      {
        id: 'law',
        heading: 'Lei aplicável',
        paragraphs: ['As regras de proteção do consumidor do país onde você mora, às quais não se pode renunciar, continuam valendo para você.'],
      },
      {
        id: 'changes',
        heading: 'Mudanças nestes termos',
        paragraphs: [
          'Podemos mudar estes termos, por exemplo quando o jogo mudar. A data no topo mostra a versão. Quando uma mudança for importante, avisaremos no jogo. Se você continuar usando o jogo depois de uma mudança, a nova versão vale para você; se não concordar, pode parar de jogar e pedir que excluamos sua conta.',
        ],
      },
      {
        id: 'contact',
        heading: 'Contato',
        paragraphs: ['Dúvidas sobre estes termos: escreva para o endereço de contato indicado no topo desta página.'],
      },
    ],
  },
  privacy: {
    title: 'Política de privacidade',
    intro:
      'Esta política explica quais dados pessoais o RideIdle usa, para quê, por quanto tempo e o que você pode fazer a respeito. Escrevemos em palavras simples; se algo não estiver claro, pergunte.',
    sections: [
      {
        id: 'controller',
        heading: 'Quem é o responsável',
        paragraphs: [
          'O responsável pelo jogo, indicado no topo desta página, decide para que e como seus dados são usados (é o controlador). Você pode falar com ele no endereço de contato informado lá, inclusive para qualquer dúvida ou pedido sobre seus dados.',
        ],
      },
      {
        id: 'data',
        heading: 'Quais dados usamos',
        paragraphs: [
          'Como convidado: o nome de piloto que você escolhe (os outros pilotos podem vê-lo), o idioma do jogo, seu progresso (carros, corridas, dinheiro do jogo, experiência e o histórico deles), o momento da sua última atividade e um token do aparelho. O token é um segredo aleatório guardado no seu navegador; guardamos apenas um hash dele (um código que não permite recuperá-lo).',
          'Quando você vincula sua conta: seu e-mail, ou o identificador que Google, Facebook ou Apple nos dá para a sua conta, junto com o e-mail que eles informam. O jogo mostra um endereço apenas de forma abreviada, como t***@example.com.',
          'Também registramos quando os termos foram aceitos e a idade confirmada, e qual versão dos termos foi aceita. Se você criar um código de recuperação, guardamos apenas o hash dele.',
          'Para links de login e logins com Google, Facebook ou Apple, guardamos um registro de curta duração: um hash do segredo, seu e-mail e a finalidade. Ele é excluído logo depois de expirar ou ser usado.',
          'Para impedir abusos, contamos as requisições por endereço IP e por um hash do e-mail, por no máximo uma hora. Nossos servidores e a hospedagem também podem guardar registros técnicos, como o endereço IP e o horário de uma requisição.',
          'Não usamos ferramentas de publicidade, de análise ou de rastreamento e não vendemos seus dados.',
        ],
      },
      {
        id: 'why',
        heading: 'Para que usamos os dados e qual é a base legal',
        paragraphs: [
          'Para dar a você o jogo e a conta: o piloto, o progresso, o login e os e-mails que você pede, por exemplo um link de login. Base legal: é necessário para fornecer o jogo que você pediu (artigo 6(1)(b) do GDPR, o Regulamento Geral de Proteção de Dados da UE).',
          'Para manter o jogo seguro e justo, impedir abusos e trapaças e corrigir erros. Base legal: nosso interesse legítimo em manter um jogo seguro (artigo 6(1)(f) do GDPR).',
          'Para poder comprovar a aceitação dos termos e a confirmação da idade. Base legal: nosso interesse legítimo em poder comprová-las (artigo 6(1)(f) do GDPR).',
        ],
      },
      {
        id: 'recipients',
        heading: 'Quem recebe',
        paragraphs: [
          'Empresas que nos ajudam a manter o jogo: hospedagem e infraestrutura, e o serviço que entrega os e-mails. Elas só podem usar os dados conforme nossas instruções.',
          'Google, Facebook (Meta) ou Apple, somente se você escolher entrar com eles. Não enviamos a eles nada sobre o seu jogo. Eles nos informam seu identificador e e-mail e usam seus dados conforme as próprias políticas de privacidade.',
          'Não repassamos seus dados a mais ninguém, a menos que a lei exija.',
        ],
      },
      {
        id: 'transfers',
        heading: 'Fora do seu país',
        paragraphs: [
          'Algumas dessas empresas podem tratar dados fora do Espaço Econômico Europeu ou fora do seu país. Nesse caso, adotamos as garantias que a lei exige, por exemplo cláusulas-padrão contratuais.',
        ],
      },
      {
        id: 'retention',
        heading: 'Por quanto tempo guardamos',
        paragraphs: [
          'Uma conta de convidado, com seus dados de jogo, é excluída após {guestDays} dias sem atividade.',
          'Uma conta vinculada é mantida enquanto existir. Você pode pedir a qualquer momento que a excluamos.',
          'Links de login e registros de login são excluídos logo depois de expirar ou ser usados. Os contadores de requisições para evitar abusos duram no máximo uma hora. Os registros técnicos são guardados só pelo tempo que a segurança exigir.',
        ],
      },
      {
        id: 'rights',
        heading: 'Seus direitos',
        paragraphs: [
          'Você pode pedir acesso aos seus dados, a correção, a exclusão, a limitação do uso e a portabilidade deles. Também pode se opor ao uso baseado no nosso interesse legítimo.',
          'Escreva para o endereço de contato indicado no topo desta página. Respondemos em até um mês. Para proteger sua conta, podemos pedir que você comprove que ela é sua, por exemplo por um link enviado ao e-mail da conta. Por enquanto, os pedidos são atendidos por e-mail.',
          'Você também pode fazer uma reclamação à autoridade de proteção de dados do seu país.',
        ],
      },
      {
        id: 'storage',
        heading: 'O que fica no seu navegador',
        paragraphs: [
          'O RideIdle não usa cookies. Ele guarda algumas coisas no armazenamento local do seu navegador: o token do aparelho, o idioma escolhido, um marcador do último estado da sua corrida que você já viu, a informação de que você adiou a janela que pede para salvar sua garagem e, por alguns minutos durante um login com Google, Facebook ou Apple, um segredo de uso único. O jogo precisa desses dados para funcionar, e eles não são usados para rastrear você.',
          'Se você limpar os dados do navegador, sua sessão será encerrada. Uma conta de convidado sem código de recuperação não pode mais ser aberta.',
        ],
      },
      {
        id: 'age',
        heading: 'Idade',
        paragraphs: ['O jogo é para pessoas com pelo menos {minAge} anos. Se você acha que uma pessoa mais jovem tem uma conta, escreva para nós e a excluiremos.'],
      },
      {
        id: 'security',
        heading: 'Segurança',
        paragraphs: [
          'Guardamos os segredos do jogo (códigos de recuperação, links de login, tokens de aparelho) apenas como hashes e limitamos quantas vezes podem ser tentados. Nenhum sistema é perfeitamente seguro, então guarde seu código de recuperação e seus links de login só para você.',
        ],
      },
      {
        id: 'changes',
        heading: 'Mudanças nesta política',
        paragraphs: ['Podemos mudar esta política. A data no topo mostra a versão. Quando uma mudança for importante, avisaremos no jogo.'],
      },
      {
        id: 'contact',
        heading: 'Contato',
        paragraphs: ['Dúvidas ou pedidos sobre seus dados: escreva para o endereço de contato indicado no topo desta página.'],
      },
    ],
  },
}

export default texts
