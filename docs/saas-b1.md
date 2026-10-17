# TaskEsc SaaS — Bloco 1: Fundação multiempresa

**Registro de retomada — 2026-10-17.** Branch de desenvolvimento: `feat/saas-b1-foundation`.
**Estado:** B1.1 implementado e validado por CI; homologação/rollout ainda pendentes; B1 completo **não concluído**.
**PR:** [#113](https://github.com/vitoryvensreis/task/pull/113), em rascunho. **CI:** [execução #39030497373](https://github.com/vitoryvensreis/task/actions/runs/39030497373), testes PHP/JavaScript/MySQL 8/MariaDB 10.11 aprovados, deploy omitido.
**Produção:** nenhuma mudança nem criação de contas SaaS deve ser feita automaticamente. O deploy da branch está desabilitado pelo workflow (só `main` faz deploy). O schema operacional continua 30.
**Aviso:** o schema **20** do SaaS antigo é historicamente removido, proibido e **não** será recriado.

## Meta do Bloco 1

Introduzir uma camada de contas assinantes ACIMA dos clientes comerciais atuais. `clients.id` continua sendo o cliente atendido pela empresa; `accounts.id` é a empresa que usa o TaskEsc. Antes de habilitar qualquer nova conta, todo módulo de leitura, escrita, tempo real, API, arquivos e worker precisa verificar a identidade e autorização da conta.

## Entregue nesta etapa — B1.1

- [x] Branch própria a partir da versão de produção (não disparar deploy enquanto o isolamento não estiver finalizado).
- [x] Migração **aditiva** `database/migrations/031_saas_accounts_foundation.sql` com tabelas `accounts`, `account_members` e `account_settings`; não reutiliza as tabelas `workspaces` do SaaS removido.
- [x] Definição dessas tabelas em `database/schema.sql`, mantendo `settings.schema_version = 30` e **sem criar assinaturas nem habilitar multiempresa**.
- [x] `app/SaasFoundationMigration.php`: DDL idempotente e bloqueio de execução concorrente; criação de **uma conta interna** para dados existentes (`taskesc-existing-operation`); associação não destrutiva de usuários atuais; desabilita `saas.external_signup_enabled` e `saas.multi_account_enabled`; usa o marcador independente `settings.saas_foundation_version=1`.
- [x] `app/AccountContext.php`: serviço de validação de vínculo com conta e status ativo, sem confiar em `account_id` fornecido pelo cliente. **Ainda não integrado às rotas da aplicação**.
- [x] `bin/migrate-saas-foundation.php`: CLI com `--dry-run` / `--apply`, exigindo manifesto de backup e SHA256 válidos; nenhuma execução implícita pelo deploy.
- [x] Testes `tests/saas-foundation.php` na matriz MySQL 8/MariaDB 10.11, cobrindo schema antigo 20 bloqueado, guarda transacional, backfill, idempotência, isolamento por membership e preservação de IDs.
- [x] Este diário atualizado com o que foi feito, o que falta e critério de retomada.

**Não confundir:** o SQL `031` é uma numeração de arquivo NOVO; a migração não avança o schema operacional para 31 porque ainda não temos consultas multitenant protegidas em todos os módulos. Ela só estabelece a fundação em modo **inativo**, sob marcador separado.

## B1.2a — extensão aditiva de ownership (em desenvolvimento; não liberado)

**Objetivo deste subpasso:** preparar parte do domínio para ownership explícito, sem habilitar múltiplas empresas nem alterar as consultas legadas globalmente.

- [x] `app/SaasDomainMigration.php`: migração B1.2a manual/idempotente sob advisory lock, requer B1.1 instalada, schema operacional 30, única conta interna, flags de signup/multiempresa em `0`; bloqueia migração em transação aberta e quando já existe outra conta; verifica estrutura e vínculos conhecidos. Marcador próprio `settings.saas_domain_version=2`.
- [x] `database/schema.sql`: coluna `tenant_account_id BIGINT UNSIGNED NULL` e índice composto `(tenant_account_id,id)` em 15 tabelas de domínio. Importante: **`calendars.account_id` já é uma conta Google**; por isso usamos `tenant_account_id` para assinatura SaaS em todos os módulos, evitando semânticas conflitantes.
- [x] 15 raízes cobertas: `clients`, `stages`, `categories`, `projects`, `contracts`, `demands`, `invoices`, `automations`, `whatsapp_contacts`, `expense_categories`, `expense_rules`, `expenses`, `templates`, `google_accounts`, `calendars`.
- [x] `app/AccountOwnership.php`: escopo explícito de leitura/listagem por conta verificada e validação dos parentes (cliente/projeto/etapa/categoria) da task. **Ainda não acoplado aos endpoints existentes, de propósito.**
- [x] `bin/migrate-saas-domain.php`: comando exclusivamente manual `--dry-run` ou `--apply`, exige manifesto de backup com SHA256 real e schema 30.
- [x] `tests/saas-domain.php`: testes de migração de banco legado simulado, idempotência, preservação dos IDs, duas empresas fictícias, tentativas de leitura cruzada e vínculos cruzados. Conectado à matriz MySQL 8/MariaDB 10.11. **CI aprovada:** [GitHub Actions #39031554658](https://github.com/vitoryvensreis/task/actions/runs/39031554658) (MySQL 8, MariaDB 10.11, PHP/JS; deploy ignorado).

**Limitações críticas desta etapa:** `tenant_account_id` é inicialmente **NULLABLE** porque os controllers legados não enviam ownership nos novos INSERTs. Nunca usar essa coluna como prova de isolamento completo. Os dados atuais são associados à conta interna somente quando o CLI for autorizado e o backup for validado. A rotina de backfill se recusa a atuar se existir outra conta. O próximo passo precisa propagar account id em TODAS as escritas, backfills restantes, validações por JOIN e FKs compostas; depois endurecer NOT NULL. Nenhum onboarding externo antes disso.

**Ausente ainda:** `payments`, `contract_items`, `cycles`, `cycle_items`, `demand_usage`, `transitions`, `comments`, `checklists`, `time_entries`, `files`, `reviews`, `change_requests`, `costs`, `events`, `notifications`, `audit_log`, `event_outbox`, `automation_runs`, `automation_run_steps`, `whatsapp_messages`, `mail_queue`, `chat_threads`, `chat_messages`, `chat_message_tasks`, `push_subscriptions`, `push_delivery_log`, `ai_whatsapp_inbox` (interno), `system_errors`, tokens e demais tabelas. Fila, realtime, workers e rotas ainda NÃO estão isolados.

**Resultado da validação B1.2a:** 2026-10-17, testes aprovados em MySQL 8 e MariaDB 10.11. Commit com testes de escopo A/B: `de38f9f44a2b`; commits subsequentes alteraram somente documentação. O PR #113 permanece em rascunho e a base da VPS não foi migrada.

**Comandos de homologação B1.2a, apenas após backup real validado e fundação B1.1 aplicada:**

```bash
php bin/migrate-saas-domain.php --dry-run --backup-manifest=/CAMINHO/VALIDADO/manifest.txt
php bin/migrate-saas-domain.php --apply --backup-manifest=/CAMINHO/VALIDADO/manifest.txt
```

**Retomada:** B1.2b está implementado na branch, com CI aprovado. Próximo trabalho: B1.2c — autorização por conta em todos os módulos, com testes agressivos contra vazamento.

## B1.2b — ownership por dependência em 16 tabelas filhas

**Estado:** B1.2b implementado e validado em CI; não implantado. [Execução #39032439643](https://github.com/vitoryvensreis/task/actions/runs/39032439643): PHP/JS, MySQL 8, MariaDB 10.11 aprovados; deploy ignorado. Sem ativar contas externas.

### Entregue

- [x] `app/SaasChildMigration.php` define a sequência determinística para `contract_items`, `cycles`, `cycle_items`, `demand_usage`, `transitions`, `comments`, `checklists`, `time_entries`, `files`, `reviews`, `change_requests`, `costs`, `payments`, `events`, `automation_runs` e `automation_run_steps`.
- [x] `database/schema.sql` contém `tenant_account_id BIGINT UNSIGNED NULL` com índices `(tenant_account_id,id)` nas 16 tabelas, além das 15 raízes do B1.2a.
- [x] A propriedade é derivada do pai: pagamentos → cobrança; arquivos → task; avaliações → arquivo; ciclos → contrato; eventos → calendário; execuções → automação.
- [x] Validação de relacionamento adicional quando houver dois pais: `contract_items.category_id`, `cycle_items.category_id`, `demand_usage.cycle_item_id`, `files.drive_account_id`, `payments.refund_of` e `events.demand_id`. Inconsistências bloqueiam a migração, não são sobrescritas.
- [x] `app/AccountOwnership.php` aceita as 16 tabelas adicionais para consultas protegidas por vínculo ativo (esta classe ainda NÃO está ligada aos controllers/rotas existentes).
- [x] `bin/migrate-saas-children.php` é opt-in, roda fora de transação, exige manifesto de backup, SHA256 válido, schema 30, B1.1 e B1.2a aplicados, única conta interna e flags SaaS desligadas.
- [x] `tests/saas-children.php` testa backfill e preservação de IDs, relações multiparentais, rejeição do schema 20, prevenção de migração concorrente/parcial e duas empresas com leitura cruzada negada.
- [x] `.github/workflows/deploy.yml` executa a suíte nos bancos de testes; **não há acionamento do CLI pelo deploy**, nem acesso de produção nesse PR.

**Marcador isolado:** `settings.saas_children_version = 3`, mantendo `settings.schema_version = 30`. Migração idempotente e DDL aditivo, sem remoção de dados. As colunas continuam NULLABLE enquanto o código legado ainda criar registros sem ownership. Não executar rollout produtivo antes da conclusão do B1.2c.

**Ordem de execução manual FUTURA, apenas com backup validado e homologação aprovada:**

```bash
php bin/migrate-saas-foundation.php --apply --backup-manifest=/CAMINHO/VALIDADO/manifest.txt
php bin/migrate-saas-domain.php --apply --backup-manifest=/CAMINHO/VALIDADO/manifest.txt
php bin/migrate-saas-children.php --dry-run --backup-manifest=/CAMINHO/VALIDADO/manifest.txt
php bin/migrate-saas-children.php --apply --backup-manifest=/CAMINHO/VALIDADO/manifest.txt
```

**Pendente para B1.2c e B1.3:** ownership e isolamento de `chat_threads`, `chat_messages`, `chat_message_tasks`, `notifications`, `audit_log`, `event_outbox`, `whatsapp_messages`, `mail_queue`, `push_subscriptions`, `push_delivery_log`, `ai_whatsapp_inbox` (apenas conta interna), `system_errors`, tokens/OAuth, idempotência, logs e demais tabelas sem pai único confiável. Migrar contas/fluxos desses dados com regras explícitas, não adivinhando empresa. Ajustar todas as escritas (inclusive inserts gerados por cron, API e workers), índices únicos por empresa, FKs compostas, autenticação e autorização por requisição, realtime, downloads, credenciais de integrações, relatórios e pagamentos.

**Riscos conhecidos:** `calendars.account_id` continua referenciando `google_accounts.id`. Os campos novos usam `tenant_account_id`. IDs de entidades não são prova de autorização. `SaasChildMigration::relationIssues` é um verificador de consistência/bloqueio e não substitui autorização em endpoints. Assinantes externos continuam bloqueados.

**Ponto exato de retomada (CI verde):** B1.2c — planejar e migrar as entidades restantes (sem pai seguro), propagar ownership de toda inserção, integrar `AccountContext` e `AccountOwnership` às rotas/serviços e aos processos assíncronos; testar acesso cruzado A/B antes de ativar clientes SaaS.

## B1.2c-1 — Contexto HTTP e leitura isolada (WIP, branch do PR #113)

**Implementado em código, não publicado:** 
- `app/SaasRequestScope.php` exige os três marcadores válidos (`saas_foundation_version=1`, `saas_domain_version=2`, `saas_children_version=3`). Nenhuma rota SaaS funciona se faltar qualquer etapa. Resolve o usuário autenticado e valida o vínculo da sessão em **todas as requisições**; não aceita `account_id` enviado em chamadas de listagem como prova de permissão. Contas externas são **sempre negadas por código nesta etapa**, mesmo que alguém force `saas.multi_account_enabled=1` e `saas.external_signup_enabled=1` na tabela. Apenas a operação interna legada é aceita; o desbloqueio real exigirá B1.3 auditado.
- `public/api.php`: novas ações experimentais `saas.context`, `saas.account_select` (POST com CSRF do middleware existente), `saas.records` e `saas.record`. Nenhuma ação legada foi substituída.
- `app/SaasReadApi.php` permite APENAS consultas de clientes, projetos e tarefas, com projeções de colunas limitadas e escopo SQL obrigatório `tenant_account_id=?`. Projetos exigem cliente proprietário; tasks exigem cliente, categoria, etapa e projeto (quando existir) pertencentes à mesma conta. Não inclui dados financeiros, chaves, tokens, briefs ou anexos brutos.
- Acesso restrito ao ADM com vínculo `owner`, previamente autenticado. Tokens de API antigos, sem empresa associada, **são rejeitados** em todas as novas ações SaaS.
- O teste `tests/saas-children.php` valida leitura da operação interna, IDs de outra empresa, seleção de conta não autorizada, vínculo suspenso, tokens antigos e migração incompleta. Também cobre conta externa com flags forçadamente habilitadas, membership owner e usuário admin, garantindo negação explícita.
- [x] [CI #39033292358](https://github.com/vitoryvensreis/task/actions/runs/39033292358) concluída com sucesso: PHP/JS, MySQL 8 e MariaDB 10.11; deploy `skipped`. Teste da seleção externa forjada validado.
- `README.md` contém lista de arquivos, progresso e próximo bloco. **CI aprovada (não implantado).**

**Limite de escopo:** essas rotas são a primeira base verificável de autenticação/SQL multiempresa. Elas NÃO corrigem as consultas globais das rotas antigas (`demands.list`, `clients.list`, `finance.*`, `files.*`, realtime, downloads, workers). O SaaS segue DESLIGADO, `main` e VPS não devem receber esta branch.

### Retomada exata B1.2c-2 (agora em B1.2c-2b após validar a primeira escrita)

1. Concluir definição de proprietário das tabelas restantes (chat, notificações, logs, outbox, filas, WhatsApp, AI interna, tokens e eventos sem relação determinística), com verificações de integridade.
2. Vincular `tenant_account_id` a cada criação, edição e exclusão de registros, com consistência de pais e autorização em todas as rotas existentes; evitar que uma escrita antiga gere registros com NULL.
3. Implementar novas credenciais/tokens vinculados à empresa, sessão multiempresa e contas de usuário sem depender do `users.role` global; revogar o contexto imediatamente após perda de membership.
4. Aplicar ownership ao armazenamento/URLs assinadas, uploads e downloads; aos workers, notificações, realtime/Centrifugo e Outbox. Fechar testes A/B reais incluindo IDs trocados, filas e mensagens.
5. Concluir índices únicos e FKs compostas por empresa, habilitar NOT NULL apenas depois do saneamento; testar rollback. Somente após tudo aprovado avançar para B1.3.

## B1.2c-2a — Primeira camada de escrita tenant-safe (em validação)

**Implementado e validado por CI na branch do PR #113; sem deployment na VPS.** Mantém o bloqueio total de empresas externas, mesmo se `account_settings` for alterada manualmente.

- [x] `app/SaasWriteApi.php`: `clientSave` (criação e edição de campos permitidos), `clientArchive` (soft delete apenas sem projetos, contratos, demandas, faturas, usuários vinculados ou templates recorrentes), `projectSave` (criação e edição com cliente verificado), `projectDelete` (exclusão física apenas de projeto sem tasks ou faturas).
- [x] Transações MySQL, validação de owner ativo e conta interna a **cada operação**, leitura `FOR UPDATE` e cláusula `tenant_account_id=?` em updates e deletes. A aplicação não aceita `tenant_account_id`, `account_id`, `created_by` ou `owner_id` de payload.
- [x] `public/api.php` recebe rotas `saas.client.save`, `saas.client.archive`, `saas.project.save`, `saas.project.delete`, todas exclusivamente POST e sob CSRF do middleware. Tokens bearer antigos sem tenant são negados.
- [x] Trilha de auditoria gerada em `audit_log` (legada). **Importante: o log ainda não possui tenant ownership e não pode ser compartilhado entre contas.** Planejar sua migração B1.2c-2b antes do uso externo.
- [x] Sem chamar `Catalog::clientSave` ou `Demands::stage` porque hoje esses métodos geram usuários, notificações, eventos, workers e outras operações sem tenant.
- [x] `tests/saas-writes.php` testa criação/edição, campos permitidos, validação de pai, exclusões sem dependências, A/B com IDs forjados, administradores sem owner, sessões revogadas, bearer antigo e migrações incompletas.
- [x] Teste conectado à matriz MySQL 8 / MariaDB 10.11. **CI aprovada:** [execução #39034250506](https://github.com/vitoryvensreis/task/actions/runs/39034250506), PHP/JS, MySQL 8 e MariaDB 10.11 verdes; deploy na VPS ignorado.

**Limites conscientes:** não são rotas do frontend normal nem substituem `clients.save`/`projects.save`. Não emitem automação, push, eventos realtime ou notificações. Não oferecem CRUD completo de tasks: a movimentação exige preservar `transitions`, `automation_runs`, `event_outbox` e outros efeitos laterais sob tenant. As contas comerciais externas continuam inacessíveis.

**Ponto exato de retomada após CI verde #39034250506 — B1.2c-2b:** identificar e associar owner de logs/chat/notifications/outbox/tokens/WhatsApp; autenticação por empresa, migrar endpoints legados módulo a módulo, implementar mutações de tasks com efeitos transacionais e não transacionais isolados; testar IDs misturados, escritas concorrentes, canais realtime, arquivos, relatórios e filas. Depois índices únicos por empresa, FKs compostas, NOT NULL e B1.3 homologação/ativação controlada.

## B1.2c-2b.1 — Tasks: mutações isoladas em estágio (WIP)

**Status:** código implementado e validado pela [CI #39035332655](https://github.com/vitoryvensreis/task/actions/runs/39035332655) (PHP/JS, MySQL 8, MariaDB 10.11 aprovados, deploy skipped). Nenhuma rota do Kanban legado ou da produção foi substituída. Empresas externas seguem bloqueadas diretamente no código de `SaasRequestScope`.

**Arquivos:** `app/SaasTaskWriteApi.php`, `public/api.php`, `tests/saas-task-writes.php`, `.github/workflows/deploy.yml`.

- [x] Rotas de sessão POST `saas.task.save`, `saas.task.move`, `saas.task.archive` sob CSRF, com `SaasRequestScope::owner()` verificando empresa e proprietários em todas as chamadas. Tokens antigos sem account não funcionam.
- [x] Criação/edição verifica cliente ativo, categoria ativa, projeto com mesmo cliente e empresa, etapa ativa e responsável com membership ativo (e com vínculo ao cliente para `staff`). O identificador `tenant_account_id` é aplicado pelo servidor, nunca aceito do payload.
- [x] Criação insere task e histórico de transição na mesma transação com `tenant_account_id`. Usa primeira etapa da própria conta. Defaults econômicos conservadores: valor 0, destino WhatsApp `none`, sem envio automático.
- [x] Edição exige `version` correspondente e mantém data em UTC inalterada quando omitida, sem alteração implícita de etapa ou preço.
- [x] Movimentação entre etapas com versionamento otimista e uma transição tenantizada; não reorganiza a ordem Kanban (reorder fica pendente). Mudança para concluída ajusta marcos de tempo, **mas ainda não emite automações/disparos**.
- [x] Arquivamento soft delete só sem faturas, custos, arquivos, consumo, eventos, execuções automatizadas, vínculos de chat ou disparos WhatsApp; dados históricos permanecem. Nenhum cascade automático entre módulos.
- [x] Lista de campos permitidos bloqueia alterações por payload de conta, preço, WhatsApp, templates, status financeiro, autores e outros campos ainda não isolados.
- [x] Testes (MySQL 8 + MariaDB 10.11) verificam contas A/B, acessos forjados, conflitos de versão, dependências, membro removido, token bearer antigo, migração ausente, transições com tenant e ausência de efeitos assíncronos. **CI aprovada, não implantado.**

**Limitação crítica:** `audit_log` legada ainda não possui tenant; as novas rotas também não integram `AutomationEvent`, `DemandRealtime`, `Notify`, financeiro, upload, Google Drive ou o SaaS comercial. Essa implementação serve somente como base de segurança em ambiente não produtivo. **NÃO liberar outras empresas ou trocar a navegação Kanban por estas rotas.**

**Retomada exata:** B1.2c-2b.1 testado e aprovado; continuar **B1.2c-2b.2** com isolamento da Outbox/eventos, fila de WhatsApp, cron/workers, notificações e realtime; depois CRUD legado por tenant, uploads/downloads e demais tabelas/logs. Revisar concorrência e índices únicos por empresa e FKs compostas antes de B1.3.

## B1.2c-2b.2a — Outbox transacional com ownership (CI aprovada, SEM publicação)

**Objetivo:** garantir que a mutação de uma task e seu evento persistam atomicamente, associados à empresa correta, **sem expor eventos ao trabalhador legado que consome `event_outbox` globalmente.** Arquitetura deliberada: duas filas independentes durante a transição.

**Validação final:** [GitHub Actions #39039309891](https://github.com/vitoryvensreis/task/actions/runs/39039309891) — código PHP/JavaScript, MySQL 8 e MariaDB 10.11 aprovados. Deploy VPS `skipped`. O PR permanece em rascunho; testes não representam homologação de event consumers/WhatsApp/realtime.

### Implementação nesta etapa
- [x] `database/migrations/032_saas_event_outbox.sql` e `database/schema.sql`: nova `saas_event_outbox` com `tenant_account_id NOT NULL`, FK de conta/ator, chave única tenant + event_key, payload JSON, data disponível, tentativas e índices adequados para inspeção futura por empresa. O código histórico do SaaS schema 20 continua removido.
- [x] `app/SaasOutboxMigration.php` aplica DDL de forma idempotente com MySQL advisory lock. Requer schema operacional 30, marcadores B1.1=1, B1.2a=2, B1.2b=3, única conta interna, flags `saas.external_signup_enabled=0` e `saas.multi_account_enabled=0`. Inspeciona tipos, índices e FKs. **Não altera `settings.schema_version`**, usa `settings.saas_outbox_version=4`.
- [x] `bin/migrate-saas-outbox.php`: CLI exclusivamente manual com `--dry-run` / `--apply`, manifesto e dump de backup íntegro SHA256; **não** incluído no deploy automático.
- [x] `app/SaasEventOutbox.php`: staging de eventos `demand.created`, `demand.updated`, `demand.stage_changed`, `demand.archived` dentro da transação MySQL existente; valida `Auth::$actor`, vínculo com conta, tipo permitido, proprietário e versão atual da task. Identificação idempotente SHA256 por empresa/evento/task/versão, hash que identifica conflito; só copia metadados de IDs e etapas do servidor (nenhum briefing, número de WhatsApp, dados financeiros, URLs ou chaves).
- [x] `app/SaasTaskWriteApi.php`: criação, edição, movimentação e arquivamento chamam a nova fila **antes do commit**. Se o evento falhar, também falha/rollback a alteração da task. Histórico `transitions` segue no mesmo escopo.
- [x] `tests/saas-task-writes.php`: migração em banco legado simulado (retirada da tabela staging no banco de teste), reexecução idempotente, duplicata do mesmo evento sem duplicação, rollback de negócio+evento, contagem por tenant, bloqueio de publisher, filtro de payload sensível e confirmação de que `event_outbox` legado não recebeu eventos.
- [x] `tests/saas-foundation.php`: ao simular banco anterior ao B1, remove primeiro `saas_event_outbox`, respeitando a FK account introduzida na instalação limpa.

### Bloqueio intencional de efeitos externos

`saas_event_outbox` **não é consumida por nenhum worker existente.** `AutomationWorker` ainda faz busca global em `automations` e `event_outbox`. `RealtimeOutboxWorker`, notificações, WhatsApp, push e Centrifugo ainda não verificam completamente tenant ownership. Publicar ou consumir os novos eventos nesses workers seria inseguro. Portanto, **nenhum disparo, broadcast ou automação é feito por esta etapa**. O valor entregue é a infraestrutura de evento durável e transacional, para liberar consumidores isolados mais tarde.

### Ponto de retomada — B1.2c-2b.2b

1. CI B1.2c-2b.2a confirmada (run #39039309891). Iniciar consumidores/filas `tenant_account_id` próprios, sem ativar nenhum publisher legado.
2. Migrar ownership e dependências restantes de `audit_log`, `notifications`, `push_delivery_log`, `whatsapp_messages`, `chat_*`, logs de worker e tokens; validar orfandade e FKs.
3. Desenvolver **consumidores novos e separados**, obrigando `tenant_account_id` também nas consultas de automações, runs, envio, templates de conteúdo, destinatários e canais de realtime. Não encaminhar eventos SaaS aos workers antigos.
4. Testar empresas A/B com URLs, canais, subscribers, credenciais, tentativa de replay e eventos atrasados; atestar ausência de mensagens ou arquivos cruzados. Implementar política segura de retry/idempotência.
5. Somente após a separação funcional, conectar o Kanban/CRUD do SaaS ao fluxo isolado e executar B1.3. Manter o TaskEsc legado em pleno funcionamento.

**Regra principal:** não mesclar esta branch, habilitar outra empresa ou executar migração na VPS antes de terminar isolamento/CI e preparar rollback testado.

## Pendente — B1.2: propagação e isolamento por conta

- [ ] Completar `tenant_account_id` (B1.2a cobre 15 raízes, demais tabelas e consultas pendentes), priorizar `clients`, `users`/vínculos, `demands`, `projects`, `stages`, `categories`, `contracts`, `invoices`, `payments`, `expenses`, `files`, `templates`, `automations`, `automation_runs`, `event_outbox`, `whatsapp_contacts`, mensagens/chat, agendas, Google, tokens e logs. O inventário precisa ser completado por relacionamento.
- [ ] Planejar índices únicos **por conta**, incluindo etapas/categorias/contatos, e FKs/joins contra relações de contas diferentes; detectar dados legados órfãos antes de tornar colunas NOT NULL.
- [ ] Adicionar resolução de conta na autenticação e nas sessões/API tokens; seleção explícita para usuários que participem de várias empresas e verificação por requisição. Identificador enviado pelo browser **não prova autorização**.
- [ ] Exigir `account_id` verificado para cada ação em `Auth`, `Catalog`, `Demands`, `Finance`, `Files`, `Reports`, `Agenda`, `Google`, `Chat`, `WhatsappContacts`, `Automation`, APIs e MCP.
- [ ] Isolar canais realtime/Centrifugo, Outbox, notificações, upload/download e workers (inclusive automações agendadas, fila WhatsApp, credenciais e respostas do Google).
- [ ] Retirar do produto vendido integrações Google Calendar e assistente IA **no servidor**, preservando-os somente para a conta interna; manter os disparos por automações.
- [ ] Cobrir testes adversariais entre conta A/B (IDs cruzados, URLs de download, filtros, arquivos, logs, relatórios, automações, eventos, filas, WebSocket).
- [ ] Depois de aprovado o isolamento, adicionar fluxo de provisionamento seguro e interface para administração de empresas, com limite de usuários provisionáveis (planos e cobrança ficam nos Blocos 4/5).
- [ ] Atualizar CLI de migração/deploy com backup e rollback documentados **antes** de habilitar o modo multitenant. Somente então considerar avançar a versão do schema de produção.

## Pendente — B1.3: ativação controlada

- [ ] Testes end-to-end no ambiente de homologação com ao menos duas empresas e usuários distintos.
- [ ] Migração da base existente após snapshot testado; plano de rollback dos dados/código; auditoria das consultas por conta.
- [ ] Ligar `saas.multi_account_enabled` **somente** quando todos os módulos estiverem protegidos e as verificações passarem.
- [ ] Confirmar que o Master ADM não herda acesso irrestrito aos dados privados de cada empresa.

## Como executar a migração da fundação, QUANDO autorizado

O comando abaixo **não** faz parte do deploy comum nem deve ser executado sem um backup completo validado do banco em uso. Uma nova instalação inclui as tabelas, mas requer a criação da conta interna/vínculos por essa migração antes de ativar a resolução multitenant.

```bash
php bin/migrate-saas-foundation.php --dry-run --backup-manifest=/CAMINHO/VALIDADO/manifest.txt
php bin/migrate-saas-foundation.php --apply --backup-manifest=/CAMINHO/VALIDADO/manifest.txt
```

O manifesto precisa ser o do backup pré-deploy, em `storage/backups/`, com referência ao arquivo de dump e SHA256 corretos. Ao repetir `--apply`, a migração deve encontrar as mesmas tabelas/conta e não duplicar vínculos.

**Nenhum dado de clientes ou tasks é alterado pela etapa B1.1.** Novos usuários criados depois da migração terão de ser associados por uma futura camada de provisionamento; até lá, o serviço `AccountContext` não é usado no login existente. Isso é proposital para evitar um rollout parcial inseguro.

## Ponto exato de retomada

1. PR #113 e CI #39030497373 aprovados na fase B1.1; não mesclar com `main` nem executar o CLI em produção sem homologação e decisão explícita de rollout.
2. CI B1.2b validado (#39032439643). Iniciar o **B1.2c pelos dados sem pai confiável e isolamento dos controladores**, sem tocar no schema 20.
3. Continuar B1.2c: integrar `AccountOwnership` e `AccountContext` às APIs de cada módulo e a todos os jobs em lotes pequenos; fechar o teste A/B para cada módulo.
4. Atualizar este README e este arquivo com commits, verificações concluídas, bloqueios e o próximo trabalho pendente.

### Regras de segurança

- Nunca usar `client_id` como identificador do assinante.
- Nunca cadastrar/permitir acesso a uma nova empresa enquanto existir qualquer rota global sem isolamento.
- Nunca apenas esconder no frontend os recursos IA e Calendar integrado.
- Nunca confiar em `account_id` proveniente de URL, request, webhook, token ou evento sem autenticação/autorização.
- Não misturar financeiro operacional dos assinantes com o faturamento SaaS/Asaas.
