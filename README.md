# TaskEsc — gestão de jobs, tarefas, agenda e arquivos

TaskEsc é um sistema PHP 8.1+ para equipes de produção audiovisual, preparado para Apache + MySQL/MariaDB. Ele reúne Kanban, demandas/jobs, responsáveis, clientes, calendário unificado, arquivos, aprovações, financeiro, relatórios e integrações Google em uma única aplicação.

## Requisitos

- PHP 8.1 ou superior com PDO MySQL, cURL, OpenSSL, Fileinfo e Mbstring.
- MySQL 8+ ou MariaDB compatível.
- Apache com `mod_rewrite` habilitado.
- HTTPS em produção, especialmente para OAuth e notificações do Google Calendar.
- Conta Google Cloud para Calendar/Drive quando essas integrações forem usadas.

Não é necessário Node.js, Docker ou processo residente em produção.

## Instalação nova

1. Envie o projeto completo para a hospedagem.
2. Aponte o `DocumentRoot` do domínio/subdomínio para a pasta `public/`.
3. Garanta permissão de escrita do PHP nas pastas `config/` e `storage/` durante a instalação.
4. Crie um banco MySQL/MariaDB vazio e um usuário com acesso a ele.
5. Abra `https://seu-dominio/install.php` e informe URL, banco e usuário administrador.
6. Após a instalação, o arquivo `storage/installed.lock` bloqueia uma reinstalação acidental.
7. Revise permissões: `config/config.php` deve ficar privado e nunca deve ser servido diretamente pela web.

O instalador aplica `database/schema.sql`, cria o administrador e grava as chaves locais da aplicação.

## Atualização de uma instalação existente

Substitua os arquivos da aplicação pelo conteúdo desta versão sem apagar `config/config.php`, `storage/installed.lock` ou seus dados. Na primeira requisição autenticada, o TaskEsc executa a migração compatível em `app/Migrations.php` e atualiza a estrutura necessária sem recriar o banco.

A atualização mantém todas as migrações anteriores e inclui todas as migrações até o **schema 19**. Ela preserva clientes, tasks, arquivos, histórico e integrações existentes. O schema 8 acrescenta apenas metadados de tentativa/conclusão na fila de e-mails para alimentar o log administrativo. Os SQLs de referência ficam em `database/migrations/`, incluindo `005_taskesc_finance_routes_upload.sql`, `006_taskesc_api_notifications.sql`, `007_client_drive_routing.sql` e `008_email_delivery_log.sql`.

Faça backup do banco antes de qualquer atualização em produção.

### Ajustes v2.6 de interface e responsividade

A v2.6 não altera o schema do banco. Ela corrige a rolagem do modal de criação/edição de task, reposiciona o status de sincronização das agendas abaixo do e-mail correspondente e reforça a responsividade geral. Em telas pequenas, a página não cria rolagem horizontal desnecessária; tabelas largas mantêm rolagem horizontal dentro do próprio componente.

### Ajustes v2.7 de Kanban, anexos e modal de task

A v2.7 também **não altera o schema do banco**. Para atualizar uma instalação em produção, basta substituir os arquivos da aplicação preservando `config/config.php`, `storage/installed.lock` e o banco existente.

Nesta versão, o arraste dos cards do Kanban usa atualização visual otimista para responder imediatamente ao soltar o card; prioridades são exibidas em pt-BR; o botão de fechar da task fica fixo no canto superior direito da tela e clicar no fundo fecha a task. A área de arquivos separa **Entrega final** dos materiais de produção, permite excluir anexos com confirmação e remove do Google Drive os anexos hospedados nele. Na criação da task, arquivos podem ser adicionados em várias seleções consecutivas — um por vez ou vários de uma vez — antes do envio.

### Ajustes v2.8 de ordenação, clientes, e-mail e agenda

A v2.8 permite **reordenar verticalmente os cards dentro de cada coluna do Kanban** e também mantém a posição escolhida quando o card muda de coluna. Clientes autenticados podem editar as próprias tasks mesmo depois de aceitas, mas continuam sem permissão para excluí-las.

As notificações de task passam a enfileirar e-mail diretamente para o endereço cadastrado no cliente em eventos públicos como criação, edição, mudança de etapa, comentários, arquivos e aprovações. Em **Configurações**, o administrador vê os últimos 100 disparos com destinatário, assunto, status, número de tentativas, último envio e erro. A fila registra `sent_at` e `last_attempt_at`; após cinco falhas o item fica com status `failed`.

A Agenda passa a abrir por padrão na **visão semanal**.



