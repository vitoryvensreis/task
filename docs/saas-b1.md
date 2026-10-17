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

**Retomada:** B1.2b está implementado na branch (CI a confirmar). Depois, B1.2c — autorização por conta em todos os módulos, com testes agressivos contra vazamento.

## B1.2b — ownership por dependência em 16 tabelas filhas

**Estado:** implementação inicial na branch `feat/saas-b1-foundation`, sem deploy e sem ativar contas externas. Testes da matriz MySQL/MariaDB em execução; registrar o link CI aprovado antes de dar como validada.

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

**Ponto de retomada após CI verde:** B1.2c — planejar e migrar as entidades restantes (sem pai seguro), propagar ownership de toda inserção, integrar `AccountContext` e `AccountOwnership` às rotas/serviços e aos processos assíncronos; testar acesso cruzado A/B antes de ativar clientes SaaS.

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
2. Confirmar CI do **B1.2b**; continuar o **B1.2c pelos dados sem pai confiável e isolamento dos controladores**, sem tocar no schema 20.
3. Continuar B1.2c: integrar `AccountOwnership` e `AccountContext` às APIs de cada módulo e a todos os jobs em lotes pequenos; fechar o teste A/B para cada módulo.
4. Atualizar este README e este arquivo com commits, verificações concluídas, bloqueios e o próximo trabalho pendente.

### Regras de segurança

- Nunca usar `client_id` como identificador do assinante.
- Nunca cadastrar/permitir acesso a uma nova empresa enquanto existir qualquer rota global sem isolamento.
- Nunca apenas esconder no frontend os recursos IA e Calendar integrado.
- Nunca confiar em `account_id` proveniente de URL, request, webhook, token ou evento sem autenticação/autorização.
- Não misturar financeiro operacional dos assinantes com o faturamento SaaS/Asaas.
