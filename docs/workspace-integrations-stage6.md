# Etapa 6 — Inventário de integrações por workspace (18/10/2026)

Esta entrega inclui apenas um avanço seguro da Etapa 6, **não sua conclusão**.

- A tela de cada empresa no ADM Master apresenta conexões Google, Drive padrão válido, agendas conectadas/selecionadas, uploads, automações e notificações reais, sempre filtrados por `workspace_id` e sem expor tokens.
- O fluxo OAuth Google agora vincula a solicitação à empresa autenticada e recusa callbacks após mudança de workspace.
- A renovação de tokens Google e as sincronizações com o Google Agenda exigem escopo de workspace em modo multiempresa. Cron/workers **devem** usar `WorkspaceJobScope::forWorkspace(...)`; consultas globais não são executadas silenciosamente.
- O comportamento legado com **somente o workspace do Vitor** fica preservado.
- WPPConnect, calendário local e rotinas globais ainda **não estão homologados** para múltiplas empresas. Não habilite `workspace_multitenant_isolation_v2` e não converta o pré-cadastro da Gabriella em workspace operacional por esta alteração.
- Asaas ainda não confirma assinaturas e nenhuma cobrança fictícia é gerada.

**Próximos passos:** scope completo de calendário local, contas e uploads, worker de WhatsApp por workspace e auditoria de endpoints; homologação A/B em banco descartável; migração sem defaults implícitos com backup; somente então corte transacional e deploy multiempresa.