### Ajustes v3.3 de responsividade, filtros e iconografia

A v3.3 é uma atualização visual e **não altera o schema do banco**. Ela remove rolagens horizontais indevidas dos cards do Dashboard em telas pequenas, reorganiza os indicadores e gráficos para a largura disponível e mantém rolagem lateral apenas nos componentes em que ela é funcional, como tabelas extensas e o quadro Kanban.

No Kanban, os filtros passam a usar uma grade alinhada no desktop e uma composição compacta em duas colunas no mobile, com a busca ocupando a largura total. O menu mobile deixa de renderizar o ícone duplicado. A interface deixa de depender do CDN externo de ícones e passa a carregar os SVGs localmente, incluindo o sino de notificações. O botão **X** redundante foi removido do popover de notificações; ele continua fechando ao tocar novamente no sino, clicar fora ou pressionar Esc.



### Ajustes v3.4 de upload e correções mobile

A v3.4 corrige o texto literal `\\n` que podia aparecer no topo da interface mobile e adiciona proteção de idempotência aos uploads. Cada envio recebe uma chave única por operação; reenvios causados por duplo submit, repetição de requisição ou recuperação de rede reutilizam a mesma sessão/registro em vez de criar outro anexo. O schema 12 adiciona a coluna `files.upload_key` com índice único para reforçar essa garantia no banco.

O upload retomável continua passando pelo TaskEsc, mas agora usa blocos de até **8 MB** em vez de 1 MB, reduzindo significativamente a quantidade de requisições. Arquivos de até 8 MB são enviados em um único bloco. Se a hospedagem rejeitar blocos maiores com HTTP 413, o navegador reduz automaticamente o tamanho até encontrar um valor aceito e guarda essa preferência durante a sessão. Em falhas de comunicação com o Google Drive, o backend consulta a posição já confirmada antes de reenviar dados, evitando retransmissões desnecessárias.



### Ajustes v3.4.1 de cards no mobile

A v3.4.1 remove barras de rolagem horizontais indevidas de cards do Dashboard e de outros cards compactos. Os conteúdos internos agora podem encolher e quebrar linha corretamente, enquanto rolagens horizontais continuam apenas em componentes onde são intencionais, como tabelas extensas e o Kanban.



### Ajustes v3.5 da Agenda

A v3.5 enriquece o card de detalhes dos compromissos sincronizados com o Google Agenda. Ao clicar em um evento, o TaskEsc consulta o evento remoto naquele momento e exibe, quando disponíveis, Google Meet, link do evento no Google Agenda, local/endereço, organizador, participantes com status de confirmação, telefones ou acessos alternativos de conferência, anexos e descrição com links clicáveis.

O schema 13 adiciona campos próprios para armazenar esses metadados. Eventos já existentes são enriquecidos automaticamente no próximo sync e também ao serem abertos, sem necessidade de recriá-los.



### Ajustes v3.5.1 do Financeiro

A v3.5.1 adiciona ao Financeiro um comparativo permanente de **faturamento x custos = lucro/prejuízo** para o período analisado. O bloco exibe os três valores lado a lado, barras proporcionais, margem projetada e o resultado de caixa (recebido menos custos). Quando o faturamento é maior que zero, também mostra quanto de cada R$ 1,00 faturado é consumido por custos e quanto permanece como resultado.

O comparativo usa os mesmos dados consolidados do Financeiro: faturamento das cobranças do período e custos operacionais somados aos custos vinculados às tasks. Ao aplicar um intervalo de datas, o comparativo acompanha automaticamente o período selecionado.



### Ajustes v3.5.2 do lucro

A v3.5.2 define o **lucro líquido** como o valor efetivamente recebido no período menos os custos do mesmo período. O faturamento continua disponível como indicador de receita prevista/cobrada, mas não é mais usado como base do lucro exibido.

Assim, o comparativo principal passa a ser **Recebido − Custos = Lucro/Prejuízo líquido**, e a margem líquida também é calculada sobre o valor recebido. O Dashboard, a tela Financeiro e o relatório financeiro usam a mesma regra.



### Ajustes v3.6 do controle financeiro

A v3.6 separa **custos lançados**, **custos pagos** e **custos pendentes**. Cada custo avulso pode ser criado como pendente ou pago, e os lançamentos recorrentes são gerados como pendentes até que o pagamento seja confirmado. A data de pagamento também fica registrada. O lucro líquido passa a considerar somente os custos efetivamente pagos no período: **recebido − custos pagos**.

