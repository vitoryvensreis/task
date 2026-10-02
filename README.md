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

A atualização mantém todas as migrações anteriores e inclui todas as migrações até o **schema 12**. Ela preserva clientes, tasks, arquivos, histórico e integrações existentes. O schema 8 acrescenta apenas metadados de tentativa/conclusão na fila de e-mails para alimentar o log administrativo. Os SQLs de referência ficam em `database/migrations/`, incluindo `005_taskesc_finance_routes_upload.sql`, `006_taskesc_api_notifications.sql`, `007_client_drive_routing.sql` e `008_email_delivery_log.sql`.

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

Novos uploads são enviados em **blocos de 1 MB** por uma sessão resumível: o navegador envia um bloco por vez ao TaskEsc e o servidor repassa esse bloco ao Google Drive. O arquivo completo não é salvo permanentemente na VPS. O envio possui progresso, retentativas para falhas transitórias, recuperação do último offset confirmado e sessão prolongada, reduzindo travamentos em arquivos grandes e em conexões instáveis. Arquivos antigos que já estavam na VPS continuam compatíveis e podem ser baixados normalmente.

Imagens, PDFs, vídeo, áudio e texto compatíveis podem ser visualizados no card do job. Demais documentos podem ser abertos pelo Google Drive e todos os arquivos podem ser baixados pelo endpoint autenticado do TaskEsc.

Em **Configurações > Limite por upload**, o valor `0` significa **sem limite total imposto pelo TaskEsc**. Nesse modo, o tamanho possível passa a depender do navegador, da conexão, da disponibilidade da conta e dos limites do Google Drive, em vez do `upload_max_filesize`/`post_max_size` precisar comportar o arquivo inteiro. Se desejar uma política interna, informe um limite em MB diferente de zero.


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
- Uploads validam extensão, MIME e tamanho antes de serem enviados ao Drive.

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
