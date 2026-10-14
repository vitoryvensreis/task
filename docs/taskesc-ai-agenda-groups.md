# TaskEsc AI — agenda e resposta em grupos (schema 29)

## Acionamento do assistente no WhatsApp
- Em um grupo, somente um **ADM ativo e identificado** pode acionar o bot. A identidade PN/LID e a participação no grupo são checadas pelo WPPConnect e revalidadas antes da operação e resposta.
- A mensagem deve conter a palavra isolada **Task** ou **TaskEsc**, com ou sem @, ou uma menção nativa verificada ao número do bot. Mensagens que só contêm "tasks", "task_123" ou parte de outro nome não acionam.
- Mensagens comuns do grupo não causam chamadas ao Gemini nem verificações caras de participação. São registradas como ignoradas para diagnóstico.
- Exemplos: `Task, liste minhas tarefas`, `Task, mostre os compromissos de hoje`. Comandos simples podem ser tratados inteiramente pelo PHP.
- Financeiro, contas de usuário, detalhes confidenciais e confirmações de alteração administrativa NÃO são expostos em grupos.

## Agenda
- ADM no grupo: `agenda_list`, apenas eventos **não privados** em calendários selecionados.
- ADM no privado: `agenda_calendars`, `agenda_list`, `agenda_detail`, `agenda_conflicts`.
- ADM no privado, com `ai_whatsapp.admin_control_enabled=true`: `agenda_create`, `agenda_update`, `agenda_cancel`. Cada alteração somente é executada mediante um novo `CONFIRMAR <código>` válido por 10 minutos, vinculado ao autor e à conversa. `CANCELAR <código>` desfaz a proposta.
- As gravações usam `Agenda::save` e `Agenda::cancel` com regras já existentes de permissão, versão, conflitos, auditoria e Outbox.
- Sincronização externa com Google é **adiada até a rotina existente** `Google::sync()` / cron após o commit, evitando chamar uma API externa antes da conclusão da transação de confirmação. Requer cron operacional e conta Google previamente conectada pelo painel.
- Não dá acesso livre ao Google, não altera calendários Google não editáveis e não configura OAuth por mensagem.

## Validação
- `php tests/ai-whatsapp-group-trigger.php` para detecção por nome e fast path.
- `php tests/ai-whatsapp-access.php` confirma admissão de ADM, negação de clientes/staff e identificação PN/LID.
- `php tests/ai-whatsapp-tools.php` exercita grupo sem @, bloqueio não-ADM, confidencialidade dos eventos, conflito, criação com confirmação, edição versionada e cancelamento.
- Schema 29 preservado. A flag administrativa da VPS continua privada; não é ativada pelo deploy.