A tela Financeiro também ganha um quadro de **mensalidades recorrentes**, mostrando por cliente se a mensalidade do mês foi cobrada, quanto foi recebido, saldo aberto e situação (pago, parcial, pendente ou vencido). Isso deixa separado o que é cobrança mensal recorrente do que é cobrança avulsa.

O schema 14 adiciona o status e a data de pagamento aos custos operacionais e aos custos vinculados às tasks. Registros antigos são preservados como pagos na data original para não alterar o histórico financeiro existente.



### Ajustes v3.7 do painel financeiro

A v3.7 reorganiza o Financeiro para priorizar **entradas, saídas, saldo e saúde do caixa**. O topo passa a mostrar um indicador operacional de saúde, quanto entrou, quanto saiu, o saldo atual, quanto ainda há para receber e quanto ainda há para pagar. Os dados secundários ficam recolhidos em **Detalhes do período**, reduzindo a poluição visual.

Além do comparativo de **Recebido − Custos pagos = Lucro líquido**, há um segundo comparativo de **Faturamento a receber − Custos pendentes = Saldo projetado**, usando as cobranças ainda abertas e os custos ainda não pagos. O saldo após pendências combina o resultado atual com essa projeção.

O schema 15 adiciona categorias de custo gerenciáveis em banco. O cadastro de custo usa um select alimentado por essas categorias e permite criar uma nova categoria sem sair do formulário. Também há um gerenciador de categorias no Financeiro. Instalações novas recebem categorias como Água, Energia / Luz, Internet / Telefonia, Aluguel, Software, Armazenamento / Cloud, Assinaturas, Marketing, Transporte, Equipamentos, Impostos, Serviços, Alimentação, Custo Pessoal e Outros. Categorias já existentes nos lançamentos são importadas automaticamente durante a migração.



### Ajustes v3.7.1 dos filtros financeiros

A v3.7.1 adiciona filtros independentes em todos os principais cards operacionais do Financeiro. **Entradas / cobranças** pode ser pesquisado por texto e filtrado por origem, cliente e status. **Saídas / custos** recebe busca, categoria, tipo e situação de pagamento. **Custos recorrentes** pode ser filtrado por categoria, recorrência, ativo/pausado e pagamento no período. **Mensalidades recorrentes** recebe busca por cliente, situação de cobrança e status de pagamento. O **Histórico financeiro** também passa a ter busca e filtros por ação, cliente, usuário e origem.

Os filtros são aplicados instantaneamente na tabela sem recarregar a página e cada card mantém seu próprio estado durante a sessão. O filtro geral anterior foi reduzido a **Período financeiro**, pois datas continuam afetando os totais e todas as tabelas.

Esta versão também corrige a exibição do saldo de clientes mensais cuja cobrança do mês foi removida ou ainda não está ativa: nesses casos o painel usa corretamente o valor mensal configurado no cliente, sem reaproveitar valores de cobranças antigas.



### Ajustes v3.8 do resumo de caixa

A v3.8 simplifica novamente o topo do Financeiro para responder seis perguntas sem exigir interpretação de vários indicadores: **quanto entrou**, **quanto já saiu**, **quanto tem no momento**, **quanto vai entrar**, **quanto vai sair** e **quanto vai sobrar no final**.

O primeiro bloco, **Até agora**, usa apenas movimentos realizados: recebimentos confirmados menos custos pagos resultam no saldo atual do período. O segundo bloco, **Daqui pra frente**, mostra cobranças em aberto e custos pendentes. A previsão final usa a fórmula **saldo atual + a receber − a pagar**.

O seletor de período foi movido para cima do resumo para deixar claro que os valores representam o intervalo selecionado. Os detalhes de faturamento, categorias e recorrências continuam disponíveis abaixo, assim como os filtros independentes das tabelas.



### Ajustes v3.8.1 do cadastro de custos

A v3.8.1 corrige o alinhamento dos campos do modal **Novo custo**, especialmente o campo de categoria com o botão **Nova categoria**. O botão deixa de alterar a altura da linha do formulário e os controles voltam a alinhar corretamente em duas colunas.

Também corrige o cadastro rápido de categoria. O modal secundário agora é aberto acima do modal de custo em uma camada própria, com foco automático no campo de nome e atualização imediata do select de categoria após o cadastro.



### Ajustes v3.8.2 do detalhamento do caixa

A v3.8.2 torna os seis cards principais do resumo financeiro clicáveis. Ao abrir **Quanto entrou**, o sistema lista cada pagamento que compõe o total, incluindo cliente, forma de pagamento e eventuais estornos. **Quanto já saiu** lista os custos operacionais e custos de tasks efetivamente pagos.

