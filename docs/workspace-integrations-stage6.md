# Etapa 6 — Inventário de integrações por workspace (18/10/2026)

Esta entrega inclui apenas um avanço seguro da Etapa 6, **não sua conclusão**.

- A tela de cada empresa no ADM Master apresenta conexões Google, Drive padrão válido, agendas conectadas/selecionadas, uploads, automações e notificações reais, sempre filtrados por `workspace_id` e sem expor tokens.
- O fluxo OAuth Google agora vincula a solicitação à empresa autenticada e recusa callbacks após mudança de workspace.
- A renovação de tokens Google e as sincronizações com o Google Agenda exigem escopo de workspace em modo multiempresa. Cron/workers **devem** usar `WorkspaceJobScope::forWorkspace(...)`; consultas globais não são executadas silenciosamente.
- O comportamento legado com **somente o workspace do Vitor** fica preservado.
- WPPConnect, calendário local e rotinas globais ainda **não estão homologados** para múltiplas empresas. Não habilite `workspace_multitenant_isolation_v2` e não converta o pré-cadastro da Gabriella em workspace operacional por esta alteração.
- Asaas ainda não confirma assinaturas e nenhuma cobrança fictícia é gerada.

**Próximos passos:** scope completo de calendário local, contas e uploads, worker de WhatsApp por workspace e auditoria de endpoints; homologação A/B em banco descartável; migração sem defaults implícitos com backup; somente então corte transacional e deploy multiempresa.

## Agenda por workspace — avanço estrutural

- Migração 038 acrescenta `workspace_id` a calendários e eventos após backup conferido; os registros antigos continuam na Escotilha.
- Em modo multiempresa, calendário local, Google sync, edição, exclusão, conflitos, atualização remota e publicações realtime usam escopo autenticado. Inserts são verificados pela camada DB.
- WhatsApp global **não é** liberado em modo multiempresa. Webhooks e envios ficam bloqueados até que a sessão do WPPConnect e o worker da IA sejam separados por empresa.
- A ativação real da Gabriella segue bloqueada até aposentadoria dos defaults implícitos das demais tabelas e homologação de APIs/workers. Nenhuma troca automática para modo multi foi executada.

## WhatsApp — decisão: uma única sessão
- O ADM Master tem aba própria em `/master/whatsapp`: consultar, conectar, exibir QR, fechar e desconectar a única sessão.
- Os endpoints oficiais de fechar e logout são utilizados, com confirmação da senha Master e auditoria de plataforma.
- O número é transporte global, mas o processamento de mensagens e automações deve resolver o workspace pelo usuário autenticado, nunca pelo payload externo.
- AI inbound multitenant ainda exige roteamento auditado; não ativar em produção sem ele.

## Worker do cron
- No modo multiempresa, financeiro (mensalidades e ciclos) e Google Agenda são executados dentro de `WorkspaceJobScope::forWorkspace` por empresa ativa.
- SMTP é transporte global compartilhado e só executa um flush por cron, sem duplicar mensagens.
- Cron HTTP deixa de executar jobs multiempresa sem identidade validada; use somente `php bin/cron.php`.
- O worker AI continua aguardando isolamento completo: nenhum conteúdo confidencial de outro workspace é processado via fallback.
