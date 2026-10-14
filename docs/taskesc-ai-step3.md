# TaskEsc AI — Passo 3 (schema 29)

Integra Groq (API HTTPS `/openai/v1/chat/completions`, modelo `llama-3.3-70b-versatile`) com ferramentas locais seguras: `list_tasks`, `create_task` e `move_task`.

## Ativação
Após deploy e backup íntegro, instalar na **VPS** (fora do Git) o seguinte bloco em `config/config.php`:
```php
'ai_whatsapp' => [
  'enabled' => true,
  'groq_api_key' => 'COLOQUE_AQUI_SUA_CHAVE_GROQ',
  'model' => 'llama-3.3-70b-versatile',
],
```
Nunca publicar a chave em GitHub, chat, prints ou logs. Enquanto a configuração não existir, o worker permanece ativo e saudável, **mas não executa nem responde**. Reinicie `taskesc-ai-whatsapp-worker.service` após habilitar.

## Fluxo e segurança
1. Webhook WPPConnect autenticado persiste mensagens novas na `ai_whatsapp_inbox` com estado `queued` somente para remetente autorizado. Mensagens anteriores ao schema 29 ficam `ignored`, sem replay.
2. Worker dedicado verifica identidade e, para grupos, presença de ADM e remetente na lista atual de membros, consultada por API privada. Requer **menção real** ao WhatsApp conectado: metadados do WPPConnect `mentionedJidList`/`mentionedIds`, não texto que apenas contenha `@TaskEsc`. Mapeamento LID→PN verificado pelo provedor.
3. O Groq interpreta o pedido usando um catálogo por usuário; **não tem acesso ao SQL, tokens ou APIs arbitrárias**. Ferramentas são limitadas pelo papel atual; `create_task` usa `Demands::save` e `move_task` usa `Demands::stage`; `list_tasks` respeita `Auth::demandFilter`. Staff não cria tasks se o papel atual não permite; clientes não movem etapas no endpoint existente.
4. **A cada ferramenta**, o servidor consulta novamente autorização, vínculos e membros do grupo dentro da transação MySQL. A gravação de mudanças + marcação `reply_pending` ocorre na mesma transação, evitando repetir uma alteração após retry.
5. Só uma resposta por mensagem: reserva estado `sending` antes de enviar. Falha ou crash deixa `delivery_uncertain`, sem reenvio automático para evitar mensagens duplicadas. Consultar e corrigir manualmente.
6. Mensagens ignoradas, sem menção, não autorizadas ou com sessão inválida não chamam o modelo nem enviam respostas. Pedidos não textuais ficam ignorados.
7. Operações de exclusão, financeiras, em massa, edição arbitrária, transferência de acesso, anexos e áudio **não** foram habilitadas.

## Operação
Comando CLI: `php bin/ai-whatsapp-worker.php --health` ou `--once`. Service via systemd instalado pelo deploy. O schema 29 cria a fila na tabela já existente, sem tabela SaaS e sem alterar mensagens outbound existentes.

Para diagnóstico, consulte:
```sql
SELECT ai_state,chat_type,COUNT(*) AS total FROM ai_whatsapp_inbox GROUP BY ai_state,chat_type;
```
Não exponha `body`, `sender_id`, chave Groq ou números em capturas de tela.

## Importante
A efetividade do gatilho de menção depende do formato que a versão instalada do WPPConnect envia. Se a sessão fornecer apenas texto sem os metadados de menção, mensagens em grupos serão ignoradas por segurança, até corrigirmos a integração. A integração não disponibiliza confirmação de entrega ponta a ponta pelo provedor; `delivery_uncertain` exige inspeção. Estabelecer políticas de retenção/LGPD antes do uso amplo.