Os cards derivados também explicam a conta: **Quanto tem no momento** abre entradas confirmadas e saídas pagas; **Quanto vai entrar** lista cobranças abertas pelo saldo restante; **Quanto vai sair** lista custos pendentes; e **Quanto vai sobrar no final** reúne os quatro grupos para mostrar exatamente como a projeção final é formada.

O detalhamento usa o mesmo período do Financeiro e os mesmos critérios dos totais, para que a soma da lista corresponda ao valor exibido no card.



### Ajustes v3.9 do Web Push

A v3.9 transforma as notificações do TaskEsc em **Web Push com payload criptografado**, permitindo que o navegador exiba a notificação diretamente pelo Service Worker mesmo quando a página do sistema não está aberta. Uma mesma conta pode manter várias assinaturas ativas, portanto o responsável pode receber a mesma atualização no PC e no celular depois de ativar as notificações em cada dispositivo.

Para tasks, o push possui uma regra rígida de destinatário: **somente o usuário definido em `demands.assigned_to` recebe Web Push**. Administradores, clientes e outros membros da equipe podem continuar recebendo os registros internos/e-mails previstos pelo fluxo existente, mas não recebem push daquela task se não forem o responsável. O próprio autor da ação não recebe push sobre a ação que acabou de executar.

O payload enviado ao dispositivo contém título, texto, URL da task, identificador da notificação e badge. O conteúdo é criptografado conforme Web Push `aes128gcm`/VAPID e o Service Worker abre diretamente a task ao tocar na notificação.

Em **Configurações → App**, cada dispositivo pode ativar ou desativar sua própria assinatura e há um botão **Enviar teste** para validar as assinaturas da conta. No iPhone/iPad, o TaskEsc precisa estar adicionado à Tela de Início para solicitar permissão de Web Push.



### Ajustes v3.9.1 do log de Push

A v3.9.1 adiciona um histórico persistente para cada tentativa de **Web Push**. O log registra **data, destinatário, task, título, mensagem, regra de envio, dispositivo, status HTTP e erro**, sem armazenar endpoint ou chaves privadas do navegador.

Para notificações de task, o campo **Regra** aparece como **Responsável da task**, refletindo a regra de segurança aplicada na v3.9: o Web Push da task é disparado somente para `demands.assigned_to`. Se o responsável possuir mais de um dispositivo ativo, o histórico terá uma entrega por dispositivo, permitindo confirmar se o push chegou ao PC, celular ou ambos.

Também são registrados casos em que o push não pôde ser entregue: **Sem dispositivo**, **Push desativado** e **Falhou**. A tela **Saúde & Logs** ganhou a aba **Push**, e o card de saúde das notificações passa a considerar falhas de entrega das últimas 24 horas.

O schema 16 cria a tabela `push_delivery_log`.



### Ajustes v3.10 dos descontos em pagamentos

A v3.10 adiciona **desconto no registro de pagamento**, com duas formas: **percentual (%)** ou **valor fixo (R$)**. O desconto é aplicado sobre o saldo aberto da cobrança e o modal recalcula em tempo real o valor recebido, o desconto e o saldo restante.

O financeiro continua separando dinheiro real de concessões: **Recebido** considera somente o valor que entrou no caixa, enquanto o **saldo da cobrança** desconta tanto pagamentos quanto descontos concedidos. Assim, uma cobrança de R$ 400 com R$ 40 de desconto e R$ 360 recebidos fica quitada, mas o caixa registra apenas R$ 360 como entrada.

Os detalhes da cobrança e as tabelas de cobranças/mensalidades mostram o total de desconto aplicado. O histórico financeiro também registra o desconto junto do pagamento.

O schema 17 adiciona `discount_cents`, `discount_type` e `discount_percent` em `payments`.



### Ajustes v3.10.1 de mobile e valores a receber

A v3.10.1 corrige a experiência mobile observada no Financeiro e no detalhe das tasks. O modal da task passa a respeitar a área segura do iPhone, mantém o botão de fechar visível, empilha corretamente cabeçalhos e ações, impede quebra ruim do texto dos botões e dá mais espaço entre arquivos e cards.

No Financeiro, as tabelas principais de **Entradas / cobranças**, **Saídas / custos** e **Mensalidades recorrentes** deixam de depender de uma tabela larga no celular e passam a ser apresentadas como cards responsivos com rótulos, ações acessíveis e sem colunas cortadas.

