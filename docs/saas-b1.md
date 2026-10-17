# TaskEsc SaaS — Bloco 1: Fundação multiempresa

**Registro de retomada — 2026-10-17.** Branch de desenvolvimento: `feat/saas-b1-foundation`.
**Estado:** B1.1 implementado na branch e pendente de validação/homologação; B1 completo **não concluído**.
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

## Pendente — B1.2: propagação e isolamento por conta

- [ ] Projetar e migrar `account_id` nas tabelas operacionais; priorizar `clients`, `users`/vínculos, `demands`, `projects`, `stages`, `categories`, `contracts`, `invoices`, `payments`, `expenses`, `files`, `templates`, `automations`, `automation_runs`, `event_outbox`, `whatsapp_contacts`, mensagens/chat, agendas, Google, tokens e logs. O inventário precisa ser completado por relacionamento.
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

1. Conferir o status do PR/CI da branch `feat/saas-b1-foundation`; não mesclar com `main` nem executar o CLI em produção sem homologação e decisão explícita de rollout.
2. Continuar **B1.2 pelo inventário de dependências SQL** e desenho da migração aditiva `account_id` para dados legados com conta interna, sem tocar no schema 20.
3. Implementar isolamento no backend em lotes pequenos; fechar o teste A/B para cada módulo antes de avançar.
4. Atualizar este README e este arquivo com commits, verificações concluídas, bloqueios e o próximo trabalho pendente.

### Regras de segurança

- Nunca usar `client_id` como identificador do assinante.
- Nunca cadastrar/permitir acesso a uma nova empresa enquanto existir qualquer rota global sem isolamento.
- Nunca apenas esconder no frontend os recursos IA e Calendar integrado.
- Nunca confiar em `account_id` proveniente de URL, request, webhook, token ou evento sem autenticação/autorização.
- Não misturar financeiro operacional dos assinantes com o faturamento SaaS/Asaas.
