# TaskEsc AI — Passo 4: edição limitada de tasks por WhatsApp (schema 29)

Continuidade dos passos 1–3. O catálogo do Groq expõe `edit_task` somente quando o usuário tem `tasks.edit`. O modelo recebe o contexto já filtrado e não pode fornecer SQL, URLs arbitrárias, tokens ou campos financeiros.

## Escopo permitido

- Identificação obrigatória da task pelo `task_id` e ao menos um campo para alterar.
- Alterações permitidas: `title`, `brief`, `priority`, `due_at`.
- `due_at` requer data e hora locais `YYYY-MM-DDTHH:mm`, validação estrita de calendário e as regras atuais de prazo útil.
- Não aceita campos extras, alterações de cliente, projeto, atribuição, categoria, etapa, criação/exclusão, arquivos, mensagens, cobranças, receitas, valores, permissões, automações ou disparos.
- Campos não enviados são preservados, incluindo `draft`; datas existentes em UTC são convertidas para o fuso do TaskEsc antes da atualização para não deslocar o prazo ao editar outro campo.
- Autorização e identidade do remetente são revalidadas antes da operação (inclusive grupo e menção real). A operação usa `Auth::demand(..., true)` e `Demands::save` dentro da mesma transação do worker, preservando auditoria/Outbox e garantindo um único processamento.
- A transação compara dados protegidos antes/depois e cancela a atualização integralmente se ela produzir efeitos colaterais em finanças, cliente, responsável, projeto, etapa, rascunho ou categoria.
- Resposta é emitida no máximo uma vez; mensagens anteriores à ativação do Groq continuam ignoradas.

## Validação

`php tests/ai-whatsapp-tools.php` nos jobs MySQL 8 e MariaDB 10.11. Inclui edição única, edição de prazo, preservação dos demais campos, erros de campos proibidos, validação de dados, ausência de replay e autorização por cliente.

## Ativação em produção

Deploy via PR e CI executa backup verificável e migrações idempotentes (schema permanece 29). Não habilitar o Groq automaticamente: a chave e `enabled=true` devem existir **apenas** em `config/config.php` privado na VPS, com autorização explícita antes de teste real. Ver `docs/taskesc-ai-step3.md`.
