# TaskEsc AI — Passo 1: inbox passivo de WhatsApp (schema 27)

## Objetivo e limites

- Receber eventos `onmessage`, `on-message`, `message` e `message.received` no webhook HTTPS existente.
- Validar token HMAC do webhook antes de processar o payload (via `public/wppconnect-webhook.php`).
- Armazenar mensagens recebidas de conversas privadas e grupos na tabela `ai_whatsapp_inbox` (InnoDB).
- Deduplicar via índice único `(session_name, provider_message_id)` e upsert idempotente.
- Persistir somente sessão, ID da mensagem, chat, remetente, tipo, corpo textual limitado, status e hora. Não guardar JSON bruto, base64, anexos ou QR Code.
- Ignorar mensagens de saída (fromMe), tipos de eventos não listados, sessões divergentes, IDs ausentes, chats inválidos e grupos sem identidade de remetente.
- **Sem resposta automática, sem invocação de IA, sem execução de comandos ou criação de tasks.** O campo `status` é inicializado como `received`; não existe consumidor nesta etapa.
- Eventos de conexão e QR Code seguem no caminho original do `WppWebhook` para o `WppRealtime`, sem copiar conteúdos sensíveis para Outbox.

## Segurança

O número do WhatsApp não é autenticação de usuário nesta etapa; `sender_id` é somente metadado não confiável até a etapa futura de vinculação de identidades. A futura autorização precisa:
1. Vincular número/LID aos usuários ativos do TaskEsc e exigir uma permissão concedida pelo ADM.
2. Confirmar remetente autorizado; para grupos, verificar participação atual de ao menos um ADM TaskEsc.
3. Recusar processamento se não puder confirmar a identidade ou o grupo; não publicar dados pessoais em grupos sem política explícita.
4. Exigir confirmação e auditoria antes de ações sensíveis.

A inbox deve ter acesso restrito ao servidor, sem API de listagem pública. As mensagens recebidas são dados pessoais; definir retenção, acesso e limpeza antes de habilitar o assistente.

## Banco e deploy

Novo arquivo: `database/migrations/027_ai_whatsapp_inbox.sql`.

A migração isolada `Migrations::assistantInbox()` aceita exclusivamente schema 26 ou 27, rejeita transação de negócio ativa, usa `GET_LOCK` e valida a estrutura/índices existentes antes de avançar o marcador. O CLI `bin/migrate-ai-whatsapp-inbox.php` exige manifest e SHA256 de backup pré-deploy; é executado após migradores Outbox/Chat/Automações. Repetir o deploy no schema 27 é operação sem alterações.

O pipeline também executa testes de payload no MySQL 8 e MariaDB 10.11, auditoria schema 27 e health check pós-deploy.

## Como testar depois do deploy

1. Confira que o número está conectado em **Configurações → WhatsApp**; os eventos de status/QR devem continuar funcionando.
2. Envie uma mensagem privada e uma mensagem num grupo pelo WhatsApp conectado.
3. **Não haverá resposta do bot neste passo.**
4. Para confirmar no servidor (sem expor o conteúdo das mensagens): `SELECT id,chat_type,message_type,status,received_at FROM ai_whatsapp_inbox ORDER BY id DESC LIMIT 10;`.
5. Reenvie o mesmo evento de webhook em ambiente de teste: o índice único evita duplicar a mensagem.
6. Se o provedor não enviar IDs e remetentes nos formatos previstos, o webhook retorna `accepted=false` e o evento é descartado com segurança. Capturar somente metadados redigidos para investigar; não registrar payloads completos em logs.

## Compatibilidade

- Disparos agendados, automações e WPPConnect `sendAutomationMessage()` não foram modificados.
- Grupos mantêm `@g.us`; participantes privados mantêm `@c.us`, `@s.whatsapp.net` ou `@lid` para posterior verificação.
- Sem novo worker, sem chave de IA, sem novo endpoint público e sem alterações visuais.
