# Separação definitiva — Vitor e Gabriella (TaskEsc)

**Decisão confirmada em 18/10/2026**

- **Workspace existente:** Escotilha Produtora (slug `escotilha-produtora`), proprietário Vitor. **Todos os registros históricos continuam aqui**, inclusive demandas, comentários, anexos, clientes, transações, mensagens, integrações e registros de auditoria criados ou atribuídos à Gabriella.
- **Novo workspace:** Gabriella como **única proprietária/ADM**. Novo ambiente inicia sem clientes, demandas, documentos, custos, recebimentos, mensagens ou conexões Google. Ele poderá receber configurações padrão de operação (ex.: etapas do Kanban), mas não dados históricos.
- **Acesso antigo da Gabriella:** vínculo ativo com o workspace do Vitor será encerrado no corte, conservando autoria e histórico, sem apagar registros. Demandas antigas atribuídas a ela **continuam pertencendo ao Vitor**; a equipe poderá reatribuir pendências no próprio workspace, sem alterar autoria histórica.
- **Master:** permanece uma identidade de plataforma, sem acesso automático às empresas; cada entrada exige sessão temporária com justificativa e auditoria.
- **Uma conta de proprietário por workspace.** Membros de equipe têm permissões delegadas, nunca propriedade nem visão de outro cliente.

## Estado atual — NÃO executar criação direta de workspace

A fundação `WorkspaceFoundation` versão 1 bloqueia intencionalmente múltiplos workspaces. A autenticação `WorkspaceContext::forUser`, o suporte Master, a distribuição de planos e consultas dos módulos ainda contêm verificações de workspace único. Nas tabelas históricas, diversas colunas `workspace_id` têm **DEFAULT para o workspace do Vitor**, o que faria novos registros de Gabriella ficarem indevidamente vinculados ao Vitor. Há consumidores globais (serviços em background, calendário, eventos, URLs de mídia, notificações, tokens e integradores) cuja autorização ainda deve ser revista.

**Não contornar esses bloqueios apenas inserindo outro registro na tabela `workspaces`.** Isso pode causar vazamento ou indisponibilidade de ambos os ambientes.

## Etapas necessárias antes de ativar

1. **Pré-migração sem escrita:** executar `php bin/plan-second-workspace.php --gabriella-email=<email confirmado>` na VPS, sem buscar a conta por nome. Verificar proprietário original, unicidade/estado de Gabriella, tarefas atribuídas, acessos Google e defaults. O programa **não altera o banco** e não pede senha.
2. **Escopo completo:** revisar todos os métodos de leitura, gravação, edição, exclusão, API, notificações, arquivos, relatórios, chat, Google/Calendar/Drive, WhatsApp, automações, workers, realtime e cache. Exigir `workspace_id` derivado da sessão verificada ou do evento validado, **nunca de input arbitrário**. Na camada de banco, prevenir gravação com escopo ausente; remover os defaults de `workspace_id` após atualizar todos os writers.
3. **Pertencimento e permissões:** permitir 2+ workspaces no `WorkspaceContext` com exatamente um vínculo de empresa por usuário comum, proprietários distintos e verificação de membro ativo. Master só utiliza escopo por sessão de suporte válida. Impor um proprietário ativo por workspace e impossibilidade de autoelevação de equipe a proprietário. Remover o bloqueio de workspace único apenas depois de escopo homologado.
4. **Homologação A/B em banco descartável:** criar fixture Vitor com dados e Gabriella vazia. Validar acesso A→B e B→A negado (listagens, IDs diretos, uploads/downloads, OAuth, API tokens, realtime, webhooks e workers). Conferir relatórios/financeiro, prevenção de CSRF, autenticação/2FA e persistência dos dados existentes.
5. **Corte em produção — transacional e com backup validado:** suspender temporariamente escritores e workers, fazer backup completo verificável, registrar contagens e checksums; criar workspace de Gabriella sem dados comerciais; atribuir plano explicitamente; migrar **apenas o vínculo de usuário** (revogar membro do workspace antigo e dar função owner no novo) e sem copiar linhas das tabelas `DATA_TABLES`. Conferir contagens, referências, suporte e logs. Liberar o login somente após o pós-check e ter rollback documentado.
6. **Aceitação:** Vitor encontra todos os clientes/tarefas/arquivos/financeiro anteriores; Gabriella autentica e encontra tudo vazio no próprio workspace; Vitor não enxerga novos dados da Gabriella e vice-versa; Master vê duas empresas no cadastro, entra com motivo auditado, e plano/equipe são independentes.

## Notas

A integração de cobrança Asaas **não é** pré-requisito para isolamento, e não devem ser criadas faturas simuladas. As tabelas de SaaS descontinuado (schema 20) não serão recriadas. O primeiro deploy deve ser somente diagnóstico; a criação real é proibida até cumprir todas as etapas.

**Estado deste documento:** decisão fixada e pré-flight disponível; **nenhum segundo workspace criado**.