O cálculo de **Quanto vai entrar / A receber** agora soma **todas as cobranças em aberto existentes**, independentemente do período financeiro selecionado. O período continua sendo usado para valores já recebidos, custos pagos, custos pendentes e demais análises do intervalo. O detalhamento do card **Quanto vai entrar** segue a mesma regra e mostra cobranças abertas de qualquer data.



### Ajustes v3.10.2 de interface mobile

A v3.10.2 corrige detalhes adicionais observados no iPhone. Os campos de data do **Período financeiro** passam a respeitar integralmente a largura do card, sem escapar pela lateral.

No detalhe da task, as ações principais passam a usar **ícones compactos no mobile**, mantendo texto no desktop e atributos de acessibilidade. O Kanban também limita corretamente o nome do responsável e outros textos ao interior do card, usando reticências quando necessário.

No Dashboard, quando o administrador possui cinco cards no resumo, o card **A receber** ocupa as duas colunas da última linha no celular. O Financeiro também recebe espaçamento vertical explícito entre os painéis principais para evitar o efeito de cards colados.



### Ajustes v3.10.3 de estabilidade mobile

A v3.10.3 reforça a responsividade de **todos os campos de data** (`date`, `datetime-local`, `month` e `time`) para que respeitem a largura do card/formulário inclusive no Safari/iOS. Grids de formulários, filtros, relatórios e período financeiro também recebem limites explícitos de largura para impedir overflow horizontal.

No mobile, os principais grupos de cards passam a usar espaçamento consistente entre painéis, grids, cards financeiros, arquivos e blocos de análise. A área de arquivos da task usa ações compactas com ícones para **visualizar, baixar, avaliar e excluir**, mantendo `title` e `aria-label` para acessibilidade. Ações principais que precisam de contexto continuam com texto.

A atualização automática foi alterada para não interromper o usuário. O TaskEsc não faz rerender automático enquanto houver campo em edição, seletor/data em foco, navegação aberta ou interação recente. Quando uma atualização da mesma tela é necessária, o sistema preserva a posição vertical da página e também as rolagens internas de Kanban, tabelas, calendário e logs. Navegação da Agenda, filtros, paginação e atualizações na mesma tela usam o mesmo mecanismo para evitar saltos perceptíveis ao topo.


## Apache

O caminho público deve terminar em `/public`. Exemplo conceitual:

```apache
DocumentRoot /var/www/taskesc/public
<Directory /var/www/taskesc/public>
    AllowOverride All
    Require all granted
</Directory>
```

O `.htaccess` da raiz protege o projeto quando a hospedagem não permite alterar o `DocumentRoot`; o `.htaccess` de `public/` trata as regras públicas. Pastas `app/`, `config/`, `database/` e `storage/` não devem ser expostas.

## Google Calendar + múltiplas agendas

No Google Cloud:

1. Crie ou selecione um projeto.
2. Ative **Google Calendar API** e **Google Drive API**.
3. Configure a tela de consentimento OAuth.
4. Crie uma credencial **OAuth Client ID > Web application**.
5. Adicione como URI de redirecionamento autorizada exatamente: `https://seu-dominio/google-callback.php`.
6. No TaskEsc, abra **Configurações > Credenciais das integrações** e informe Client ID e Client Secret.
7. Na tela **Agenda**, use **Conectar outra agenda**. Essa conexão solicita somente as permissões necessárias ao Google Calendar.

A partir da v2.5, **Calendar e Drive são conexões independentes por finalidade**. Conectar uma conta como Drive no cadastro de um cliente **não descobre nem importa as agendas dessa conta**. Somente contas conectadas explicitamente pela tela **Agenda** entram na descoberta/sincronização de calendários. Na tela Agenda, marque ou desmarque quais calendários dessas contas devem participar da visão unificada. A interface permite alternar entre **Dia, Semana e Mês**; Dia e Semana mostram a grade de horários, inclusive os períodos **Livres**, e o clique em um compromisso mostra horário, agenda, local e descrição.

Uma mesma conta Google pode ser autorizada separadamente para as duas finalidades quando necessário.

## Google Drive para os arquivos dos jobs

Em **Clientes**, cada cliente pode ter seu próprio Google Drive. No cadastro/edição, escolha uma conta de Drive já conectada ou clique em **Conectar novo Drive** para autorizar outra conta Google naquele momento. Essa conexão não importa agendas. Para clientes com Drive dedicado, o TaskEsc cria uma pasta `TaskEsc - Nome do cliente` e, dentro dela, uma pasta por job. Se o cliente estiver configurado para **Usar Drive padrão**, o sistema usa como fallback a conta escolhida em **Configurações > Google Drive**. Alterar o Drive do cliente afeta os próximos uploads; arquivos antigos continuam vinculados à conta onde foram enviados.

