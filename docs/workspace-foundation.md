# Workspace único — fundação preparada (schema operacional 30)

## Objetivo da migração

Vincular os dados atuais do TaskEsc ao workspace **Escotilha Produtora**, cujo
administrador proprietário é o usuário existente
`vitoryvensreisdeoliveira@gmail.com`, sem perda ou troca de IDs existentes.

`escotilhaprodutora@gmail.com` foi definido como o futuro ADM Master, mas a
inspeção somente leitura na VPS confirmou que essa conta ainda **não existe no
banco**. A migração registra somente a identidade pendente em
`platform_master_identities`. **Não cria credenciais, não concede role master,
não habilita login ou suporte.** Uma verificação de propriedade do endereço e
ativação explícita serão necessárias mais adiante.

A arquitetura da migração NÃO é a fundação SaaS anterior. O schema 30 e as
funcionalidades atuais permanecem vigentes.

## Escopo

- Cinco tabelas novas: `workspaces`, `workspace_members`,
  `workspace_settings`, `workspace_support_sessions`,
  `platform_master_identities`.
- 44 tabelas operacionais existentes recebem `workspace_id BIGINT UNSIGNED
  NOT NULL`, um índice correspondente e um default para o workspace
  **único** existente. Os dados/IDs históricos permanecem.
- Usuários já existentes, exceto a identidade master se houver, recebem
  `workspace_members`, respeitando seu status atual e sem reativar membros
  desabilitados; o proprietário recebe papel `owner`.
- Configurações comuns de nome/branding, timezone, uploads, Drive de clientes
  e dashboards são copiadas de `settings` para `workspace_settings`.
- `users` continua sendo a identidade global de autenticação.
  `calendars` e `events` não são atribuídos a um workspace — serão
  exclusivos do ADM Master quando a camada de permissão estiver pronta.
- `settings`, `rate_limits`, `oauth_clients`, `reset_tokens`,
  `automation_worker_state` e `system_errors` continuam globais/técnicos.

## Garantias e restrições

- Executar apenas via `bin/migrate-workspace-foundation.php --apply
  --backup-manifest=...` sob backup comprimido, checksum SHA256 recente,
  cópia fora da VPS e rollback documentado.
- A rotina verifica schema 30, administrador proprietário ativo e separado do
  master, ausência do SaaS removido, e recusa qualquer workspace adicional.
- DDL é aditivo, idempotente e sem apagar arquivos, tarefas, clientes,
  faturas, pagamentos ou registros históricos.
- **IMPORTANTE: nenhum novo cliente SaaS/workspace deve ser habilitado nesta
  etapa!** As APIs, permissões, frontend, workers, integrações e consultas
  legadas ainda não filtram por `workspace_id`. Com um único workspace,
  seguem funcionando como antes. Para permitir multiempresa, filtros por
  workspace, verificações nos joins, índices únicos compostos e testes
  cruzados A/B são obrigatórios.
- Novos usuários criados pelo frontend legado após o backfill ainda precisam
  ser sincronizados com `workspace_members` na próxima etapa. O sistema
  operacional atual não deve depender dessa tabela até tal ajuste.
- O calendário ainda poderá aparecer para outros papéis na interface legada.
  A restrição exclusiva ao ADM Master exige alteração do backend posteriormente.

## Publicação

PR de desenvolvimento: #120.
A confirmação da publicação exige CI MySQL/MariaDB, execução remota da migração
e health check da VPS. Nunca inferir sucesso apenas por ter feito merge.
