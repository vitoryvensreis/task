# TaskEsc — gestão de jobs, tarefas, agenda e arquivos

> **Estado mais recente (17/10/2026):** a fundação inativa da PR #113 foi integrada à `main` e publicada com sucesso na VPS pelo [deploy #39070465317](https://github.com/vitoryvensreis/task/actions/runs/39070465317). A PR #114 acrescenta bloqueio fail-closed para APIs e workers legados antes de existir qualquer conta externa. Seu pipeline prepara também migrações in-place 031–036 sob backup SHA256 recente, preflight e flags externas desativadas. **O Bloco 1 completo e a operação com várias empresas ainda NÃO estão liberados.** Informações de status anteriores abaixo são registros históricos.

## SaaS — Bloco 1 (fundação multiempresa) — diário de desenvolvimento

**Última atualização:** 17/10/2026 · **Branch:** `feat/saas-b1-foundation` · **PR:** [#113](https://github.com/vitoryvensreis/task/pull/113) (rascunho) · **Estado:** B1.1 e B1.2a validados; B1.2b validado; B1.2c-1 validado; B1.2c-2a validado; B1.2c-2b.1 validado; B1.2c-2b.2a validado; B1.2c-2b.2b.1 validado; B1.2c-2b.2b.2a validado; B1.2c-2b.2b.2b.1 validado; B1.2c-2b.2b.2b.2a cofre de canais validado em CI; caixa interna B1.2c-2b.2b.2b.2b.1 validada em CI, workers/envios externos B1.2c-2b.2b.2b.2b.2/B1.3 pendentes. **Não liberado em produção.**

**Implantação decidida:** mesma VPS, mesmo site `task.escotilhaprodutora.com` e mesmo banco de dados em uso; **sem subdomínio/banco novos**. Esta decisão exige rollout gradual e aditivo, *sem ativar outras empresas*. **CI da auditoria:** [GitHub Actions #39068616079](https://github.com/vitoryvensreis/task/actions/runs/39068616079) — PHP/JS, MySQL 8 e MariaDB 10.11 aprovados; deploy skipped. Nova auditoria **somente de leitura** em `app/SaasInPlacePreflight.php` / `bin/saas-inplace-preflight.php`, com bloqueios para schema diferente de 30, versão SaaS 20 antiga, marcadores divergentes, flags habilitadas e contas externas. `--release-check` só passa com backup SQL SHA256 verificado gerado nas últimas 2 horas. O preflight não executa migração, backup nem deploy. [Plano de rollout na instalação existente](docs/saas-deploy-inplace.md) substitui a proposta anterior de staging com outro banco. **Nenhuma atualização na VPS foi feita nesta rodada.**

**Documento de continuidade detalhado:** [docs/saas-b1.md](docs/saas-b1.md). Ao retomar o projeto, consulte este bloco e o documento ANTES de qualquer migração.

| Etapa | Situação | Resultado / próximo passo |
| --- | --- | --- |
| B1.1 — tabelas de contas e vínculo de usuários | ✅ Implementado e validado na branch | `accounts`, `account_members`, `account_settings`; conta interna para legado; migração idempotente com backup obrigatório, modo multiempresa bloqueado |
| B1.1 — proteção inicial e verificações | ✅ Código e testes aprovados na CI | `AccountContext` valida o vínculo; testes no MySQL/MariaDB; NÃO conectado a todas as rotas ainda |
| B1.2a — `tenant_account_id` em 15 tabelas principais | ✅ Implementado e CI aprovada, NÃO implantado | Migração aditiva e backfill testados em MySQL 8/MariaDB 10.11; rotas legadas ainda sem isolamento |
| B1.2b — ownership em 16 tabelas filhas | ✅ CI aprovada, NÃO implantado | Backfill por FK e checagens multiparentais aprovados no MySQL 8/MariaDB 10.11; ainda sem isolamento operacional |
| B1.2c-1 — contexto de requisição + leituras isoladas | ✅ CI aprovada, NÃO implantado | Contexto validado por sessão; seleção CSRF; clientes/projetos/tasks filtrados; tokens antigos e empresas externas bloqueados |
| B1.2c-2a — escritas seguras de clientes/projetos | ✅ CI aprovada, NÃO implantado | Rotas POST com escopo por conta, transações, arquivamento condicionado (inclusive templates recorrentes) e exclusão de projeto vazio; CRUD legado permanece |
| B1.2c-2b.1 — escrita isolada de tasks | ✅ CI aprovada, NÃO implantado | Rotas POST criar/editar/mover/arquivar, versão e transições por conta; sem disparos/efeitos assíncronos |
| B1.2c-2b.2a — Outbox SaaS isolada e transacional | ✅ CI aprovada, NÃO implantado | Fila separada com tenant obrigatório, migração manual com backup, eventos atômicos por task; **nenhum consumidor/publicador ativo** |
| B1.2c-2b.2b.1 — preparação de eventos por empresa | ✅ CI aprovada, NÃO implantado | Recibos únicos retidos por evento+empresa, hash canônico, vínculos, isolamento A/B e zero publicação |
| B1.2c-2b.2b.2a — fila de intenção de entrega por empresa | ✅ CI aprovada, NÃO implantado | Jobs únicos por empresa/recibo/canal interno, destino calculado no servidor, CAS/backoff e DLQ após 5 falhas; **sem envio** |
| B1.2c-2b.2b.2b.1 — roteamento interno de destinatários | ✅ Testes aprovados, NÃO implantado | Resolução por membership ativo, equipe e cliente da task, bloqueio de canal alterado, conta suspensa e outra empresa; nenhum envio |
| B1.2c-2b.2b.2b.2a — cofre isolado de credenciais | ✅ CI aprovada, NÃO implantado | Tokens por conta/canal criptografados AES-256-GCM; AAD por empresa/provedor/revisão, rotação, acesso somente owner; provedores DESLIGADOS |
| B1.2c-2b.2b.2b.2b.1 — caixa interna com isolamento | ✅ Implementada e CI aprovada, NÃO implantada | `saas_internal_inbox` por empresa/job/usuário, duplicação bloqueada, ACL validada a cada leitura, rota `saas.inbox` sem PII; nenhum envio externo |
| B1.2c-2b.2b.2b.2b.2 — workers e envios externos | ⬜ Pendente | Entrega automatizada interna, endpoints/credenciais por empresa, automações, WhatsApp, push, realtime, retry com conciliação e testes A/B |
| B1.2 — restrições de recursos do SaaS | ⬜ Pendente | Desabilitar IA e calendários Google integrados para assinantes no servidor; manter disparos de automação |
| B1.3 — ativação, homologação e migração segura | ⬜ Pendente | 2 empresas em testes; deploy com backup/rollback; habilitar contas externas só após isolamento completo |
| Bloco 2 — cargos e permissões personalizadas | ⬜ Próximo bloco | Iniciar somente após base multiempresa confiável |
| Blocos 4/5 — planos, licenças avulsas, Asaas | ⬜ Futuro | Não incluídos no Bloco 1 |

**B1.2a validado:** [GitHub Actions #39031554658](https://github.com/vitoryvensreis/task/actions/runs/39031554658) — validação PHP/JS, MySQL 8 e MariaDB 10.11 aprovadas; **deploy skipped**. As correções de fixture (created_at de projetos e membership revogado no teste A/B) já estão incluídas. Nenhuma alteração foi aplicada à VPS.

**B1.2b validado:** [GitHub Actions #39032439643](https://github.com/vitoryvensreis/task/actions/runs/39032439643) — PHP/JavaScript, MySQL 8 e MariaDB 10.11 aprovados; deploy na VPS `skipped`. A etapa **não** permite novas empresas nem elimina a necessidade das verificações nas rotas existentes.

**B1.2c-1 validado:** [GitHub Actions #39033292358](https://github.com/vitoryvensreis/task/actions/runs/39033292358): código PHP/JS, MySQL 8, MariaDB 10.11 aprovados; deploy na VPS ignorado. Os testes cobrem seleção forjada, membership revogado, tokens de API antigos e flags externas alteradas manualmente. **Ainda não altera nenhuma rota legada nem libera multiempresa.**

**B1.2c-2a validado:** [GitHub Actions #39034250506](https://github.com/vitoryvensreis/task/actions/runs/39034250506) — sintaxe PHP/JS, MySQL 8 e MariaDB 10.11 aprovados, deploy na VPS `skipped`. Testa contas A/B, IDs forjados, dono sem permissão, token sem tenant, membership revogado, migração ausente, cliente com template recorrente, projetos com tasks e exclusões restritas. Nenhum dado de produção foi alterado.

**B1.2c-2a (implementado e CI aprovada):** `app/SaasWriteApi.php` adiciona `clientSave`, `clientArchive`, `projectSave`, `projectDelete` exclusivamente à conta interna verificada, com transação e cláusula de posse também em `UPDATE`/`DELETE`. Em `public/api.php`, rotas `saas.client.save`, `saas.client.archive`, `saas.project.save`, `saas.project.delete` exigem POST; a proteção CSRF já existente no middleware é aplicada a solicitações de sessão, enquanto bearer tokens sem tenant continuam rejeitados. Edições proíbem mudar projeto com tasks/cobranças e excluem apenas registros sem dependências; arquivamento de clientes não altera usuários/vínculos existentes. Mantém trilha `audit_log` do legado (**ainda não isolada por tenant**). Não dispara notificações nem automações até workers/filas estarem isolados. Testes em `tests/saas-writes.php` na matriz MySQL/MariaDB. **Não implantado, não pronto para contas externas.**

**B1.2c-2b.1 validado:** [GitHub Actions #39035332655](https://github.com/vitoryvensreis/task/actions/runs/39035332655) — testes PHP/JS, MySQL 8 e MariaDB 10.11 aprovados; deploy ignorado. Casos: pais/atores de empresas diferentes, concorrência por versão, transições próprias, arquivamento conservador e ausência de disparos assíncronos.

**B1.2c-2b.1 (implementado e CI aprovada):** `app/SaasTaskWriteApi.php`, `tests/saas-task-writes.php`, `public/api.php` e CI. Rotas experimentais POST: `saas.task.save`, `saas.task.move`, `saas.task.archive`. Validação de account/session/owner em cada chamada; filtro por conta em `demands`, cliente, projeto, categoria, etapa, responsável e UPDATE. Controle de versão para evitar sobrescrita, transições criadas com `tenant_account_id`. Não aceita campos de preço, WhatsApp, automação, template nem `tenant_account_id` em payloads. Arquivamento é soft delete e bloqueia registros ligados a finanças, arquivos, eventos, automações, chat e WhatsApp. **Não foram ativados os disparos, notificações ou realtime por ainda serem globais**; o `audit_log` segue legado e NÃO é apropriado para outras empresas. Código só para a conta interna; não altera rotas antigas. **CI aguardada, VPS intacta.**

**CI B1.2c-2b.2a:** [GitHub Actions #39039309891](https://github.com/vitoryvensreis/task/actions/runs/39039309891) — PHP/JavaScript, MySQL 8 e MariaDB 10.11 aprovados; deploy na VPS ignorado. Testes verificam migração, idempotência, rollback do negócio+evento, fila legada intacta e ausência de qualquer publicação externa.

**B1.2c-2b.2a (fila transacional isolada — CI aprovada):** criada `saas_event_outbox` em `database/migrations/032_saas_event_outbox.sql` e `database/schema.sql`, com `tenant_account_id BIGINT UNSIGNED NOT NULL`, índice único `(tenant_account_id,event_key)`, índices para inspeção por empresa, FKs e payload JSON. `app/SaasOutboxMigration.php` exige as três migrações anteriores, schema operacional 30, conta interna única e acesso externo desligado; marcador separado `settings.saas_outbox_version=4`. `bin/migrate-saas-outbox.php` roda somente manualmente com dump/manifesto SHA256 verificado. `app/SaasEventOutbox.php` grava **um evento idempotente por empresa/tipo/task/versão** na mesma transação MySQL da criação, edição, movimentação e arquivamento em `app/SaasTaskWriteApi.php`; valida ownership e versionamento da task, e restringe payload a IDs sem briefing, tokens, valores financeiros ou telefones. Suíte `tests/saas-task-writes.php` testa idempotência, rollback atômico, ausência de publicação e não ingestão na fila antiga. **A tabela `saas_event_outbox` é separada de `event_outbox`; AutomationWorker, RealtimeOutboxWorker, WhatsApp e notificações NÃO A CONSOMEM. Portanto, nenhum disparo acontece nesta etapa.** Migração não incluída no deploy automático, empresa externa segue bloqueada.

**Validação B1.2c-2b.2b.1:** [GitHub Actions #39053069659](https://github.com/vitoryvensreis/task/actions/runs/39053069659): código PHP/JS, MySQL 8, MariaDB 10.11 aprovados; deploy na VPS `skipped`. Testes de hash JSON normalizado em ambos os bancos, sessões, A/B, reprocessamento idempotente, evento alterado e ausência de publicação.

**B1.2c-2b.2b.1 (recibos retidos de eventos — CI aprovada):** `database/migrations/033_saas_event_receipts.sql` e `database/schema.sql` criam `saas_event_receipts` com `tenant_account_id NOT NULL`, chave única por conta+evento, FKs e estado `held`. `app/SaasReceiptMigration.php` valida schema/indexação/FKs e exige B1.1/B1.2a/B1.2b/Outbox (marcador próprio `saas_receipts_version=5`). `bin/migrate-saas-receipts.php` roda somente manualmente com backup SQL e SHA256 verificados, nunca pelo deploy. `app/SaasEventConsumer.php` é um pré-consumidor executado somente em testes nesta etapa: lê evento da **empresa interna verificada**, valida hash de payload canonizado (compatível MySQL 8/MariaDB), categoria, cliente, projeto, etapa e task, cria recibos `held` atômicos, idempotentes, sem copiar briefing/segredos. **Não marca `published_at`, não reprocessa ou publica mensagens, não aciona automações/WhatsApp/realtime e não integra cron nem API.** Teste A/B rejeita empresa externa, verifica reexecuções e eventos adulterados. Aprovado em MySQL 8/MariaDB 10.11. Produção inalterada.

**Validação B1.2c-2b.2b.2a:** [GitHub Actions #39054715478](https://github.com/vitoryvensreis/task/actions/runs/39054715478) — PHP/JS, MySQL 8 e MariaDB 10.11 aprovados, deploy na VPS `skipped`. O workflow fornece ZIP completo do código como artefato `taskesc-realtime-full`, com expiração de 7 dias; o PR segue rascunho.

**B1.2c-2b.2b.2a — fila de entregas retidas (CI aprovada):** `database/migrations/034_saas_delivery_jobs.sql` e `database/schema.sql` criam `saas_delivery_jobs` com `tenant_account_id NOT NULL`, FK para `accounts` e `saas_event_receipts`, UNIQUE `(tenant_account_id,receipt_id,channel)`, `status` (`held/retry/dead`), `attempts`, `available_at`, `last_error` e índices por conta. `app/SaasDeliveryMigration.php` exige migrações anteriores (marcadores 1–5) e cria marcador próprio `saas_delivery_version=6`; `bin/migrate-saas-delivery.php` é manual e exige backup/manifesto SHA256. `app/SaasDeliveryQueue.php` cria apenas jobs `internal_event` com destino derivado `saas.account.<id>.events` após verificar origem, recibo, tarefa, hash JSON canônico e empresa; executa tudo sob lock/transação. Deduplicação idempotente, `recordFailure` CAS com backoff 30s/60s/120s/240s, morte após 5 falhas e prévia sem payload sensível. `tests/saas-task-writes.php` inclui testes A/B, repetição, adulteração do recibo, tentativa prematura, atualização obsoleta e DLQ. A fixture `tests/saas-foundation.php` inclui a FK nova. **Não há worker/cron conectado à fila, nem método de enviar ou marcar como entregue. Falhas simuladas/validação NÃO representam tentativa de envio.** O ZIP completo é gerado como artefato `taskesc-realtime-full` no workflow do PR. **Não fazer merge/deploy nem ativar SaaS neste estágio.**

**CI B1.2c-2b.2b.2b.1:** [GitHub Actions #39062191994](https://github.com/vitoryvensreis/task/actions/runs/39062191994) — PHP/JS, MySQL 8 e MariaDB 10.11 aprovados; deploy na VPS `skipped`. Testes de revogação, cliente, conta suspensa, A/B, job `dead`, canal adulterado e nenhuma publicação.

**B1.2c-2b.2b.2b.1 (roteamento interno sem transporte; CI aprovada):** `app/SaasDeliveryRouting.php` resolve exclusivamente destinatários **internos** de um `saas_delivery_jobs` já validado, a partir dos membros ativos da conta interna: administradores proprietários e responsável ativo da task com acesso ao cliente. Relaciona `saas_delivery_jobs` → `saas_event_receipts` → `saas_event_outbox` → `demands` sempre por `tenant_account_id`, verifica integridade/hash, canal fixo `internal_event`, destino `saas.account.<ID>.events`, vínculo/membership atuais e conta ativa. Não lê contatos WhatsApp, telefones, e-mails, chaves de push ou segredos. Ao remover membership, vinculação ao cliente, ou suspender a empresa, o usuário deixa de ser elegível. Testes adicionais em `tests/saas-task-writes.php` exercitam A/B, conta suspensa, revogação, canal adulterado e DLQ. **O retorno é apenas uma decisão em memória, NÃO mensagem enviada, publicação ou job com destinatário persistido.** O sistema legado, workers e produção permanecem intactos.

**Validação B1.2c-2b.2b.2b.2a:** [GitHub Actions #39063150470](https://github.com/vitoryvensreis/task/actions/runs/39063150470) — código PHP/JS, MySQL 8 e MariaDB 10.11 aprovados, deploy na VPS ignorado. Testes de criptografia/rotação/adulteração, tokens antigos, A/B e ausência de envios. Nenhuma credencial real foi usada.

**B1.2c-2b.2b.2b.2a — cofre de canais (CI aprovada):** `database/migrations/035_saas_channel_secrets.sql` e `database/schema.sql` criam `saas_channel_secrets` com `tenant_account_id NOT NULL`, `UNIQUE(tenant_account_id,channel)`, FKs para contas/autor, status `disabled` e revisão. `app/SaasChannelMigration.php` verifica tabela/índices/FKs, schema operacional 30, marcadores 1–6 e única conta interna; salva `settings.saas_channels_version=7` sem alterar o schema 30. CLI manual `bin/migrate-saas-channels.php` requer backup e SHA256 verificados e NÃO está conectado ao deploy. `app/SaasChannelVault.php` mantém lista fixa de canais/provedores (WhatsApp/WPPConnect, e-mail/SMTP, push/WebPush e realtime/Centrifugo), exige sessão web de administrador owner, rejeita contas externas, criptografa tokens com AES-256-GCM e AAD vinculada a conta/canal/provedor/revisão e executa rotação atômica mantendo `disabled`. Usa chave derivada do `app_key`, nunca retorna plaintext/ciphertext/fingerprint nos metadados, nem possui função para enviar ou extrair credenciais para provedores. Em `tests/saas-task-writes.php`, cenários de rotação, adulteração de revisão, cópia de token entre canais, tokens antigos e usuário externo; `tests/saas-foundation.php` atualizado para FK. **NÃO inserir tokens reais até haver revisão e habilitação controlada do serviço. Nenhum envio ativo e produção inalterada.**

**B1.2c-2b.2b.2b.2b.1 — caixa de entrada interna tenant-safe:** migration SQL `036_saas_internal_inbox.sql` em `database/migrations/`, atualizada em `database/schema.sql`, cria `saas_internal_inbox` com FK/índices e chave única empresa+job+destinatário. `app/SaasInternalInboxMigration.php` (marcador `saas_internal_inbox_version=8`) exige schema 30 e migrações SaaS 1–7, única operação interna ativa, multiempresa desativado; CLI `bin/migrate-saas-inbox.php` com backup SHA256 obrigatório e execução somente manual. `app/SaasInternalInbox.php` prepara entradas internas idempotentes, verificando o roteador `SaasDeliveryRouting`; rota `saas.inbox` permite somente consulta do usuário autenticado com membership e vínculo ao cliente **reavaliados a cada leitura**, sem retornar briefing, token ou telefone. `tests/saas-task-writes.php` cobre deduplicação, usuário removido, cliente desvinculado, outra empresa e inexistência de envio externo. **Nenhum worker ou cron está ligado à caixa; eventos continuam sem marcação de publicados.**

**VPS:** o deploy real **não foi executado**. O workflow existente na branch agora impede deploy fora de `main` inclusive por `workflow_dispatch`. Preparado o script `ops/saas-stage-code-only.sh` e [guia de homologação segregada](docs/saas-vps-homologacao.md), para execução por SSH autorizado num diretório **fora da produção**, com `.taskesc-saas-staging-allow`. Esse script não configura vhost, banco, runtime, migrações ou workers e não publica SaaS funcional por si só. **Não mesclar o PR #113 nem executar migrations no banco produtivo.**

**Arquivos B1.2c-1:** `app/SaasRequestScope.php`, `app/SaasReadApi.php`, `public/api.php` e `tests/saas-children.php`. As novas rotas `saas.context`, `saas.account_select` (POST com CSRF), `saas.records` e `saas.record` são estritamente experimentais: exigem marcadores das migrações B1.1/B1.2a/B1.2b, sessões com vínculo ativo, proprietário ADM, e não aceitam bearer tokens sem contexto de empresa. Recursos permitidos: projeções seguras de `clients`, `projects`, `demands`, sempre filtradas por `tenant_account_id`. **Nenhuma rota legada foi alterada; não libera SaaS.**

**Arquivos B1.2b:** `app/SaasChildMigration.php`, `bin/migrate-saas-children.php`, `tests/saas-children.php`, `app/AccountOwnership.php`, `database/schema.sql` e `.github/workflows/deploy.yml`. 16 tabelas filhas: `contract_items`, `cycles`, `cycle_items`, `demand_usage`, `transitions`, `comments`, `checklists`, `time_entries`, `files`, `reviews`, `change_requests`, `costs`, `payments`, `events`, `automation_runs` e `automation_run_steps`. Migração sob `settings.saas_children_version=3`, schema operacional permanece 30, execução manual com backup.

**Arquivos B1.2a:** `app/SaasDomainMigration.php`, `app/AccountOwnership.php`, `bin/migrate-saas-domain.php`, `tests/saas-domain.php`, `database/schema.sql` e matriz CI. Coluna `tenant_account_id` foi escolhida porque `calendars.account_id` já identifica a conta Google. O schema operacional permanece **30**.

**Arquivos B1.1:** `database/migrations/031_saas_accounts_foundation.sql`, `database/schema.sql`, `app/SaasFoundationMigration.php`, `app/AccountContext.php`, `bin/migrate-saas-foundation.php`, `tests/saas-foundation.php`, `.github/workflows/deploy.yml`, `docs/saas-b1.md`.

**Testes:** [GitHub Actions #39030497373](https://github.com/vitoryvensreis/task/actions/runs/39030497373) — validação de código, MySQL 8 e MariaDB 10.11 aprovados; deploy na VPS **ignorado** no PR. PR permanece em rascunho para impedir aprovação precoce.

**Ponto exato onde paramos:** escolhido rollout **na VPS/domínio/DB atuais**, sem staging separado. Auditoria read-only implementada e CI aprovada (#39068616079), sem execução na VPS. Antes de publicar código e aplicar DDL, conferir backup/snapshot recente e restaurável, pré-auditoria, capacidade/locks e todas as flags SaaS desligadas. Branch WIP não deve ser mesclada sem revisão de produção; os workers externos, controladores globais, chat, arquivos e financeiro continuam pendentes. Próxima implementação: B1.2c-2b.2b.2b.2b.2 com isolamento e testes A/B. Registrar commit, backup e health no deploy, quando houver acesso de execução.

---


Preparação opcional de Redis/Centrifugo, com feature flag desligada: [Realtime — Passo 1](docs/realtime-step1.md).
Infraestrutura transacional de eventos, sem publisher/frontend: [Realtime — Passo 2](docs/realtime-step2.md).

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

A atualização mantém as migrações anteriores e chega ao **schema 21**, criando somente `event_outbox` a partir do schema 19. O schema 20 permanece reservado ao SaaS removido e não é reexecutado. Clientes, tasks, arquivos, histórico e integrações são preservados. O DDL novo está em `database/migrations/021_event_outbox.sql`; o marcador só avança após validação da estrutura. Faça backup completo antes da atualização.

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

### Ajustes v3.26 de visualização, Drive e auditoria

A v3.26 corrige o fechamento do visualizador de arquivos: os botões **X**, **Fechar**, clique no fundo e tecla **Esc** encerram apenas a pré-visualização sem recarregar a task. O modal da task permanece aberto por baixo.

A entrega final passa a reconstruir o link da pasta diretamente a partir da pasta-pai do arquivo final salvo no Google Drive, usando a conta realmente vinculada ao arquivo. Isso evita perder o botão **Abrir pasta** quando o roteamento de Drive do cliente muda ou quando a task é antiga. A abertura de uma task também deixa de criar ou alterar pastas do Drive apenas para descobrir o link de entrega.

A autorização Google agora registra os **scopes efetivamente presentes no access token**, em vez de somar scopes antigos ao novo token. Isso evita a interface indicar `drive.file` quando o token real não possui a permissão.

Foi adicionado `bin/system-audit.php`, executado no deploy após o backup. Ele valida schema, tabelas essenciais, referências órfãs, vínculos de arquivos no Drive, contas Google, storage gravável e restos conhecidos da primeira fundação SaaS. No deploy desta versão, `--cleanup-saas` remove somente as tabelas `workspaces`, `workspace_members`, `workspace_settings` e as seis feature flags SaaS, caso ainda existam. Dados normais do TaskEsc não são removidos.


## Realtime — Passo 3

O Passo 3 adiciona o worker CLI `bin/realtime-worker.php` para consumir `event_outbox` em lotes pequenos e publicar no Centrifugo pela API HTTP local. O worker usa lock MySQL por evento, `event_key` como `idempotency_key`, retry com backoff exponencial, limite de 8 tentativas, heartbeat e health sem expor API key/HMAC.

O deploy instala e habilita `taskesc-realtime-worker.service` com restart automático, executa um evento de diagnóstico real e valida serviço, heartbeat, Centrifugo e fila. A configuração sensível continua exclusivamente em `/etc/taskesc/realtime.json`. Nenhuma integração WebSocket/frontend é ativada neste passo. Consulte `docs/realtime-step3.md`.


## Realtime — Passo 4

O Passo 4 habilita a autenticação segura navegador → Centrifugo para usuários já autenticados no TaskEsc. O endpoint `/realtime.auth` usa somente a identidade da sessão, exige CSRF/same-origin e emite JWTs HS256 de conexão e de assinatura compatíveis com Centrifugo 6.9.7.

Canais preparados: `user:{id}`, `demand:{id}`, `project:{id}` e `kanban/global`, sempre reutilizando as regras atuais de admin/equipe/cliente/preview do TaskEsc. O cliente oficial `centrifuge-js 5.7.4` é vendorado localmente no build/deploy, sem CDN em runtime. Falhas realtime não interrompem a aplicação HTTP e nenhum card do Kanban é alterado neste passo.

O deploy executa um smoke real de login → connection JWT → subscription JWT → handshake WSS, além de confirmar que assinatura sem token, canal não autorizado e reutilização do token em outro canal são negados. Consulte `docs/realtime-step4.md`.


## Realtime — Passo 5

O Passo 5 conecta as alterações reais de tasks ao Outbox transacional. Criação, edição, mudança de etapa, reordenação/movimentação, ações em lote, exclusão e decisão de orçamento passam a gerar eventos mínimos em `event_outbox` dentro da mesma transação MySQL da alteração de negócio.

Os eventos são distribuídos para `kanban/global`, `demand:{id}` e, quando aplicável, `project:{id}`; uma troca de projeto também invalida o projeto anterior. Rascunhos permanecem somente no canal global de administrador. O payload não carrega briefing, notas internas nem valores financeiros.

O worker do Passo 3 continua fazendo publicação idempotente/retry no Centrifugo. O frontend ainda não move nem recarrega cards a partir desses eventos neste passo. Consulte `docs/realtime-step5.md`.


## Realtime — Passo 6

O Passo 6 faz o frontend reagir às publicações WebSocket das tasks. Foi adicionado o canal `client:{id}`, autorizado pelas mesmas regras de acesso já usadas pelo HTTP, para que Equipe, Cliente e modo de visualização recebam mudanças dos clientes que realmente podem enxergar; administradores continuam usando `kanban/global`.

Eventos `demand.*` agora invalidam Dashboard, Kanban, Tasks, Financeiro e Relatórios com debounce, preservando scroll e filtros do Kanban. Modais de task em detalhe também são atualizados; exclusões remotas fecham o modal e edições em andamento não são sobrescritas. O backend inclui um `operation_id` compartilhado no fanout para o navegador deduplicar publicações sobrepostas.

O WebSocket continua sendo apenas o gatilho: os dados completos são relidos pelos endpoints HTTP autorizados. O refresh periódico de 30 segundos permanece como fallback. Consulte `docs/realtime-step6.md`.


## Chat administrativo realtime

O TaskEsc possui chat privado 1:1 **somente entre Administradores** pela infraestrutura WebSocket já existente. O acesso fica em um botão flutuante no canto inferior direito, que abre um popup responsivo com pesquisa de contatos, lista de administradores, conversa, não lidas e confirmação de leitura.

As mensagens ficam persistidas no MySQL e a entrega em tempo real usa Outbox → worker → Centrifugo → canais pessoais `user:{id}`. O corpo das mensagens não trafega no evento WebSocket: o evento carrega somente identificadores e o navegador consulta o conteúdo pelo HTTP autenticado.

Equipe, Clientes, modo preview e tokens de API/MCP não possuem acesso ao chat. Consulte `docs/chat-realtime.md`.


## Realtime completo — Tasks, Chat e Agenda

A camada realtime do TaskEsc cobre agora o ciclo completo das tasks: criar, editar, mover, reordenar, excluir, comentários públicos/internos, checklist, horas, alterações de escopo, uploads/links, exclusão de arquivos e aprovação de versões. Dados internos são separados pelo canal `staff/global`; conteúdos sensíveis não são enviados pelo WebSocket.

A Agenda usa `agenda/global` para Administradores e Equipe e reage a criação, edição, cancelamento, resolução de conflitos e mudanças vindas do Google Calendar (webhook/cron/sync manual). O navegador recebe apenas uma invalidação mínima e relê os dados pelos endpoints HTTP autorizados.

O chat administrativo continua em canais pessoais `user:{id}`. O polling de 30 segundos permanece somente como fallback. Detalhes em `docs/realtime-full.md`.


## WPPConnect — infraestrutura inicial

A integração de WhatsApp do TaskEsc usa WPPConnect Server isolado em Docker. O provider fica em `127.0.0.1:21465` e o subdomínio `wppconnect.escotilhaprodutora.com` atua como reverse proxy HTTPS com suporte a Socket.IO/WebSocket.

O TaskEsc nunca expõe `SECRET_KEY` ou token do WPPConnect ao navegador. A integração backend inicial oferece health/status, iniciar sessão, QR Code e envio de teste. Veja `docs/wppconnect-step1.md`.


## WPPConnect — painel realtime

O WPPConnect agora possui integração administrativa dentro de **Configurações → WhatsApp**. O TaskEsc registra um webhook HTTPS assinado, converte eventos de sessão em `wpp.changed` no Outbox e publica somente para `wpp/global`, canal exclusivo de Administradores.

O QR bruto e credenciais do provider não trafegam pelo Centrifugo. O painel busca QR/status pelos endpoints autenticados do TaskEsc e recebe invalidações instantâneas via WebSocket. Consulte `docs/wppconnect-step2.md`.


## Automações — núcleo executável (schema 24)

O TaskEsc possui agora a fundação do motor de automações baseada nos eventos transacionais já emitidos pelo Outbox. Usuários e clientes possuem número de WhatsApp; cada Task define se os disparos relacionados usam quem criou a task, o cliente ou ninguém. O responsável pela execução não recebe por essa regra.

As automações são armazenadas como grafos validados, com gatilho, condições, ramificações e ação WhatsApp. Um worker systemd dedicado consome eventos novos, deduplica o fanout pelo `operation_id`, registra cada execução/etapa e aplica retry limitado sem bloquear as requisições do sistema. Consulte `docs/automation-step3.md`.

O editor visual em árvore será construído por cima dessa estrutura no próximo passo.


## Automações — editor visual em árvore

A área **Automações** está disponível para Administradores em `/automacoes`. Ela usa o motor do schema 24 para montar visualmente fluxos com gatilho, condições com ramos **SIM/NÃO**, ação de WhatsApp e fim.

O editor salva diretamente o `definition_json` validado pelo backend, protege alterações não salvas, permite ativar/pausar fluxos e mostra as execuções reais do worker. Nenhuma nova migração foi necessária neste passo.

Detalhes: `docs/automation-step4.md`.


## Automações — clientes, prazos e destinatários WhatsApp

O editor de automações também cobre **cliente criado**, **conta de Equipe criada e vinculada ao cliente**, **Task próxima da entrega/atrasada** e **cobrança próxima do vencimento/atrasada**. Os gatilhos de data são gerados pelo worker de forma idempotente.

Cada ação **Enviar WhatsApp** pode usar a seleção da própria Task ou escolher diretamente **responsável pela task**, **quem criou a task**, **cliente** ou **número específico**. Para gatilhos de Cliente/Financeiro, estão disponíveis cliente e número específico.

Detalhes: `docs/automation-triggers-recipients.md`.

Para mensagens com links de entrega, a variável `{{link do drive}}` resolve o mesmo link compartilhável da pasta **Entrega final** da task (quando disponível).


## Automações — entrega final e ações de negócio

O editor `/automacoes` tem o gatilho **Envio de entrega final**, emitido apenas após o registro transacional de um arquivo na categoria *Entrega final* (upload normal ou direto). O evento mantém o `file_id` e a versão, permitindo um disparo por envio.

Além de WhatsApp e condições, o fluxo permite:

- **Alterar task:** movimentar para uma coluna ativa, alterar prioridade ou responsável de equipe vinculado ao cliente. Alterações registram transições, auditoria e evento realtime.
- **Enviar e-mail:** destinatário cliente, criador, responsável ou endereço personalizado. Assunto e mensagem usam variáveis do fluxo; envio entra na fila SMTP existente.
- **Notificação push:** criador, responsável, usuários do cliente ou usuário escolhido. Salva notificação no aplicativo e tenta enviar Web Push aos dispositivos inscritos.
- **Criar task:** cliente do gatilho ou outro cliente ativo, categoria, título, briefing, prioridade e horas úteis até a entrega. Respeita o prazo mínimo e atribui a criação ao administrador proprietário da automação. Sem cobrança automática.

As novas ações usam a tabela `automation_run_steps` para registrar o resultado na mesma transação das alterações de negócio; execuções repetidas não duplicam tasks ou mensagens na fila. Eventos originados por ações de automação levam marcação `automation_generated` e são ignorados pelo motor de automações, evitando ciclos, mas continuam sendo publicados para atualizar a interface em realtime.

**Banco:** não requer migração adicional; mantém schema 24.

**Produção:** publicar o projeto atualizado com backup e verificar `tests/automation-core.php` em MySQL/MariaDB de testes, serviço `taskesc-automation-worker` e o envio real dos canais configurados.

### Disparos para grupos do WhatsApp (schema 25)

Em **Automações → Enviar WhatsApp → Destinatário**, selecione **Grupo do WhatsApp**, escolha um grupo da sessão WPPConnect e salve. A ação `wpp.groups` lista grupos de forma segura, restrita ao administrador, e o worker envia com `isGroup=true`. O histórico WhatsApp e a migração 25 preservam os registros anteriores. Consulte `docs/automation-triggers-recipients.md`.

### Diagnóstico Google e notificações de saúde (13/10/2026)

- **Saúde & Logs → Diagnóstico Google**: contas e agendas com falhas, último erro, motivo sugerido, ação de sincronização e reconexão explícita (sem expor tokens).
- `SystemHealth::watch()` identifica incidentes persistentes por recurso e tipo de erro, emite o primeiro aviso, mudanças de situação e no máximo um lembrete a cada 24 horas; emite também confirmação única de normalização. O histórico anterior permanece intacto.
- Contas Google desconectadas intencionalmente e agendas locais não contam como erros.
- Renovação OAuth distingue rejeição definitiva (`invalid_grant`, `invalid_client`, `unauthorized_client`, `access_denied`) de timeout, instabilidade e limites temporários. Apenas erros definitivos marcam a conta como `reconnect`; falhas transitórias usam retentativas com intervalo progressivo. Um HTTP 401 inesperado também força renovação única do token antes de repetir a chamada.
- Uma sincronização real bem-sucedida limpa os erros antigos da respectiva conta e agenda.
- Teste de integração: `tests/google-health.php` (executado com banco descartável após `tests/automation-core.php`). Nenhuma migração SQL nova é necessária; o estado do monitor usa `settings`.

### WhatsApp: número específico por task (schema 26)

Em **Criar/Editar task → Disparos de WhatsApp**, selecione **Número específico**, informe DDI + DDD + número e salve. O campo `demands.whatsapp_custom_phone` guarda apenas os dígitos normalizados. Os blocos de automação com destinatário **Usar seleção da própria task** passam a usar esse telefone. A seleção não altera blocos que escolheram outro destinatário explicitamente. Somente usuários da equipe podem escolher um número manual; usuários clientes não veem o número salvo. A migração de 25 para 26 adiciona apenas essa coluna nullable e preserva as demais tasks.

### Disparos de WhatsApp por task: acesso e padrão

Somente o perfil **ADM** vê e pode editar os controles de WhatsApp na criação/edição de tasks. A configuração é opcional; em novas tasks criadas por qualquer perfil (inclusive via automação), o destino padrão é **Quem criou a task**. O telefone só é obrigatório ao escolher explicitamente **Número específico**. Contas de equipe e clientes não recebem o campo na API e não podem alterá-lo via `demands.save`; edições nesses perfis preservam a preferência anterior. Tasks antigas mantêm sua seleção já salva. Sem migração: schema 26 preservado.

### Canvas livre de automações

O editor visual permite arrastar os cards, salvar as posições no JSON da automação e conectar um gatilho/ação a vários destinos. O worker executa cada ramo uma vez por execução, preservando a proteção contra reenvios. Os fluxos antigos continuam compatíveis, sem migração SQL. Consulte `docs/automation-triggers-recipients.md`.

### TaskEsc AI — Passo 1: recebimento passivo do WhatsApp

O webhook autenticado do WPPConnect agora registra eventos `onmessage` de conversas privadas e grupos na tabela `ai_whatsapp_inbox` (schema 27), com índice único por sessão/ID da mensagem, sem respostas automáticas. Eventos de conexão, QR Code e envio de automações permanecem inalterados. A migração é aplicada via `bin/migrate-ai-whatsapp-inbox.php` apenas após backup verificado na CI/deploy. Consulte `docs/taskesc-ai-step1.md`.

### TaskEsc AI — Passo 3 (Groq e ferramentas autorizadas)

Integração de IA via Groq com ações limitadas a **listar, criar e mover tasks** através das rotinas existentes do TaskEsc. Worker isolado `taskesc-ai-whatsapp-worker.service`, sem impactar automações. Proteções: menção real no grupo, autorização e membros do grupo verificados antes da operação, filas idempotentes e nenhuma repetição automática de envio incerto. Mensagens antigas não são processadas. Para habilitar, definir somente na configuração privada da VPS `ai_whatsapp.enabled=true` e `ai_whatsapp.groq_api_key`. Consulte `docs/taskesc-ai-step3.md`.


### TaskEsc AI — Passo 4 (edição segura de tasks)

O assistente pode editar **título, briefing, prioridade e prazo** de uma task existente pelo WhatsApp, desde que o usuário tenha permissão `tasks.edit` e identifique a task sem ambiguidade. Campos financeiros, clientes, responsáveis, status, uploads e automações não podem ser alterados por essa ferramenta. A transação protege o rascunho e demais atributos, exige prazo válido e mantém autorização revalidada por operação. **Schema 29 permanece inalterado** e Groq continua desativado por padrão. Consulte `docs/taskesc-ai-step4.md`.

### TaskEsc AI — Gemini principal / Groq alternativa

O motor de IA recebe mensagens autorizadas pelo WhatsApp e utiliza **Gemini 3.8 Flash** como interpretador principal, com **Groq** como alternativa em falhas do provedor. Operações são executadas somente pelo gateway de tarefas do próprio TaskEsc, compartilhando regras com a API v1. Por segurança, as chaves não são incluídas no GitHub nem no ZIP. Para ativar pela VPS, use `php bin/configure-taskesc-ai.php` num terminal SSH interativo e reinicie o worker. Veja [guia de integração e ativação](docs/taskesc-ai-gemini-primary.md).

### TaskEsc AI — consulta financeira com permissões

A API REST v1 tem `GET /api/v1/finance/summary` e `GET /api/v1/finance/invoices` para administradores. O agente de WhatsApp também pode **consultar** o resumo financeiro, contas a receber e despesas registradas, exclusivamente em conversa privada de um ADM autorizado. Os endpoints novos `GET /api/v1/finance/receivables` e `GET /api/v1/finance/expenses` compartilham o mesmo gateway somente leitura com a IA. Essas consultas não geram lançamentos, pagamentos ou despesas; valores ainda não sincronizados não aparecem. Todas as operações financeiras de escrita permanecem bloqueadas para a IA. Veja [cobertura, regras e limites](docs/taskesc-ai-finance.md).

### TaskEsc AI — ferramentas administrativas com confirmação (revisão)

Há uma implementação de operações administrativas privadas do WhatsApp com duas mensagens: primeiro uma proposta validada, depois `CONFIRMAR <código>` ou `CANCELAR <código>`. Fica **desativada por padrão** por `ai_whatsapp.admin_control_enabled=false`. Consulte [módulos, segurança, testes e limitações](docs/taskesc-ai-admin-control.md). Não autoriza acesso irrestrito à plataforma nem ignora as regras do domínio.

### TaskEsc AI — agenda e grupos

Em grupos, somente o ADM TaskEsc autenticado e confirmado como participante pode invocar o assistente escrevendo **Task** ou **TaskEsc** como palavra isolada, ou marcando a conta oficial. Mensagens comuns não acionam a IA. Consultas de agenda no grupo mostram apenas compromissos públicos. No privado, o ADM pode listar calendários, compromissos, horários ocupados e detalhes; com a flag privada independente `ai_whatsapp.agenda_control_enabled=true`, também pode propor criação, edição e cancelamento, sempre sujeitos a código de confirmação em uma segunda mensagem. Eventos Google são sincronizados pelo cron existente após a transação. Veja [regras e testes](docs/taskesc-ai-agenda-groups.md).