A partir da **v3.22**, o caminho principal de upload deixa de passar pela VPS: o TaskEsc cria no backend uma sessão retomável autenticada e o navegador envia o arquivo **diretamente para o Google Drive**. Isso remove a dupla transferência PC → VPS → Drive e permite usar muito mais da banda real do usuário. O envio direto usa uma requisição grande/contínua para melhor desempenho, mantém progresso e velocidade em MB/s e, se houver interrupção, consulta no backend o último byte confirmado para retomar. Se o navegador ou a rede não permitirem o caminho direto, o sistema cai automaticamente para o modo compatível via VPS em blocos adaptativos.

Os anexos de tasks não usam mais lista fechada de extensões: **SVG e qualquer outro formato de arquivo** podem ser enviados ao Google Drive, inclusive arquivos de projeto e formatos sem extensão. Imagens, PDFs, vídeo, áudio e texto compatíveis podem ser visualizados quando o navegador suportar; os demais arquivos podem ser abertos pelo Google Drive ou baixados pelo endpoint autenticado do TaskEsc.

Na atualização para a **v3.20**, o limite interno de upload é redefinido para `0`, ou seja, **sem limite total imposto pelo TaskEsc**. O tamanho possível passa a depender do navegador, da conexão, do espaço disponível e dos limites da conta Google Drive. Em **Configurações > Limite por upload** ainda é possível definir posteriormente uma política interna em MB, se desejado.


## Financeiro e relatórios

O financeiro usa a regra comercial atual do sistema:

- clientes **Mensais** geram uma mensalidade a partir de `Valor mensal` e `Dia de pagamento` da própria ficha do cliente;
- tasks marcadas como **Serviço avulso** e com valor maior que zero geram cobrança vinculada à task;
- tasks **Inclusas no pacote mensal** não geram uma segunda cobrança;
- cobranças manuais continuam disponíveis para exceções;
- contratos/ciclos legados permanecem no banco por compatibilidade, mas não são a fonte principal das novas mensalidades.

Os relatórios Financeiro, Clientes e Mensalidades usam as mesmas cobranças geradas por essa regra. Alterações em valores ainda sem pagamento são sincronizadas; depois que existe pagamento registrado, o TaskEsc exige estorno antes de alterar o valor comercial da task.

## Rotas amigáveis e links de task

Com `mod_rewrite` ativo, o painel usa URLs organizadas como `/dashboard`, `/kanban`, `/tasks`, `/agenda`, `/clientes`, `/financeiro`, `/relatorios` e `/configuracoes`.

Abrir uma task normalmente a partir do Kanban/Tasks continua usando modal e **não altera a URL**, preservando a navegação rápida. Para compartilhamento, cada task oferece **Copiar link**, gerando uma URL no formato `/kanban/task/123`. Ao abrir esse endereço — inclusive depois do login — o sistema entra no Kanban e abre automaticamente a task correspondente. Isso permite enviar o link direto por WhatsApp, e-mail ou outro canal.

## Kanban e usuários

Administradores podem criar, editar, mover e excluir colunas do Kanban. Ao excluir uma coluna que possui cards ativos, o sistema exige escolher outra coluna de destino. A coluna é desativada em vez de apagada fisicamente, preservando integridade e histórico.

Toda task nova entra automaticamente na **primeira coluna ativa do Kanban**. Depois disso ela pode ser editada, movida por arrastar e soltar e excluída. A exclusão da task é lógica para preservar relações históricas e financeiras.

Em **Configurações > Usuários e responsáveis**, crie usuários do tipo **Equipe** ou **Administrador**. Esses usuários aparecem no campo **Responsável** da task e podem receber jobs. Perfis de cliente continuam disponíveis para acesso do cliente e aprovações conforme permissões.

A partir da **v3.19**, toda task precisa ter um responsável antes de ser salva. Usuários podem ser vinculados a **mais de um cliente**. O perfil **Equipe** enxerga, edita, move e exclui as tasks de todos os clientes aos quais estiver vinculado, independentemente de ser o responsável direto daquela task. Um membro da Equipe só pode ser definido como responsável de um cliente ao qual esteja vinculado.

Na **v3.23**, o formulário de **Nova solicitação** do acesso Cliente também exibe o campo obrigatório **Responsável**. O cliente pode escolher um Administrador ativo ou um membro da Equipe vinculado ao cliente da solicitação. Em tasks já existentes, o cliente continua sem poder trocar o responsável.

