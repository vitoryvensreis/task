# TaskEsc AI — Passo 2: identidade e autorizações (schema 28)

O webhook autenticado continua **somente recebendo e armazenando mensagens**. Nenhuma mensagem é respondida, nenhuma task é criada/modificada e nenhuma ação financeira é executada. A verificação abaixo classifica a mensagem recebida para diagnóstico, **não é um token de autorização permanente**.

## Permissões

- ADM ativo: acesso implícito, independentemente do checkbox.
- Equipe/cliente ativo: acesso somente se `users.ai_assistant_enabled=1`, habilitado pelo ADM em **Configurações → Usuários → Editar usuário**.
- Contas inativas, telefones sem vínculo, números compartilhados entre contas e qualquer identificador `@lid` sem mapeamento autenticado: **negado por padrão**.
- A autorização por WhatsApp não substitui as permissões específicas de cada papel no TaskEsc, que deverão ser verificadas por cada ação futura.
- Nunca tomar o número informado dentro de uma mensagem como identidade. A identidade vem do `sender_id` do webhook tokenizado do WPPConnect e só é associada a `users.whatsapp_phone` por igualdade exata de número completo DDI+DDD.
- Ao editar usuários, o sistema impede criar novos números duplicados; números antigos duplicados são negados em tempo de verificação.

## Grupos

- Consultar participantes em tempo real via `GET /api/{session}/group-members/{groupId}`, nunca confiar em uma lista de participantes incluída em mensagem recebida.
- Exigir simultaneamente (1) remetente ativo/autorizado com identidade de telefone confirmada, (2) remetente entre os membros atuais do grupo e (3) pelo menos um ADM ativo do TaskEsc também presente no grupo.
- Um ADM do TaskEsc **não precisa ser administrador do grupo WhatsApp**.
- Se provedor estiver fora do ar, resposta inválida, membro não identificado ou `@lid` sem vínculo verificado, a autorização **falha de forma fechada**.
- A autorização de grupo só é um snapshot: antes de realizar uma ação futura será preciso repetir a consulta e verificar a permissão da ação. Permissões de menção/reply e limites de resposta pertencem ao próximo passo, que habilitará ações.

## Banco

- `users.ai_assistant_enabled TINYINT NOT NULL DEFAULT 0`
- `ai_whatsapp_inbox.auth_user_id`, `auth_status`, `auth_reason`, `auth_checked_at`; mensagens anteriores permanecem `pending` e **não podem ser executadas**.
- Apenas o ADM recebe os campos de permissão via listagem de usuários; não existe endpoint público para consultas de inbox.
- Não copia participantes, QR Codes, mídia, API secrets ou informações privadas para resposta do webhook ou Outbox.

Migração isolada `Migrations::assistantAccess()` aceita somente 27/28, executa sob lock e fora de transações. O deploy requer backup MySQL íntegro + manifest SHA256, aplica a migração pelo CLI `bin/migrate-ai-whatsapp-access.php` e audita schema 28. Nenhuma tabela do SaaS removido é recriada.

## Verificação de recepção passiva

Após o deploy, confira somente contagens e estado em ambiente autorizado:
```sql
SELECT chat_type,auth_status,auth_reason,COUNT(*) AS total
FROM ai_whatsapp_inbox GROUP BY chat_type,auth_status,auth_reason;
```

O histórico do Passo 1 terá `auth_status='pending'`. Os novos eventos terão `authorized` ou `denied`. Isso **não habilita** leitura/execução da IA: não existe consumidor de comandos nesta etapa.