Na **v3.24**, a coluna **Ações** de Entradas / cobranças usa posições fixas para Detalhes, Registrar pagamento e Excluir no desktop. Cobranças já pagas preservam o espaço do botão de pagamento, mantendo os botões alinhados entre todas as linhas; no mobile o layout continua responsivo em cards.

Na **v3.25**, a aba **Financeiro** ganhou um controle de privacidade com ícone de olho. Com o olho aberto, os valores monetários são exibidos normalmente; ao ocultar, valores de cards, fórmulas, tabelas, detalhamentos e demais resumos financeiros passam a aparecer como `••••••`. A preferência fica salva somente no navegador/dispositivo e pode ser alternada a qualquer momento pelo mesmo botão.

Na **v3.20**, o seletor de clientes vinculados ganhou busca por nome em tempo real, mantendo os vínculos já marcados mesmo quando a lista está filtrada.

Na **v3.21**, ao entrar no TaskEsc sem uma assinatura push ativa neste dispositivo, o sistema abre um aviso para ativar notificações. A permissão nativa continua sendo solicitada somente após o clique do usuário, como exigido pelos navegadores. Assinaturas existentes são sincronizadas novamente com o backend ao entrar.

## Clientes e regra comercial

No cadastro de cliente, defina o modelo como **Avulso** ou **Mensal**. Clientes mensais possuem valor mensal e **dia de pagamento mensal (1 a 31)**. Ao criar uma task:

- cliente **Mensal** permite escolher **Incluso no pacote mensal** ou **Serviço avulso**;
- ao escolher **Serviço avulso**, o campo de valor aparece com máscara em reais;
- cliente **Avulso** usa automaticamente **Serviço avulso** e solicita o valor da task.

O valor da task é armazenado internamente em centavos para evitar erros de ponto flutuante, mas é exibido como `R$ 0,00`.

## Prazo de entrega e briefing

A task possui um único campo de **Data de entrega**. Por padrão, uma nova task não pode ser criada com prazo inferior a **24 horas úteis**; sábados e domingos não contam para esse cálculo. O administrador pode alterar esse número em **Configurações > Interface e fluxo**. A validação ocorre no backend e no formulário.

O briefing aceita **Markdown** com pré-visualização para títulos, listas, negrito, itálico, links e trechos de código. A própria criação da task permite selecionar arquivos; depois que os dados da task são salvos, os arquivos são enviados ao Google Drive. Quando todos terminam, o modal fecha e o sistema abre o Kanban. Se um arquivo falhar, a task já criada é preservada e o erro é informado sem provocar uma segunda criação ao tentar novamente.

## Cron

Recomenda-se executar a rotina a cada 5 minutos. Ela processa ciclos financeiros, sincronização Google e fila de e-mail.

Via HTTP, use a URL exibida em **Configurações > Rotina da hospedagem**. Via CLI (recomendado no CloudPanel):

```bash
php /caminho/do/taskesc/bin/cron.php
```

Em execução CLI, a chave HTTP não é necessária. A URL HTTP continua protegida pela chave exibida nas Configurações.

## SMTP

Configure em **Configurações > Credenciais das integrações**: host, porta, criptografia, usuário, senha, remetente e nome do remetente. Para Gmail, use a configuração SMTP da conta e uma senha de app quando a conta exigir autenticação em duas etapas.

## Segurança

- Senhas usam `password_hash()`/`password_verify()`.
- SQL usa prepared statements via PDO.
- Formulários e APIs mutáveis usam proteção CSRF.
- Sessão é regenerada no login.
- Permissões são verificadas no backend por perfil e vínculo.
- Credenciais e tokens ficam no arquivo privado `config/config.php`/banco criptografado, não no JavaScript.
- Downloads do Drive passam pela autorização do TaskEsc; o ID do arquivo não concede acesso por si só.
- Uploads aceitam qualquer extensão, validam o nome do arquivo e usam metadados MIME sem executar o conteúdo no servidor; o envio grande é feito por sessão resumível diretamente para o Drive.

## Estrutura principal

```text
app/                  regras, serviços e integrações
bin/                  rotina CLI
config/               configuração privada
database/             schema e migrations
public/                DocumentRoot, frontend e endpoints
storage/               logs, sessões e compatibilidade de uploads legados
```

## Diagnóstico rápido

- **Erro 500 após atualizar:** confirme PHP 8.1+, extensões necessárias e permissões de `storage/`.
- **Google não conecta:** confira Client ID/Secret e a URI exata de `google-callback.php`.
- **Drive pede reconexão:** desconecte/conecte novamente a conta para aceitar o novo scope.
- **Agenda não aparece:** abra Agenda, marque o calendário desejado e use **Sincronizar agora**.
- **Upload do Drive falha ou parece parado:** confirme que a conta selecionada está conectada e com permissão do Drive; reconecte a conta se necessário. O botão da task mostra o progresso por arquivo e o fluxo tenta novamente falhas transitórias. O TaskEsc também recria automaticamente pastas do Drive removidas.
- **Webhook de calendário não ativa:** use HTTPS público válido; a sincronização pelo cron continua como fallback.


## API REST v1

O TaskEsc 2.8 inclui uma API REST versionada para integrações, automações e futura camada MCP/ChatGPT.

1. Em **Configurações > Conexão ChatGPT / MCP**, gere um token com os escopos `api.read api.write`.
2. Envie o token no header `Authorization: Bearer SEU_TOKEN`.
3. Base: `https://seu-dominio.com/api/v1`.

Principais rotas: `GET /status`, `GET /analysis/overview`, CRUD de `/tasks` e `/clients`, movimentação `/tasks/{id}/move`, comentários, eventos de calendário, financeiro, relatórios e usuários. Escritas passam pelas mesmas validações e regras de negócio do painel. A especificação OpenAPI fica em `/openapi.json` e também aparece em Configurações para facilitar integrações futuras.

## Visualização como cliente

Administradores podem iniciar a visualização diretamente em **Clientes > Visualizar como cliente**. O modo é somente leitura e filtra dashboard, Kanban, tasks e demais dados pelo cliente escolhido. Use o botão **Sair da visão do cliente** no topo para retornar à administração.

## E-mails de atividade

As notificações de tasks (criação, edição, exclusão, mudança de etapa, comentários, arquivos e aprovações) e de ciclo de vida de clientes entram na fila de e-mail. Eventos importados/sincronizados de agendas externas não geram notificações por e-mail. O cron continua responsável por despachar a fila.

## Google Agenda imediato

Ao criar, editar ou cancelar um compromisso em uma agenda Google gravável, o TaskEsc tenta enviar a alteração imediatamente. Se o Google estiver temporariamente indisponível, a alteração fica pendente e o cron tenta novamente.


## Experiência de navegação

A navegação principal exibe um carregamento discreto em tela cheia, apenas com o indicador giratório e o texto “Carregando”, enquanto troca de módulo. A tipografia foi ampliada para melhorar a leitura em monitores Full HD e 4K, sem alterar a estrutura compacta do painel.

No formulário de criação e na área de arquivos de uma task é possível selecionar e enviar **múltiplos arquivos**; eles são processados sequencialmente pelo fluxo resumível do Google Drive, com progresso individual/geral e sem carregar o arquivo completo na memória da VPS.

### Ajustes v3.12 de UX/UI, mobile e controles de data

A v3.12 é uma revisão visual e de usabilidade baseada na auditoria completa das telas em desktop/mobile e **não altera o schema do banco**. A atualização adiciona uma camada de Design System e responsividade que aumenta legibilidade, hierarquia, contraste, áreas de toque, espaçamentos e consistência sem remover funcionalidades existentes.

Os controles nativos de `date`, `datetime-local`, `month` e `time` continuam presentes no formulário para manter validação e envio dos valores, mas deixam de ser o elemento visual. A interface mostra um botão de data estável e acessível que abre o seletor nativo do dispositivo, evitando o overflow observado principalmente no Safari/iOS.

No mobile, tabelas administrativas de Tasks, Clientes, Serviços, Financeiro, Relatórios, Saúde & Logs e Configurações passam a se reorganizar em cards com rótulos, em vez de comprimir ou cortar colunas. Filtros extensos de Tasks e Kanban mantêm a busca visível e recolhem opções secundárias atrás de um controle **Filtros**.

A Agenda abre em **Dia** na primeira entrada pelo smartphone. A visualização semanal continua disponível com rolagem horizontal intencional, e a lista de agendas conectadas fica recolhida por padrão para priorizar o calendário. Relatórios ganham composição de período sem o grande vazio entre abas e filtros; Serviços passa a aproveitar toda a linha de indicadores; Financeiro ganha hierarquia mais clara entre período, caixa atual e projeção.

No desktop, a interface recebe largura máxima de leitura, tipografia e controles ligeiramente maiores, melhor densidade dos painéis, login mais confortável e alvos de interação mais consistentes. Estados de foco visível, suporte a `prefers-reduced-motion` e áreas de toque maiores reforçam acessibilidade.
\n